#!/usr/bin/env python3
# scripts/pipeline.py -> ALiHS computation dispatcher
#
# This is the single entry-point script called by PHP via shell_exec().
# It handles everything from fetching sequences to generating the final PDF.
# PHP calls it with a --task flag and reads whatever JSON lands on stdout.
#
# Usage:
# python3 pipeline.py --task <TASK> --job_id <N> --results_dir <PATH> [OPTIONS]
#
# Available tasks:
# fetch -> retrieve protein sequences from NCBI Entrez
# conservation -> multiple sequence alignment + per-column conservation scores
# plotcon -> EMBOSS plotcon static PNG conservation plot
# motifs -> PROSITE motif scanning via EMBOSS patmatmotifs
# pepstats -> EMBOSS pepstats physicochemical properties
# garnier -> EMBOSS garnier secondary structure prediction
# pepwindow -> EMBOSS pepwindow Kyte-Doolittle hydrophobicity profile
# blast -> BLAST all-vs-all within dataset
# uniprot -> UniProt REST API functional annotation retrieval
# pdb -> PDB / AlphaFold structure cross-reference lookup
# report -> PDF summary report compilation
#
# Contract with PHP (kept simple on purpose):
# - all tasks write primary output files to --results_dir
# - all tasks print a single JSON object to stdout on success
# - all tasks write error details to stderr on failure
# - exit code 0 = success, non-zero = failure
# - PHP reads stdout, parses JSON, handles all DB inserts via PDO
# 
# Changes: removed pepstats (physicochemical), garnier (2ndary structures),
# pepwindow (hydrophobicity), and report generation.
# Tried to make them work but failed, no time to fix

import argparse
import json
import os
import subprocess
import sys
import time
import re
import math
from pathlib import Path


# Stdout / stderr helpers

def emit(data: dict) -> None:
    # Print JSON to stdout and flush. Called once at the end of each task.
    # I made sure to flush immediately -> PHP needs that JSON right away.
    print(json.dumps(data, ensure_ascii=False), flush=True)


def die(message: str, code: int = 1) -> None:
    # Print error to stderr and exit with a non-zero code.
    # I tried using sys.exit(code) directly in the functions, but this
    # wrapper makes sure the error format is consistent everywhere.
    print(f"[pipeline.py ERROR] {message}", file=sys.stderr, flush=True)
    sys.exit(code)


# Argument parsing
# I spent ages getting this right. The PHP side passes in a ton of arguments,
# and keeping them all straight was a nightmare. I'm pretty sure I ended up
# with more options here than in the original design doc.

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="PhyloSeq pipeline dispatcher",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )

    # Required for all tasks
    p.add_argument("--task", required=True,
                   choices=["fetch","conservation","plotcon","motifs",
                            "blast","uniprot","pdb"],
                   help="Pipeline task to execute")
    p.add_argument("--job_id", required=True, type=int,
                   help="Database job ID")
    p.add_argument("--results_dir", required=True,
                   help="Absolute path to per-job results directory")

    # Fetch-specific
    # This block got huge. I probably should have split it into a separate config
    # but it's too late for that now.
    p.add_argument("--protein_family", default="",
                   help="Protein family / keyword for NCBI query")
    p.add_argument("--taxon", default="",
                   help="Taxonomic group for NCBI query")
    p.add_argument("--max_seqs", type=int, default=50,
                   help="Maximum number of sequences to retrieve")
    p.add_argument("--min_length", type=int, default=10,
                   help="Minimum sequence length filter (aa)")
    p.add_argument("--max_length", type=int, default=10000,
                   help="Maximum sequence length filter (aa)")
    p.add_argument("--ncbi_query", default="",
                   help="Full pre-built Entrez query string (overrides auto-build)")
    p.add_argument("--api_key", default="",
                   help="NCBI API key (from config.php)")
    p.add_argument("--email", default="",
                   help="Email address for NCBI Entrez (from config.php)")
    p.add_argument("--blast_makeblastdb", default="",
                   help="Path to makeblastdb executable (from config.php)")
    p.add_argument("--blast_blastp", default="",
                   help="Path to blastp executable (from config.php)")
    p.add_argument("--blast_temp_dir", default="",
                   help="Temporary directory for BLAST operations")

    # Conservation-specific
    p.add_argument("--aln_format", default="clustal",
                   choices=["clustal","fasta","phylip"],
                   help="ClustalOmega output alignment format")
    p.add_argument("--window_size", type=int, default=4,
                   help="Sliding window size for plotcon / conservation scoring")

    # Motifs-specific
    p.add_argument("--include_weak", type=int, default=0,
                   help="Include weak / low-scoring PROSITE matches (1=yes)")

    return p.parse_args()


# Running an external command
# This function was born out of sheer frustration with subprocess.
# I wanted to just use run_cmd everywhere and not have to worry about
# capturing output and checking return codes every single time.

def run_cmd(cmd: list[str], cwd: str | None = None) -> tuple[str, str]:
    # Run an external command and return (stdout, stderr).
    # Raises RuntimeError on non-zero exit code.
    result = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        cwd=cwd,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"Command {' '.join(cmd)} failed (exit {result.returncode}):\n"
            f"{result.stderr.strip()}"
        )
    return result.stdout, result.stderr


# Reading a FASTA file
# This is a basic parser I wrote after realising that Biopython is overkill
# for just reading a simple FASTA file.

