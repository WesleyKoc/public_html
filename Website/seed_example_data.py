#!/usr/bin/env python3
"""
scripts/seed_example_data.py — ALiHS Example Dataset Generator
===================================================================
One-time setup script that:

  1. Runs the full ALiHS pipeline against the example dataset
     (glucose-6-phosphatase proteins from Aves) by calling pipeline.py
     for each task in sequence.

  2. Captures the JSON output from each task and writes it to
     sql/seed_example.sql as valid INSERT statements, ready to be
     loaded into MySQL.

  3. Copies / verifies all generated output files into results/example/
     so the example page can serve them as static files.

Usage (run from the project root):
    python3 scripts/seed_example_data.py [OPTIONS]

Options:
    --project_dir   Absolute path to the ALiHS project root.
                    Defaults to the parent directory of this script.
    --api_key       NCBI API key (recommended; raises rate limit to 10/s).
    --email         Email address for NCBI Entrez (required by NCBI policy).
    --max_seqs      Maximum sequences to retrieve (default: 30).
    --dry_run       Run the pipeline but do not write the SQL file.
    --skip_fetch    Skip the NCBI fetch step and use an existing
                    sequences.fasta in results/example/ (useful for re-runs
                    when network access is slow or unavailable).
    --tasks         Comma-separated list of tasks to run.
                    Default: fetch,conservation,plotcon,motifs,pepstats,
                             garnier,pepwindow,uniprot,pdb,report
                    Omit tasks you want to skip, e.g. --tasks fetch,conservation

Prerequisites:
    - config.php must have correct DB credentials.
    - pipeline.py dependencies must be installed (biopython, matplotlib,
      seaborn, numpy, fpdf2).
    - EMBOSS, ClustalOmega, and BLAST+ must be available on PATH.

Output:
    results/example/        All pipeline output files (PNGs, FASTAs, etc.)
    sql/seed_example.sql    Regenerated SQL INSERT file (overwrites existing)
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import textwrap
from datetime import datetime
from pathlib import Path


# ---------------------------------------------------------------------------
# Configuration defaults — mirror config.php
# ---------------------------------------------------------------------------

EXAMPLE_PROTEIN = "glucose-6-phosphatase"
EXAMPLE_TAXON   = "Aves"
EXAMPLE_QUERY   = (
    '"glucose-6-phosphatase"[Protein Name] AND "Aves"[Organism]'
)
EXAMPLE_JOB_ID  = 1          # fixed job_id for the example dataset
EXAMPLE_LABEL   = "Glucose-6-Phosphatase in Aves"

DEFAULT_TASKS = [
    "fetch",
    "conservation",
    "plotcon",
    "motifs",
    "pepstats",
    "garnier",
    "pepwindow",
    "uniprot",
    "pdb",
    "report",
]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def log(msg: str) -> None:
    ts = datetime.now().strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", flush=True)


def log_ok(msg: str) -> None:
    print(f"  ✓  {msg}", flush=True)


def log_warn(msg: str) -> None:
    print(f"  ⚠  {msg}", flush=True)


def log_err(msg: str) -> None:
    print(f"  ✗  {msg}", file=sys.stderr, flush=True)


def run_task(
    python_bin: str,
    pipeline_script: str,
    task: str,
    job_id: int,
    results_dir: str,
    extra_args: list[str] | None = None,
) -> dict:
    """
    Invoke pipeline.py for a single task.
    Returns the parsed JSON dict from stdout.
    Raises RuntimeError on non-zero exit or invalid JSON.
    """
    cmd = [
        python_bin,
        pipeline_script,
        "--task",        task,
        "--job_id",      str(job_id),
        "--results_dir", results_dir,
    ]
    if extra_args:
        cmd.extend(extra_args)

    log(f"Running task: {task}  →  {' '.join(cmd[-4:])}")

    result = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
    )

    if result.returncode != 0:
        log_err(f"Task '{task}' failed (exit {result.returncode})")
        if result.stderr.strip():
            log_err(result.stderr.strip())
        raise RuntimeError(f"pipeline.py --task {task} exited with {result.returncode}")

    if result.stderr.strip():
        # Warnings from pipeline.py — print but don't abort
        for line in result.stderr.strip().splitlines():
            log_warn(line)

    stdout = result.stdout.strip()
    if not stdout:
        raise RuntimeError(f"Task '{task}' produced no output on stdout.")

    try:
        data = json.loads(stdout)
    except json.JSONDecodeError as e:
        raise RuntimeError(
            f"Task '{task}' stdout is not valid JSON: {e}\n"
            f"Output was: {stdout[:300]}"
        )

    return data


def esc_sql(value: str | None) -> str:
    """
    Minimally escape a string value for embedding in a SQL literal.
    Uses single quotes and escapes internal single quotes and backslashes.
    Returns 'NULL' for None.
    """
    if value is None:
        return "NULL"
    value = str(value)
    value = value.replace("\\", "\\\\")
    value = value.replace("'",  "\\'")
    return f"'{value}'"


def sql_val(value) -> str:
    """
    Convert a Python value to a SQL literal string.
    Handles: None → NULL, bool → 0/1, int/float → numeric, str → escaped.
    """
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, float)):
        return str(value)
    return esc_sql(str(value))


# ---------------------------------------------------------------------------
# SQL generation helpers
# ---------------------------------------------------------------------------

def make_header(project_dir: str) -> str:
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    return textwrap.dedent(f"""\
        -- =============================================================================
        -- sql/seed_example.sql — ALiHS Example Dataset (AUTO-GENERATED)
        -- =============================================================================
        -- Generated by:  scripts/seed_example_data.py
        -- Generated at:  {now}
        -- Project root:  {project_dir}
        --
        -- Protein:  {EXAMPLE_PROTEIN}
        -- Taxon:    {EXAMPLE_TAXON}
        -- Job ID:   {EXAMPLE_JOB_ID}  (fixed — always 1 for the example dataset)
        --
        -- Load this file:
        --   mysql -u alihs_user -p alihs < sql/seed_example.sql
        -- =============================================================================

        SET NAMES utf8mb4;
        SET character_set_client = utf8mb4;
        SET FOREIGN_KEY_CHECKS = 0;

        -- ---------------------------------------------------------------------------
        -- 0. Clean up any pre-existing example data
        -- ---------------------------------------------------------------------------

        DELETE FROM `external_links`
        WHERE `seq_id` IN (
            SELECT `seq_id` FROM `sequences` WHERE `job_id` = {EXAMPLE_JOB_ID}
        );
        DELETE FROM `extra_analyses`   WHERE `job_id` = {EXAMPLE_JOB_ID};
        DELETE FROM `blast_results`    WHERE `job_id` = {EXAMPLE_JOB_ID};
        DELETE FROM `motif_hits`       WHERE `job_id` = {EXAMPLE_JOB_ID};
        DELETE FROM `conservation_scores`
        WHERE `alignment_id` IN (
            SELECT `alignment_id` FROM `alignments` WHERE `job_id` = {EXAMPLE_JOB_ID}
        );
        DELETE FROM `alignments`  WHERE `job_id` = {EXAMPLE_JOB_ID};
        DELETE FROM `sequences`   WHERE `job_id` = {EXAMPLE_JOB_ID};
        DELETE FROM `jobs`        WHERE `job_id` = {EXAMPLE_JOB_ID};

    """)


def make_footer() -> str:
    return textwrap.dedent(f"""\

        SET FOREIGN_KEY_CHECKS = 1;

        -- ---------------------------------------------------------------------------
        -- Verification
        -- ---------------------------------------------------------------------------
        /*
        SELECT 'jobs'                AS tbl, COUNT(*) AS rows FROM jobs
            WHERE job_id = {EXAMPLE_JOB_ID}
        UNION ALL
        SELECT 'sequences',          COUNT(*) FROM sequences
            WHERE job_id = {EXAMPLE_JOB_ID}
        UNION ALL
        SELECT 'alignments',         COUNT(*) FROM alignments
            WHERE job_id = {EXAMPLE_JOB_ID}
        UNION ALL
        SELECT 'conservation_scores',COUNT(*) FROM conservation_scores
            WHERE alignment_id IN (
                SELECT alignment_id FROM alignments WHERE job_id = {EXAMPLE_JOB_ID})
        UNION ALL
        SELECT 'motif_hits',         COUNT(*) FROM motif_hits
            WHERE job_id = {EXAMPLE_JOB_ID}
        UNION ALL
        SELECT 'extra_analyses',     COUNT(*) FROM extra_analyses
            WHERE job_id = {EXAMPLE_JOB_ID}
        UNION ALL
        SELECT 'external_links',     COUNT(*) FROM external_links
            WHERE seq_id IN (
                SELECT seq_id FROM sequences WHERE job_id = {EXAMPLE_JOB_ID});
        */
    """)


# ---------------------------------------------------------------------------
# SQL section generators — one per pipeline task result
# ---------------------------------------------------------------------------

def sql_jobs(results_dir: str, num_sequences: int, now_str: str) -> str:
    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- 1. jobs",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `jobs` (",
        "    `job_id`, `session_token`, `label`,",
        "    `protein_family`, `taxonomic_group`, `ncbi_query_string`,",
        "    `num_sequences`, `status`, `is_example`,",
        "    `session_dir`, `created_at`, `completed_at`",
        ") VALUES (",
        f"    {EXAMPLE_JOB_ID},",
        f"    NULL,",
        f"    {esc_sql(EXAMPLE_LABEL)},",
        f"    {esc_sql(EXAMPLE_PROTEIN)},",
        f"    {esc_sql(EXAMPLE_TAXON)},",
        f"    {esc_sql(EXAMPLE_QUERY)},",
        f"    {num_sequences},",
        f"    'done',",
        f"    1,",
        f"    {esc_sql('/results/example')},",
        f"    {esc_sql(now_str)},",
        f"    {esc_sql(now_str)}",
        ");",
        "",
    ]
    return "\n".join(lines)


def sql_sequences(sequences: list[dict]) -> str:
    if not sequences:
        return "-- No sequences to insert.\n"

    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- 2. sequences",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `sequences`",
        "    (`job_id`, `accession`, `taxon_id`, `description`,",
        "     `organism`, `order_name`, `sequence`, `length`, `excluded`)",
        "VALUES",
    ]

    rows = []
    for seq in sequences:
        rows.append(
            "    ("
            f"{EXAMPLE_JOB_ID}, "
            f"{esc_sql(seq.get('accession',''))}, "
            f"{sql_val(seq.get('taxon_id') or None)}, "
            f"{esc_sql(seq.get('description',''))}, "
            f"{esc_sql(seq.get('organism',''))}, "
            f"{esc_sql(seq.get('order_name') or None)}, "
            f"{esc_sql(seq.get('sequence',''))}, "
            f"{int(seq.get('length', 0))}, "
            f"0"
            ")"
        )

    lines.append(",\n".join(rows) + ";")
    lines.append("")
    return "\n".join(lines)


def sql_alignments(aln_data: dict) -> str:
    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- 3. alignments",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `alignments`",
        "    (`job_id`, `tool_used`, `num_sequences`, `alignment_length`,",
        "     `avg_identity`, `output_file`, `clustal_file`)",
        "VALUES (",
        f"    {EXAMPLE_JOB_ID},",
        f"    'clustalo',",
        f"    {int(aln_data.get('num_sequences', 0))},",
        f"    {int(aln_data.get('alignment_length', 0))},",
        f"    {float(aln_data.get('avg_identity', 0.0))},",
        f"    {esc_sql('/results/example/alignment.aln')},",
        f"    {esc_sql('/results/example/alignment.clustal')}",
        ");",
        "",
        "-- Store alignment_id for use in conservation_scores",
        "SET @aln_id = LAST_INSERT_ID();",
        "",
    ]
    return "\n".join(lines)


def sql_conservation_scores(scores: list[dict]) -> str:
    if not scores:
        return "-- No conservation scores to insert.\n"

    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- 4. conservation_scores",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `conservation_scores`",
        "    (`alignment_id`, `position`, `conservation_score`, `gap_fraction`)",
        "VALUES",
    ]

    rows = []
    for s in scores:
        rows.append(
            "    ("
            f"@aln_id, "
            f"{int(s.get('position', 0))}, "
            f"{float(s.get('conservation_score', 0.0))}, "
            f"{float(s.get('gap_fraction', 0.0))}"
            ")"
        )

    lines.append(",\n".join(rows) + ";")
    lines.append("")
    return "\n".join(lines)


def sql_motif_hits(hits: list[dict], acc_to_seq_id: dict[str, int]) -> str:
    if not hits:
        return "-- No motif hits to insert.\n"

    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- 5. motif_hits",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `motif_hits`",
        "    (`job_id`, `seq_id`, `motif_id`, `motif_name`,",
        "     `start_pos`, `end_pos`, `score`)",
        "VALUES",
    ]

    rows = []
    for h in hits:
        acc    = h.get("accession", "")
        seq_id = acc_to_seq_id.get(acc)
        if seq_id is None:
            continue
        rows.append(
            "    ("
            f"{EXAMPLE_JOB_ID}, "
            f"{seq_id}, "
            f"{esc_sql(h.get('motif_id',''))}, "
            f"{esc_sql(h.get('motif_name',''))}, "
            f"{int(h.get('start_pos', 0))}, "
            f"{int(h.get('end_pos', 0))}, "
            f"{sql_val(h.get('score'))}"
            ")"
        )

    if not rows:
        return "-- No motif hits could be mapped to sequence IDs.\n"

    lines.append(",\n".join(rows) + ";")
    lines.append("")
    return "\n".join(lines)


def sql_extra_analyses(
    task_name: str,
    task_data: dict,
    acc_to_seq_id: dict[str, int],
    section_comment: str,
) -> str:
    """
    Generic generator for pepstats, garnier, pepwindow extra_analyses rows.
    task_data must have a 'sequences' list with 'accession' and 'props' keys.
    """
    seqs = task_data.get("sequences", [])
    if not seqs:
        return f"-- No {task_name} data to insert.\n"

    lines = [
        "-- ---------------------------------------------------------------------------",
        f"-- {section_comment}",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `extra_analyses`",
        "    (`job_id`, `seq_id`, `analysis_type`, `result_summary`, `output_file`)",
        "VALUES",
    ]

    rows = []
    for s in seqs:
        acc    = s.get("accession", "")
        seq_id = acc_to_seq_id.get(acc)
        if seq_id is None:
            continue
        props_json = json.dumps(s.get("props", {}), ensure_ascii=False)
        out_file   = s.get("output_file") or None
        rows.append(
            "    ("
            f"{EXAMPLE_JOB_ID}, "
            f"{seq_id}, "
            f"{esc_sql(task_name)}, "
            f"{esc_sql(props_json)}, "
            f"{sql_val(out_file)}"
            ")"
        )

    if not rows:
        return f"-- No {task_name} rows could be mapped to sequence IDs.\n"

    lines.append(",\n".join(rows) + ";")
    lines.append("")
    return "\n".join(lines)


def sql_uniprot_links(annots: list[dict], acc_to_seq_id: dict[str, int]) -> str:
    if not annots:
        return "-- No UniProt annotations to insert.\n"

    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- UniProt external_links",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `external_links`",
        "    (`seq_id`, `database_name`, `external_id`, `url`, `annotation_summary`)",
        "VALUES",
    ]

    rows = []
    for a in annots:
        acc    = a.get("accession", "")
        seq_id = acc_to_seq_id.get(acc)
        if seq_id is None:
            continue
        summary = (a.get("summary") or "")[:500]
        rows.append(
            "    ("
            f"{seq_id}, "
            f"'UniProt', "
            f"{esc_sql(a.get('uniprot_id',''))}, "
            f"{esc_sql(a.get('url',''))}, "
            f"{esc_sql(summary)}"
            ")"
        )

    if not rows:
        return "-- No UniProt rows could be mapped.\n"

    lines.append(",\n".join(rows) + ";")
    lines.append("")
    return "\n".join(lines)


def sql_structure_links(structures: list[dict], acc_to_seq_id: dict[str, int]) -> str:
    if not structures:
        return "-- No PDB/AlphaFold structures to insert.\n"

    lines = [
        "-- ---------------------------------------------------------------------------",
        "-- PDB / AlphaFold external_links",
        "-- ---------------------------------------------------------------------------",
        "",
        "INSERT INTO `external_links`",
        "    (`seq_id`, `database_name`, `external_id`, `url`, `annotation_summary`)",
        "VALUES",
    ]

    rows = []
    seen = set()
    for s in structures:
        acc    = s.get("accession", "")
        seq_id = acc_to_seq_id.get(acc)
        if seq_id is None:
            continue
        db          = s.get("database", "PDB")
        struct_id   = s.get("structure_id", "")
        key         = (seq_id, db, struct_id)
        if key in seen:
            continue
        seen.add(key)
        rows.append(
            "    ("
            f"{seq_id}, "
            f"{esc_sql(db)}, "
            f"{esc_sql(struct_id)}, "
            f"{esc_sql(s.get('url',''))}, "
            f"{esc_sql((s.get('summary') or '')[:500])}"
            ")"
        )

    if not rows:
        return "-- No structure rows could be mapped.\n"

    lines.append(",\n".join(rows) + ";")
    lines.append("")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Generate the ALiHS example dataset and seed SQL file.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument(
        "--project_dir",
        default=str(Path(__file__).resolve().parent.parent),
        help="Absolute path to the ALiHS project root directory.",
    )
    p.add_argument(
        "--api_key",
        default="",
        help="NCBI API key (recommended).",
    )
    p.add_argument(
        "--email",
        default="user@example.com",
        help="Email address for NCBI Entrez (required by NCBI policy).",
    )
    p.add_argument(
        "--max_seqs",
        type=int,
        default=30,
        help="Maximum number of sequences to retrieve from NCBI.",
    )
    p.add_argument(
        "--dry_run",
        action="store_true",
        help="Run pipeline but do not write the SQL file.",
    )
    p.add_argument(
        "--skip_fetch",
        action="store_true",
        help="Skip NCBI fetch and reuse an existing sequences.fasta.",
    )
    p.add_argument(
        "--tasks",
        default=",".join(DEFAULT_TASKS),
        help="Comma-separated list of pipeline tasks to run.",
    )
    p.add_argument(
        "--python",
        default=sys.executable,
        help="Python interpreter to use when calling pipeline.py.",
    )
    return p.parse_args()


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    args = parse_args()

    project_dir     = Path(args.project_dir).resolve()
    results_dir     = project_dir / "results" / "example"
    pipeline_script = project_dir / "scripts" / "pipeline.py"
    sql_output      = project_dir / "sql" / "seed_example.sql"

    tasks_to_run = [t.strip() for t in args.tasks.split(",") if t.strip()]

    # ── Validate project structure ────────────────────────────────────
    if not pipeline_script.exists():
        log_err(f"pipeline.py not found at {pipeline_script}")
        sys.exit(1)

    results_dir.mkdir(parents=True, exist_ok=True)
    log_ok(f"Results directory: {results_dir}")

    # ── Tracking state ────────────────────────────────────────────────
    sequences:      list[dict] = []
    acc_to_seq_id:  dict[str, int] = {}
    aln_data:       dict = {}
    scores:         list[dict] = []
    motif_hits:     list[dict] = []
    pepstats_data:  dict = {}
    garnier_data:   dict = {}
    pepwindow_data: dict = {}
    uniprot_data:   dict = {}
    pdb_data:       dict = {}
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # ── TASK: fetch ───────────────────────────────────────────────────
    fasta_path = results_dir / "sequences.fasta"

    if "fetch" in tasks_to_run and not args.skip_fetch:
        log("=" * 60)
        log("STEP 1/10  Fetching sequences from NCBI")
        log("=" * 60)

        fetch_args = [
            "--protein_family", EXAMPLE_PROTEIN,
            "--taxon",          EXAMPLE_TAXON,
            "--max_seqs",       str(args.max_seqs),
            "--ncbi_query",     EXAMPLE_QUERY,
            "--api_key",        args.api_key,
            "--email",          args.email,
        ]

        fetch_result = run_task(
            args.python,
            str(pipeline_script),
            "fetch",
            EXAMPLE_JOB_ID,
            str(results_dir),
            fetch_args,
        )

        sequences = fetch_result.get("sequences", [])
        log_ok(f"Retrieved {len(sequences)} sequences.")

        if not sequences:
            log_err("No sequences returned. Check NCBI query and API key.")
            sys.exit(1)

    elif args.skip_fetch or "fetch" not in tasks_to_run:
        # Re-use existing sequences.fasta
        if not fasta_path.exists():
            log_err(
                f"--skip_fetch set but {fasta_path} does not exist. "
                "Run without --skip_fetch first."
            )
            sys.exit(1)
        log_warn(f"Skipping fetch — reusing {fasta_path}")

        # Parse the existing FASTA to reconstruct the sequences list
        # (accession and length only — sufficient for SQL generation)
        sequences = []
        current_acc  = None
        current_seq  = []
        current_desc = ""
        with open(fasta_path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.rstrip()
                if line.startswith(">"):
                    if current_acc:
                        seq_str = "".join(current_seq)
                        sequences.append({
                            "accession":   current_acc,
                            "description": current_desc,
                            "sequence":    seq_str,
                            "length":      len(seq_str),
                            "organism":    "",
                            "taxon_id":    None,
                            "order_name":  None,
                        })
                    header       = line[1:]
                    parts        = header.split(None, 1)
                    current_acc  = parts[0] if parts else "unknown"
                    current_desc = parts[1] if len(parts) > 1 else ""
                    current_seq  = []
                else:
                    current_seq.append(line.strip())
        if current_acc:
            seq_str = "".join(current_seq)
            sequences.append({
                "accession":   current_acc,
                "description": current_desc,
                "sequence":    seq_str,
                "length":      len(seq_str),
                "organism":    "",
                "taxon_id":    None,
                "order_name":  None,
            })
        log_ok(f"Loaded {len(sequences)} sequences from existing FASTA.")

    # Build accession → sequential seq_id map
    # (seq_ids start at 1; MySQL AUTO_INCREMENT will assign real IDs,
    # but for SQL generation we use a stable local numbering so that
    # foreign-key references in motif_hits and extra_analyses are consistent)
    for i, seq in enumerate(sequences, start=1):
        acc_to_seq_id[seq["accession"]] = i

    # ── TASK: conservation ────────────────────────────────────────────
    if "conservation" in tasks_to_run:
        log("=" * 60)
        log("STEP 2/10  Running ClustalOmega alignment + conservation scoring")
        log("=" * 60)

        aln_result = run_task(
            args.python,
            str(pipeline_script),
            "conservation",
            EXAMPLE_JOB_ID,
            str(results_dir),
        )

        aln_data = aln_result
        scores   = aln_result.get("scores", [])
        log_ok(
            f"Alignment: {aln_data.get('num_sequences')} seqs, "
            f"{aln_data.get('alignment_length')} columns, "
            f"{aln_data.get('avg_identity'):.1f}% mean identity."
        )
        log_ok(f"Conservation scores: {len(scores)} positions.")

    # ── TASK: plotcon ─────────────────────────────────────────────────
    if "plotcon" in tasks_to_run:
        log("=" * 60)
        log("STEP 3/10  Running EMBOSS plotcon")
        log("=" * 60)

        plotcon_result = run_task(
            args.python,
            str(pipeline_script),
            "plotcon",
            EXAMPLE_JOB_ID,
            str(results_dir),
            ["--window_size", "4"],
        )
        png = plotcon_result.get("plotcon_png")
        log_ok(f"plotcon PNG: {png or 'not generated'}")

    # ── TASK: motifs ──────────────────────────────────────────────────
    if "motifs" in tasks_to_run:
        log("=" * 60)
        log("STEP 4/10  Running PROSITE motif scan (patmatmotifs)")
        log("=" * 60)

        motif_result = run_task(
            args.python,
            str(pipeline_script),
            "motifs",
            EXAMPLE_JOB_ID,
            str(results_dir),
        )
        motif_hits = motif_result.get("hits", [])
        log_ok(f"Motif hits: {len(motif_hits)} total.")

    # ── TASK: pepstats ────────────────────────────────────────────────
    if "pepstats" in tasks_to_run:
        log("=" * 60)
        log("STEP 5/10  Running EMBOSS pepstats")
        log("=" * 60)

        pepstats_data = run_task(
            args.python,
            str(pipeline_script),
            "pepstats",
            EXAMPLE_JOB_ID,
            str(results_dir),
        )
        log_ok(f"pepstats: {len(pepstats_data.get('sequences', []))} sequences.")

    # ── TASK: garnier ─────────────────────────────────────────────────
    if "garnier" in tasks_to_run:
        log("=" * 60)
        log("STEP 6/10  Running EMBOSS garnier")
        log("=" * 60)

        garnier_data = run_task(
            args.python,
            str(pipeline_script),
            "garnier",
            EXAMPLE_JOB_ID,
            str(results_dir),
        )
        log_ok(f"garnier: {len(garnier_data.get('sequences', []))} sequences.")

    # ── TASK: pepwindow ───────────────────────────────────────────────
    if "pepwindow" in tasks_to_run:
        log("=" * 60)
        log("STEP 7/10  Running EMBOSS pepwindow")
        log("=" * 60)

        pepwindow_data = run_task(
            args.python,
            str(pipeline_script),
            "pepwindow",
            EXAMPLE_JOB_ID,
            str(results_dir),
        )
        log_ok(f"pepwindow: {len(pepwindow_data.get('sequences', []))} sequences.")

    # ── TASK: uniprot ─────────────────────────────────────────────────
    if "uniprot" in tasks_to_run:
        log("=" * 60)
        log("STEP 8/10  Fetching UniProt annotations")
        log("=" * 60)

        uniprot_args = []
        if args.api_key:
            uniprot_args += ["--api_key", args.api_key]
        if args.email:
            uniprot_args += ["--email", args.email]

        uniprot_data = run_task(
            args.python,
            str(pipeline_script),
            "uniprot",
            EXAMPLE_JOB_ID,
            str(results_dir),
            uniprot_args or None,
        )
        log_ok(f"UniProt annotations: {len(uniprot_data.get('annotations', []))}.")

    # ── TASK: pdb ─────────────────────────────────────────────────────
    if "pdb" in tasks_to_run:
        log("=" * 60)
        log("STEP 9/10  Fetching PDB / AlphaFold structure links")
        log("=" * 60)

        pdb_args = []
        if args.api_key:
            pdb_args += ["--api_key", args.api_key]
        if args.email:
            pdb_args += ["--email", args.email]

        pdb_data = run_task(
            args.python,
            str(pipeline_script),
            "pdb",
            EXAMPLE_JOB_ID,
            str(results_dir),
            pdb_args or None,
        )
        log_ok(f"Structures found: {len(pdb_data.get('structures', []))}.")

    # ── TASK: report ──────────────────────────────────────────────────
    if "report" in tasks_to_run:
        log("=" * 60)
        log("STEP 10/10  Generating PDF report")
        log("=" * 60)

        report_result = run_task(
            args.python,
            str(pipeline_script),
            "report",
            EXAMPLE_JOB_ID,
            str(results_dir),
        )
        log_ok(f"Report: {report_result.get('report_pdf', 'not generated')}.")

    # ── Verify output files ───────────────────────────────────────────
    log("")
    log("Verifying generated files in results/example/:")
    expected_files = [
        "sequences.fasta",
        "alignment.aln",
        "plotcon.png",
        "identity_heatmap.png",
        "motif_plot.svg",
        "conservation_scores.tsv",
        "pepstats.txt",
        "pepwindow.png",
        "report.pdf",
    ]
    for fname in expected_files:
        fpath = results_dir / fname
        if fpath.exists():
            size = fpath.stat().st_size
            log_ok(f"{fname} ({size:,} bytes)")
        else:
            log_warn(f"{fname} — NOT FOUND (task may have been skipped or failed)")

    # ── Generate SQL file ─────────────────────────────────────────────
    if args.dry_run:
        log("")
        log("DRY RUN — SQL file not written.")
        log("Pipeline completed successfully.")
        return

    log("")
    log("=" * 60)
    log("Generating sql/seed_example.sql")
    log("=" * 60)

    sql_parts = [
        make_header(str(project_dir)),
        sql_jobs(str(results_dir), len(sequences), now_str),
    ]

    if sequences:
        sql_parts.append(sql_sequences(sequences))

    if aln_data:
        sql_parts.append(sql_alignments(aln_data))

    if scores:
        sql_parts.append(sql_conservation_scores(scores))

    if motif_hits:
        sql_parts.append(
            sql_motif_hits(motif_hits, acc_to_seq_id)
        )

    if pepstats_data.get("sequences"):
        sql_parts.append(
            sql_extra_analyses(
                "pepstats", pepstats_data, acc_to_seq_id,
                "extra_analyses — pepstats"
            )
        )

    if garnier_data.get("sequences"):
        sql_parts.append(
            sql_extra_analyses(
                "garnier", garnier_data, acc_to_seq_id,
                "extra_analyses — garnier"
            )
        )

    if pepwindow_data.get("sequences"):
        sql_parts.append(
            sql_extra_analyses(
                "pepwindow", pepwindow_data, acc_to_seq_id,
                "extra_analyses — pepwindow"
            )
        )

    if uniprot_data.get("annotations"):
        sql_parts.append(
            sql_uniprot_links(uniprot_data["annotations"], acc_to_seq_id)
        )

    if pdb_data.get("structures"):
        sql_parts.append(
            sql_structure_links(pdb_data["structures"], acc_to_seq_id)
        )

    sql_parts.append(make_footer())

    # Write the SQL file
    sql_content = "\n".join(sql_parts)
    with open(sql_output, "w", encoding="utf-8") as fh:
        fh.write(sql_content)

    size = sql_output.stat().st_size
    log_ok(f"Written: {sql_output}  ({size:,} bytes)")

    # ── Summary ───────────────────────────────────────────────────────
    log("")
    log("=" * 60)
    log("DONE. Summary:")
    log("=" * 60)
    log_ok(f"Sequences:           {len(sequences)}")
    log_ok(f"Alignment columns:   {aln_data.get('alignment_length', '—')}")
    log_ok(f"Mean identity:       {aln_data.get('avg_identity', '—')}")
    log_ok(f"Conservation scores: {len(scores)}")
    log_ok(f"Motif hits:          {len(motif_hits)}")
    log_ok(f"pepstats rows:       {len(pepstats_data.get('sequences', []))}")
    log_ok(f"garnier rows:        {len(garnier_data.get('sequences', []))}")
    log_ok(f"UniProt annotations: {len(uniprot_data.get('annotations', []))}")
    log_ok(f"Structure links:     {len(pdb_data.get('structures', []))}")
    log("")

if __name__ == "__main__":
    main()