def read_fasta(path: str) -> list[dict]:
    # Parse a FASTA file and return a list of dicts: { accession, description, sequence }.
    records = []
    current_id = None
    current_desc = ""
    current_seq = []

    with open(path, "r", encoding="utf-8") as fh:
        for line in fh:
            line = line.rstrip()
            if line.startswith(">"):
                if current_id is not None:
                    records.append({
                        "accession": current_id,
                        "description": current_desc,
                        "sequence": "".join(current_seq),
                    })
                header = line[1:]
                parts = header.split(None, 1)
                current_id = parts[0] if parts else "unknown"
                current_desc = parts[1] if len(parts) > 1 else ""
                current_seq = []
            else:
                current_seq.append(line.strip())

    if current_id is not None:
        records.append({
            "accession": current_id,
            "description": current_desc,
            "sequence": "".join(current_seq),
        })

    return records


# Splitting a FASTA file into one file per sequence
# This is needed for some downstream tools that expect one sequence per file.
# I found this approach to be the least buggy, even though it's a bit brute-force.

def split_fasta(fasta_path: str, out_dir: str) -> list[dict]:
    # Write one <accession>.fasta file per record to out_dir.
    # Returns list of { accession, fasta_path }.
    records = read_fasta(fasta_path)
    seq_files = []
    for rec in records:
        safe_acc = re.sub(r"[^A-Za-z0-9._-]", "_", rec["accession"])
        out_path = os.path.join(out_dir, f"{safe_acc}.fasta")
        with open(out_path, "w", encoding="utf-8") as fh:
            fh.write(f">{rec['accession']} {rec['description']}\n")
            # Wrap sequence at 60 chars -> it's a common standard for FASTA.
            seq = rec["sequence"]
            for i in range(0, len(seq), 60):
                fh.write(seq[i:i+60] + "\n")
        seq_files.append({"accession": rec["accession"], "fasta_path": out_path})
    return seq_files


# Fetching sequences from NCBI Entrez
# This was the first task I wrote, and it shows. It's a bit of a monster,
# but it gets the job done. I had to add a lot of error handling because
# NCBI's API can be flaky, especially with large queries.

def task_fetch(args: argparse.Namespace) -> None:
    # Query NCBI Entrez (protein database) and write sequences.fasta.
    # Prints a JSON array of sequence records to stdout for PHP to insert.
    #
    # Requires: biopython
    # Output files:
    # {results_dir}/sequences.fasta
    # Stdout JSON:
    # { sequences: [ { accession, organism, taxon_id, description,
    # sequence, length, order_name } ] }
    try:
        from Bio import Entrez, SeqIO
    except ImportError:
        die("Biopython is not installed. Run: pip3 install biopython --break-system-packages")

    Entrez.email = args.email
    Entrez.api_key = args.api_key if args.api_key else None
    Entrez.tool = "ALiHS"

    results_dir = args.results_dir
    os.makedirs(results_dir, exist_ok=True)

    # Build the query if one wasn't passed in directly
    query = args.ncbi_query
    if not query:
        parts = []
        if args.protein_family:
            parts.append(f'"{args.protein_family}"[Protein Name]')
        if args.taxon:
            parts.append(f'"{args.taxon}"[Organism]')
        if args.min_length > 10 or args.max_length < 10000:
            parts.append(f"{args.min_length}:{args.max_length}[Sequence Length]")
        query = " AND ".join(parts) if parts else args.protein_family

    if not query:
        die("No query could be constructed. Provide --protein_family or --ncbi_query.")

    # esearch -> get GI / accession list
    try:
        search_handle = Entrez.esearch(
            db="protein",
            term=query,
            retmax=args.max_seqs,
            usehistory="y",
        )
        search_results = Entrez.read(search_handle)
        search_handle.close()
    except Exception as e:
        die(f"Entrez esearch failed: {e}")

    id_list = search_results.get("IdList", [])
    if not id_list:
        # Return an empty result rather than failing -> let PHP handle the zero-result case.
        # This way the user gets a nice message instead of a crash.
        emit({"sequences": [], "query": query, "count": 0})
        return

    # efetch -> retrieve FASTA sequences -> batch in groups of 200 to respect NCBI limits
    # I learned this the hard way. Sending too many IDs at once gets you blocked.
    all_records = []
    batch_size = 200
    for start in range(0, len(id_list), batch_size):
        batch = id_list[start:start + batch_size]
        try:
            fetch_handle = Entrez.efetch(
                db="protein",
                id=batch,
                rettype="gb", # GenBank format gives us taxonomy
                retmode="text",
            )
            batch_records = list(SeqIO.parse(fetch_handle, "genbank"))
            fetch_handle.close()
            all_records.extend(batch_records)
        except Exception as e:
            print(f"[pipeline.py WARNING] efetch batch failed: {e}",
                  file=sys.stderr)
        # Respect NCBI rate limit -> 10 req/s with API key, 3 without.
        # I'm being conservative here to avoid any chance of being blocked.
        time.sleep(0.12 if args.api_key else 0.35)

    if not all_records:
        emit({"sequences": [], "query": query, "count": 0})
        return

    # Apply length filters
    filtered = [
        r for r in all_records
        if args.min_length <= len(r.seq) <= args.max_length
    ]

    # Write FASTA
    fasta_path = os.path.join(results_dir, "sequences.fasta")
    sequences_out = []

    with open(fasta_path, "w", encoding="utf-8") as fh:
        for rec in filtered:
            # Extract organism and taxonomy data from GenBank annotations
            # I spent way too long figuring out how to get the taxonomy from the
            # GenBank record. The structure is weirdly nested.
            organism = rec.annotations.get("organism", "")
            taxonomy = rec.annotations.get("taxonomy", [])
            taxon_id = ""
            order_name = ""

            # Extract NCBI taxon ID from db_xrefs
            # This is the part that took me three tries to get right.
            for feat in rec.features:
                if feat.type == "source":
                    db_xref = feat.qualifiers.get("db_xref", [])
                    for xref in db_xref:
                        if xref.startswith("taxon:"):
                            taxon_id = xref.replace("taxon:", "")
                    break

            # Attempt to extract order from lineage.
            # Typical lineage looks like: [..., "Aves", "Passeriformes", ...]
            # I'm checking for "-formes" and "-iformes" because that's the standard
            # suffix for taxonomic orders.
            order_keywords = [
                t for t in taxonomy
                if t.endswith("formes") or t.endswith("iformes")
            ]
            if order_keywords:
                order_name = order_keywords[0]

            # Write FASTA record
            seq_str = str(rec.seq)
            acc = rec.id
            desc = rec.description.replace(acc, "").strip()
            fh.write(f">{acc} {desc}\n")
            for i in range(0, len(seq_str), 60):
                fh.write(seq_str[i:i+60] + "\n")

            sequences_out.append({
                "accession": acc,
                "organism": organism,
                "taxon_id": taxon_id,
                "description": desc,
                "sequence": seq_str,
                "length": len(seq_str),
                "order_name": order_name,
            })

    emit({
        "sequences": sequences_out,
        "query": query,
        "count": len(sequences_out),
        "fasta_path": fasta_path,
    })


# Conservation -> ClustalOmega alignment + per-column scores
# This is the core analysis. I had to wrestle with ClustalOmega's output format,
# and then I spent an entire afternoon getting the entropy calculation right.
# I'm still not 100% sure it's correct, but it looks plausible.

def task_conservation(args: argparse.Namespace) -> None:
    # Run ClustalOmega on sequences.fasta, parse the alignment with BioPython,
    # compute per-column Shannon entropy conservation scores, and generate a
    # seaborn pairwise identity heatmap PNG.
    #
    # Requires: clustalo, biopython, matplotlib, seaborn, numpy
    # Output files:
    # {results_dir}/alignment.aln (clustal format)
    # {results_dir}/alignment.fasta (FASTA format)
    # {results_dir}/identity_heatmap.png
    # {results_dir}/conservation_scores.tsv
    # Stdout JSON:
    # { num_sequences, alignment_length, avg_identity,
    # scores: [ { position, conservation_score, gap_fraction } ],
    # heatmap_png }
    results_dir = args.results_dir
    fasta_in = os.path.join(results_dir, "sequences.fasta")

    if not os.path.exists(fasta_in):
        die(f"sequences.fasta not found in {results_dir}")

    aln_out_clustal = os.path.join(results_dir, "alignment.aln")
    aln_out_fasta = os.path.join(results_dir, "alignment.fasta")

    # Run ClustalOmega -> also write FASTA-format alignment for downstream tools.
    # I'm generating both formats because some tools prefer one over the other.
    try:
        run_cmd([
            "clustalo",
            "-i", fasta_in,
            "-o", aln_out_clustal,
            "--outfmt=clustal",
            "--force",
            "--threads=2",
        ])
        run_cmd([
            "clustalo",
            "-i", fasta_in,
            "-o", aln_out_fasta,
            "--outfmt=fasta",
            "--force",
            "--threads=2",
        ])
    except RuntimeError as e:
        die(f"ClustalOmega failed: {e}")

    # Parse alignment
    try:
        from Bio import AlignIO
        import numpy as np
    except ImportError:
        die("Biopython and/or numpy not installed.")

    alignment = AlignIO.read(aln_out_clustal, "clustal")
    num_seqs = len(alignment)
    aln_len = alignment.get_alignment_length()

    if num_seqs < 2:
        die("Alignment contains fewer than 2 sequences.")

    # Per-column conservation using Shannon entropy
    # I looked up the formula in a textbook and I think I implemented it correctly.
    # The normalisation part is a bit hand-wavy, but it produces scores between 0 and 1.
    AA_ALPHABET = set("ACDEFGHIKLMNPQRSTVWY")
    scores = []

    for col in range(aln_len):
        column = [alignment[i, col].upper() for i in range(num_seqs)]

        # Gap fraction
        gap_count = column.count("-") + column.count("X")
        gap_frac = gap_count / num_seqs

        # Count residue frequencies, excluding gaps
        residues = [c for c in column if c in AA_ALPHABET]
        if not residues:
            scores.append({
                "position": col + 1,
                "conservation_score": 0.0,
                "gap_fraction": round(gap_frac, 4),
            })
            continue

        counts = {}
        for r in residues:
            counts[r] = counts.get(r, 0) + 1

        total = len(residues)

        # Shannon entropy H = -sum(p * log2(p))
        entropy = 0.0
        for c in counts.values():
            p = c / total
            entropy -= p * math.log2(p)

        # Normalise -> max entropy for 20 AAs = log2(20) ~= 4.322
        # This gives a score where 1 = perfectly conserved, 0 = completely random.
        max_entropy = math.log2(min(total, 20)) if total > 1 else 1.0
        if max_entropy == 0:
            conservation = 1.0
        else:
            conservation = max(0.0, 1.0 - (entropy / max_entropy))

        scores.append({
            "position": col + 1,
            "conservation_score": round(conservation, 4),
            "gap_fraction": round(gap_frac, 4),
        })

    # Write TSV
    tsv_path = os.path.join(results_dir, "conservation_scores.tsv")
    with open(tsv_path, "w", encoding="utf-8") as fh:
        fh.write("position\tconservation_score\tgap_fraction\n")
        for s in scores:
            fh.write(f"{s['position']}\t{s['conservation_score']}\t{s['gap_fraction']}\n")

    # Pairwise identity matrix -> compute all-vs-all % identity from the alignment
    identities = np.zeros((num_seqs, num_seqs))
    labels = [alignment[i].id for i in range(num_seqs)]

    for i in range(num_seqs):
        for j in range(num_seqs):
            if i == j:
                identities[i][j] = 100.0
                continue
            seq_i = str(alignment[i].seq).upper()
            seq_j = str(alignment[j].seq).upper()
            matches = sum(
                1 for a, b in zip(seq_i, seq_j)
                if a == b and a != "-"
            )
            non_gap = sum(
                1 for a, b in zip(seq_i, seq_j)
                if a != "-" and b != "-"
            )
            identities[i][j] = (matches / non_gap * 100.0) if non_gap else 0.0

    avg_identity = float(np.mean(
        [identities[i][j]
         for i in range(num_seqs)
         for j in range(i+1, num_seqs)]
    ))

    # Seaborn heatmap PNG -> tried doing this in pure matplotlib first -> seaborn is just nicer.
    # I fought with matplotlib for hours to get the heatmap to look decent.
    # Then I remembered seaborn exists and it was so much easier.
    heatmap_path = None
    try:
        import matplotlib
        matplotlib.use("Agg") # non-interactive backend
        import matplotlib.pyplot as plt
        import seaborn as sns

        # Shorten labels for readability
        # Some accession strings are absurdly long -> they'd ruin the plot.
        short_labels = []
        for lbl in labels:
            parts = lbl.split("|")
            short_labels.append(parts[-1][:25] if parts else lbl[:25])

        fig_size = max(8, num_seqs * 0.35)
        fig, ax = plt.subplots(figsize=(fig_size, fig_size * 0.85))

        sns.heatmap(
            identities,
            ax=ax,
            vmin=0,
            vmax=100,
            cmap="YlOrRd_r", # dark = high identity
            xticklabels=short_labels,
            yticklabels=short_labels,
            linewidths=0.3,
            linecolor="#e0e0e0",
            cbar_kws={"label": "% Identity", "shrink": 0.7},
            annot=(num_seqs <= 20), # show values only for small matrices
            fmt=".0f",
            annot_kws={"size": 7},
        )
        ax.set_title(
            f"Pairwise sequence identity (n={num_seqs})",
            fontsize=11, pad=12
        )
        plt.xticks(rotation=45, ha="right", fontsize=7)
        plt.yticks(rotation=0, fontsize=7)
        plt.tight_layout()

        heatmap_path = os.path.join(results_dir, "identity_heatmap.png")
        plt.savefig(heatmap_path, dpi=150, bbox_inches="tight")
        plt.close(fig)

    except Exception as e:
        print(f"[pipeline.py WARNING] Heatmap generation failed: {e}",
              file=sys.stderr)
        heatmap_path = None

    emit({
        "num_sequences": num_seqs,
        "alignment_length": aln_len,
        "avg_identity": round(avg_identity, 2),
        "scores": scores,
        "heatmap_png": heatmap_path,
    })


# Plotcon -> EMBOSS plotcon static PNG
# This one is straightforward. EMBOSS does all the heavy lifting.
# The only tricky part was remembering that EMBOSS appends .png itself.
# I wasted half an hour to that mistake.

def task_plotcon(args: argparse.Namespace) -> None:
    # Run EMBOSS plotcon on the ClustalOmega alignment to produce a
    # static conservation PNG. Pretty straightforward, though I did have
    # to figure out that EMBOSS appends .png itself -> don't pass the extension.
    #
    # Output files:
    # {results_dir}/plotcon.png
    # Stdout JSON:
    # { plotcon_png }
    results_dir = args.results_dir
    aln_file = os.path.join(results_dir, "alignment.aln")

    if not os.path.exists(aln_file):
        die(f"alignment.aln not found in {results_dir}. Run conservation task first.")

    png_out = os.path.join(results_dir, "plotcon") # EMBOSS appends .png
    png_path = png_out + ".png"

    try:
        run_cmd([
            "plotcon",
            "-sequence", aln_file,
            "-winsize", str(args.window_size),
            "-graph", "png",
            "-goutfile", png_out,
        ])
    except RuntimeError as e:
        die(f"plotcon failed: {e}")

    if not os.path.exists(png_path):
        die(f"plotcon ran but did not produce {png_path}")

    emit({"plotcon_png": png_path})


# Motifs -> PROSITE scanning via patmatmotifs
# This was supposed to be easy, but patmatmotifs' output format is a mess.
# I wrote a custom parser to handle the TSV, and it seems to work most of the time.
# I'm not handling the "weak" hits because the include_weak flag was giving me trouble.

def task_motifs(args: argparse.Namespace) -> None:
    # Run PROSITE motif scanning directly on the multi-sequence FASTA
    results_dir = args.results_dir
    fasta_path = os.path.join(results_dir, "sequences.fasta")
    
    if not os.path.exists(fasta_path):
        die(f"sequences.fasta not found in {results_dir}")
    
    # Run patmatmotifs directly on the multi-sequence FASTA
    output_path = os.path.join(results_dir, "motif_hits.tsv")
    
    cmd = [
        "patmatmotifs",
        "-sequence", fasta_path,
        "-outfile", output_path,
        "-rformat", "excel"
    ]
    
    try:
        run_cmd(cmd)
    except RuntimeError as e:
        die(f"patmatmotifs failed: {e}")
    
    # Parse the TSV file
    all_hits = []
    
    try:
        with open(output_path, "r", encoding="utf-8", errors="replace") as fh:
            lines = fh.readlines()
        
        # Skip header
        first_line = True
        
        for line in lines:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            
            parts = line.split('\t')
            
            # Skip header line
            if first_line and parts[0] == 'SeqName':
                first_line = False
                continue
            
            if len(parts) >= 6:
                seq_name = parts[0].strip()
                
                # Parse score safely
                score_str = parts[3].strip()
                if score_str == 'Score' or score_str == '-':
                    score = None
                else:
                    try:
                        score = float(score_str)
                    except ValueError:
                        score = None
                
                start_pos = int(parts[1]) if parts[1].isdigit() else 0
                end_pos = int(parts[2]) if parts[2].isdigit() else 0
                motif_name = parts[5].strip()
                
                if seq_name and motif_name and start_pos > 0 and end_pos > 0:
                    all_hits.append({
                        "accession": seq_name,
                        "motif_id": motif_name,
                        "motif_name": motif_name,
                        "start_pos": start_pos,
                        "end_pos": end_pos,
                        "score": score,
                    })
                    
    except Exception as e:
        die(f"Failed to parse TSV: {e}")
    
    # Also create a readable text version
    text_path = os.path.join(results_dir, "motif_results.txt")
    with open(text_path, "w", encoding="utf-8") as fh:
        fh.write(f"Total motifs found: {len(all_hits)}\n")
        fh.write("=" * 60 + "\n\n")
        for hit in all_hits:
            fh.write(f"Accession: {hit['accession']}\n")
            fh.write(f"Motif: {hit['motif_name']}\n")
            fh.write(f"Position: {hit['start_pos']}-{hit['end_pos']}\n")
            if hit['score']:
                fh.write(f"Score: {hit['score']}\n")
            fh.write("-" * 40 + "\n")
    
    emit({
        "hits": all_hits,
        "total_hits": len(all_hits),
        "distinct_motifs": len(set(h["motif_id"] for h in all_hits)),
        "motif_plot_svg": None,
        "combined_tsv": output_path,
    })

def _parse_patmatmotifs_tsv(filepath: str, accession: str) -> list[dict]:
    # Parse patmatmotifs TSV output file and extract motif hits
    # The TSV format (from -rformat excel) has columns:
    # SeqName Start   End     Score   Strand  Motif
    #
    # I'm not proud of this parser. It's brittle and depends on the exact output format.
    # But it's what I've got.
    hits = []
    
    try:
        with open(filepath, "r", encoding="utf-8", errors="replace") as fh:
            # Read all lines
            lines = fh.readlines()
        
        print(f"[DEBUG] Parsing TSV file: {filepath}", file=sys.stderr)
        
        # Flag to skip the header
        first_data_line = True
        
        for line in lines:
            line = line.strip()
            
            # Skip empty lines and comments
            if not line or line.startswith('#'):
                continue
            
            # Parse TSV data
            parts = line.split('\t')
            
            # Skip the header line
            if first_data_line and parts[0] == 'SeqName':
                first_data_line = False
                continue
            
            print(f"[DEBUG] TSV line parts: {parts}", file=sys.stderr)
            
            if len(parts) >= 6:
                # Format: SeqName, Start, End, Score, Strand, Motif
                seq_name = parts[0].strip()
                
                # Handle the Score field -> it might be 'Score' or a number
                score_str = parts[3].strip()
                if score_str == 'Score' or score_str == '-':
                    score = None
                else:
                    try:
                        score = float(score_str)
                    except ValueError:
                        score = None
                
                start_pos = int(parts[1]) if parts[1].isdigit() else 0
                end_pos = int(parts[2]) if parts[2].isdigit() else 0
                strand = parts[4].strip()
                motif_name = parts[5].strip()
                
                # Only add if we have valid data
                if seq_name and motif_name and start_pos > 0 and end_pos > 0:
                    hits.append({
                        "accession": seq_name,
                        "motif_id": motif_name,
                        "motif_name": motif_name,
                        "start_pos": start_pos,
                        "end_pos": end_pos,
                        "score": score,
                    })
                    print(f"[DEBUG] Added hit: {motif_name} at {start_pos}-{end_pos}", file=sys.stderr)
                
    except OSError as e:
        print(f"[pipeline.py WARNING] Could not read {filepath}: {e}", file=sys.stderr)
    except Exception as e:
        print(f"[pipeline.py WARNING] Error parsing {filepath}: {e}", file=sys.stderr)
    
    return hits

def _generate_motif_svg(
    hits: list[dict],
    seq_lengths: dict[str, int],
    results_dir: str,
) -> str | None:
    # Generate an SVG motif domain map using matplotlib.
    # One horizontal bar per sequence, coloured blocks for each hit.
    #
    # This is my attempt at making the motif data more visual.
    # It's a bit clunky, but it gets the point across.
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
        import matplotlib.patches as mpatches

    except ImportError:
        print("[pipeline.py WARNING] matplotlib not available; skipping SVG map.",
              file=sys.stderr)
        return None

    if not hits:
        return None

    # Build colour map per motif_id
    PALETTE = [
        "#1a6b72","#e8a020","#2e7d4f","#9a3a60","#3a5aa0",
        "#c05a20","#5a3a9a","#2a7a5a","#a05a1a","#1a5a8a",
    ]
    motif_ids = list(dict.fromkeys(h["motif_id"] for h in hits))
    motif_colour = {mid: PALETTE[i % len(PALETTE)] for i, mid in enumerate(motif_ids)}

    # Sequence ordering -> unique accessions in hit order
    accessions = list(dict.fromkeys(h["accession"] for h in hits))
    n_seqs = len(accessions)
    max_len = max((seq_lengths.get(acc, 500) for acc in accessions), default=500)

    row_height = 0.6
    fig_height = max(3.0, n_seqs * (row_height + 0.3) + 1.5)
    fig, ax = plt.subplots(figsize=(12, fig_height))

    for row_idx, acc in enumerate(accessions):
        y = row_idx
        seq_len = seq_lengths.get(acc, max_len)
        bar_width = seq_len / max_len # normalised to [0,1]

        # Background bar (full sequence length)
        ax.barh(
            y, bar_width, height=row_height * 0.7,
            left=0, color="#e8ecee", edgecolor="#c8d0d4",
            linewidth=0.5, zorder=2,
        )

        # Motif hit blocks
        seq_hits = [h for h in hits if h["accession"] == acc]
        for hit in seq_hits:
            start_norm = (hit["start_pos"] - 1) / max_len
            width_norm = max(
                0.002,
                (hit["end_pos"] - hit["start_pos"] + 1) / max_len
            )
            colour = motif_colour.get(hit["motif_id"], "#888")
            ax.barh(
                y, width_norm, height=row_height * 0.65,
                left=start_norm, color=colour,
                edgecolor="white", linewidth=0.3, zorder=3,
            )

        # Sequence label (short)
        short_acc = acc.split("|")[-1][:22]
        ax.text(
            -0.01, y, short_acc,
            ha="right", va="center",
            fontsize=6.5, color="#444",
        )

    # Legend, capped at 10 entries
    legend_patches = [
        mpatches.Patch(color=motif_colour[mid], label=mid)
        for mid in motif_ids[:10]
    ]
    ax.legend(
        handles=legend_patches,
        loc="upper right",
        fontsize=7,
        framealpha=0.8,
        title="Motif ID",
        title_fontsize=7,
    )

    ax.set_xlim(-0.25, 1.05)
    ax.set_ylim(-0.8, n_seqs - 0.2)
    ax.set_xlabel("Normalised sequence position", fontsize=8)
    ax.set_yticks([])
    ax.spines[["top","right","left"]].set_visible(False)
    ax.tick_params(axis="x", labelsize=7)
    ax.set_title(
        f"PROSITE motif domain map (n={n_seqs} sequences)",
        fontsize=9, pad=8
    )

    plt.tight_layout()
    svg_path = os.path.join(results_dir, "motif_plot.svg")
    plt.savefig(svg_path, format="svg", bbox_inches="tight")
    plt.close(fig)

    return svg_path

# BLAST -> all-vs-all blastp within the dataset
# This was a pain to set up. I had to find the BLAST executables,
# build the database, and then run blastp. I'm using a temporary directory
# to avoid cluttering the results folder with BLAST's internal files.

def task_blast(args: argparse.Namespace) -> None:
    # Run makeblastdb then blastp all-vs-all on the job's sequences.
    results_dir = args.results_dir
    fasta_path = os.path.join(results_dir, "sequences.fasta")
    
    # Use temporary directory if provided, otherwise use results_dir/blast_db
    if args.blast_temp_dir:
        blast_dir = args.blast_temp_dir
        blast_out = os.path.join(blast_dir, "blast_results.txt")  # Write to temp dir first
    else:
        blast_dir = os.path.join(results_dir, "blast_db")
        blast_out = os.path.join(results_dir, "blast_results.txt")
    
    # Create directory if it doesn't exist
    if not os.path.exists(blast_dir):
        os.makedirs(blast_dir, exist_ok=True)
    
    # Try to set permissions, but continue if we can't
    try:
        os.chmod(blast_dir, 0o777)
    except PermissionError:
        # Directory might already have correct permissions
        pass

    if not os.path.exists(fasta_path):
        die(f"sequences.fasta not found in {results_dir}")

    db_path = os.path.join(blast_dir, "seqs")
    
    # Get BLAST paths from arguments
    makeblastdb_cmd = args.blast_makeblastdb
    blastp_cmd = args.blast_blastp
    
    # If not provided, try to find them
    if not makeblastdb_cmd or not os.path.exists(makeblastdb_cmd):
        possible_paths = [
            '/localdisk/home/ubuntu-software/blast217/ncbi-blast-2.17.0+-src/c++/ReleaseMT/bin/makeblastdb',
            '/usr/local/bin/makeblastdb',
            '/usr/bin/makeblastdb',
            'makeblastdb',
        ]
        for path in possible_paths:
            if os.path.exists(path):
                makeblastdb_cmd = path
                break
    
    if not blastp_cmd or not os.path.exists(blastp_cmd):
        possible_paths = [
            '/localdisk/home/ubuntu-software/blast217/ncbi-blast-2.17.0+-src/c++/ReleaseMT/bin/blastp',
            '/usr/local/bin/blastp',
            '/usr/bin/blastp',
            'blastp',
        ]
        for path in possible_paths:
            if os.path.exists(path):
                blastp_cmd = path
                break
    
    # Verify we found the executables
    if not makeblastdb_cmd or not os.path.exists(makeblastdb_cmd):
        die(f"makeblastdb not found. Please provide path via --blast_makeblastdb")
    
    if not blastp_cmd or not os.path.exists(blastp_cmd):
        die(f"blastp not found. Please provide path via --blast_blastp")
    
    print(f"[pipeline.py] Using makeblastdb: {makeblastdb_cmd}", file=sys.stderr)
    print(f"[pipeline.py] Using blastp: {blastp_cmd}", file=sys.stderr)
    print(f"[pipeline.py] Using BLAST directory: {blast_dir}", file=sys.stderr)
    print(f"[pipeline.py] Using BLAST output: {blast_out}", file=sys.stderr)
    
    try:
        stdout, stderr = run_cmd([
            makeblastdb_cmd,
            "-in", fasta_path,
            "-dbtype", "prot",
            "-out", db_path,
        ])
        print(f"[pipeline.py] makeblastdb stdout: {stdout[:200]}", file=sys.stderr)
    except RuntimeError as e:
        die(f"makeblastdb failed: {e}")
    
    try:
        stdout, stderr = run_cmd([
            blastp_cmd,
            "-query", fasta_path,
            "-db", db_path,
            "-out", blast_out,
            "-outfmt", "6 qseqid sseqid pident length evalue bitscore stitle",
            "-evalue", "1e-5",
            "-max_target_seqs", "10",
            "-num_threads", "2",
        ])
        print(f"[pipeline.py] blastp stdout: {stdout[:200]}", file=sys.stderr)
    except RuntimeError as e:
        die(f"blastp failed: {e}")
    
    # If we used a temporary directory, copy the results to the final location
    final_blast_out = os.path.join(results_dir, "blast_results.txt")
    if args.blast_temp_dir and os.path.exists(blast_out):
        try:
            import shutil
            shutil.copy2(blast_out, final_blast_out)
            print(f"[pipeline.py] Copied results to: {final_blast_out}", file=sys.stderr)
        except Exception as e:
            print(f"[pipeline.py] WARNING: Could not copy results: {e}", file=sys.stderr)

    # Parse results (from the temporary file or final file)
    hits = []
    output_file_to_parse = blast_out if os.path.exists(blast_out) else final_blast_out
    
    if os.path.exists(output_file_to_parse):
        try:
            with open(output_file_to_parse, "r", encoding="utf-8") as fh:
                for line in fh:
                    line = line.strip()
                    if not line or line.startswith("#"):
                        continue
                    parts = line.split("\t")
                    if len(parts) < 7:
                        continue
                    query_acc = parts[0]
                    hit_acc = parts[1]
                    if query_acc == hit_acc:
                        continue
                    stitle = parts[6] if len(parts) > 6 else ""
                    org_match = re.search(r'\[([^\]]+)\]', stitle)
                    organism = org_match.group(1) if org_match else ""

                    hits.append({
                        "query_accession": query_acc,
                        "hit_accession": hit_acc,
                        "hit_description": stitle,
                        "hit_organism": organism,
                        "pct_identity": float(parts[2]) if parts[2] else 0.0,
                        "evalue": float(parts[4]) if parts[4] else 1.0,
                        "bitscore": float(parts[5]) if parts[5] else 0.0,
                    })
            print(f"[pipeline.py] Parsed {len(hits)} BLAST hits", file=sys.stderr)
        except OSError as e:
            die(f"Could not read BLAST output: {e}")
    else:
        print(f"[pipeline.py] BLAST output file not found: {output_file_to_parse}", file=sys.stderr)

    emit({"hits": hits, "blast_output": final_blast_out})

# UniProt -> functional annotation via REST API
# I decided to keep this simple and not use any external libraries.
# It's just a basic HTTP request to the UniProt REST API.
# I'm only processing the first 10 sequences to avoid hitting rate limits.

def task_uniprot(args: argparse.Namespace) -> None:
    try:
        import urllib.request
        import urllib.parse
        import json
    except ImportError:
        die("urllib not available (standard library issue).")

    print(f"[pipeline.py] Using email: {args.email} for UniProt requests", file=sys.stderr)

    results_dir = args.results_dir
    fasta_path = os.path.join(results_dir, "sequences.fasta")

    if not os.path.exists(fasta_path):
        die(f"sequences.fasta not found in {results_dir}")

    records = read_fasta(fasta_path)
    accessions = [r["accession"] for r in records]

    if not accessions:
        emit({"annotations": []})
        return

    annotations = []
    
    # Process each accession individually (simpler, no async API needed)
    for acc in accessions[:10]:
        search_url = f"https://rest.uniprot.org/uniprotkb/search?query={urllib.parse.quote(acc)}&format=json&size=1"
        
        try:
            req = urllib.request.Request(search_url, headers={"Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                
                results = data.get("results", [])
                if not results:
                    continue
                    
                entry = results[0]
                uniprot_id = entry.get("primaryAccession", "")
                
                # Extract function
                function_txt = ""
                for comment in entry.get("comments", []):
                    if comment.get("commentType") == "FUNCTION":
                        texts = comment.get("texts", [])
                        if texts:
                            function_txt = texts[0].get("value", "")
                            break
                
                summary = function_txt or "No function annotation available."
                if len(summary) > 500:
                    summary = summary[:497] + "..."
                
                annotations.append({
                    "accession": acc,
                    "seq_id": None,
                    "uniprot_id": uniprot_id,
                    "url": f"https://www.uniprot.org/uniprotkb/{uniprot_id}",
                    "summary": summary,
                    "go_terms": [],
                })
                
        except Exception as e:
            print(f"[pipeline.py WARNING] UniProt fetch for {acc} failed: {e}", file=sys.stderr)
            continue
            
        time.sleep(0.2)  # Rate limiting

    emit({"annotations": annotations})

# PDB / AlphaFold structure cross-reference
# This is similar to the UniProt task, but I'm looking for PDB and AlphaFold IDs.
# I'm using the same UniProt REST API to get the cross-references.

def task_pdb(args: argparse.Namespace) -> None:
    # For each UniProt accession found in the job, query RCSB PDB and
    # AlphaFold DB for available structures. Since this script has no DB access,
    # it re-queries UniProt for PDB cross-references using the same approach
    # as task_uniprot -> a bit redundant but keeps things self-contained.
    #
    # Requires: urllib (standard library)
    # Stdout JSON:
    # { structures: [ { accession, seq_id (null), database,
    # structure_id, url, summary } ] }
    try:
        import urllib.request
        import urllib.parse
    except ImportError:
        die("urllib not available.")

    print(f"[pipeline.py] Using email: {args.email} for PDB requests", file=sys.stderr)

    results_dir = args.results_dir
    fasta_path = os.path.join(results_dir, "sequences.fasta")

    if not os.path.exists(fasta_path):
        die(f"sequences.fasta not found in {results_dir}")

    records = read_fasta(fasta_path)
    accessions = [r["accession"] for r in records]
    structures = []

    for acc in accessions:
        # Query UniProt for this accession to get PDB and AlphaFold links
        search_url = (
            "https://rest.uniprot.org/uniprotkb/search?"
            f"query={urllib.parse.quote(acc)}&format=json&size=1"
        )
        try:
            with urllib.request.urlopen(search_url, timeout=15) as resp:
                data = json.loads(resp.read().decode("utf-8"))
        except Exception as e:
            print(f"[pipeline.py WARNING] UniProt PDB search failed for {acc}: {e}",
                  file=sys.stderr)
            continue

        results = data.get("results", [])
        if not results:
            continue

        entry = results[0]
        uniprot_id = entry.get("primaryAccession", "")
        xrefs = entry.get("uniProtKBCrossReferences", [])

        for xref in xrefs:
            db = xref.get("database", "")

            if db == "PDB":
                pdb_id = xref.get("id", "")
                props = {p["key"]: p["value"] for p in xref.get("properties", [])}
                method = props.get("Method", "")
                res = props.get("Resolution", "")
                summary_txt = f"{method}" + (f", {res}" if res else "")
                structures.append({
                    "accession": acc,
                    "seq_id": None,
                    "database": "PDB",
                    "structure_id": pdb_id,
                    "url": f"https://www.rcsb.org/structure/{pdb_id}",
                    "summary": summary_txt,
                })

            elif db == "AlphaFoldDB":
                af_id = xref.get("id", "")
                structures.append({
                    "accession": acc,
                    "seq_id": None,
                    "database": "AlphaFold",
                    "structure_id": af_id,
                    "url": f"https://alphafold.ebi.ac.uk/entry/{af_id}",
                    "summary": f"AlphaFold model for {uniprot_id}",
                })

        time.sleep(0.15)

    emit({"structures": structures})

# Main dispatcher
# This is where the magic happens. I'm using a simple dictionary to map
# task names to functions. It's easy to extend if I need to add more tasks.

TASK_MAP = {
    "fetch": task_fetch,
    "conservation": task_conservation,
    "plotcon": task_plotcon,
    "motifs": task_motifs,
    "blast": task_blast,
    "uniprot": task_uniprot,
    "pdb": task_pdb,
}


def main() -> None:
    args = parse_args()

    # Make sure the results directory exists before anything tries to write to it
    os.makedirs(args.results_dir, exist_ok=True)

    task_fn = TASK_MAP.get(args.task)
    if task_fn is None:
        die(f"Unknown task: {args.task}")

    task_fn(args)


if __name__ == "__main__":
    main()
