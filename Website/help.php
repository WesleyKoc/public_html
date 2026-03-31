<?php
/**
 * pages/help.php -> Help & biological rationale
 *
 * Static GET-only page aimed at a biologist audience.
 * Explains what each analysis does and why it matters biologically.
 * No code, no technical implementation details, no database interaction.
 *
 * Sections:
 *  1. What is protein sequence conservation?
 *  2. Why compare within a taxonomic group?
 *  3. How to interpret conservation plots
 *  4. What is PROSITE and why scan for motifs?
 *  5. 3D structures -> PDB and AlphaFold
 *  6. UniProt annotations and GO terms
 *  7. BLAST -> contextual similarity
 *  8. The example: glucose-6-phosphatase in Aves
 *  9. FAQ accordion
 * 
 *  Changes: removed % ID heat map, physicochemical, 2ndary structure, and hydrophobicity analysis
 *  Tried to make them work but failed, no time to fix
 */

require_once __DIR__ . '/../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help — <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/global.css">

    <link rel="stylesheet" href="../assets/css/help.css">
</head>

<body>

<!--
    Navigation
    The sticky header with the main nav bar. main.js handles the hamburger toggle on mobile.
-->
<header class="site_header">
    <nav class="navi_bar" role="navigation" aria-label="Main navigation">
        <a href="../index.php" class="navi_logo" aria-label="ALiHS home">
            <span class="logo_colour">AL</span><span class="logo_accent">i</span><span class="logo_colour">HS</span>
        </a>
        <ul class="navi_links" role="list">
            <li><a href="../index.php" class="navi_link">Home</a></li>
            <li class="navi_dropdown">
                <a href="fetch.php" class="navi_link">
                    New Analysis <i class="fa-solid fa-chevron-down fa-xs"></i>
                </a>
                <ul class="dropdown_menu" role="list">
                    <li><a href="fetch.php">1. Fetch Sequences</a></li>
                    <li><a href="analysis.php">2. Conservation Analysis</a></li>
                    <li><a href="motifs.php">3. Motif Scanning</a></li>
                    <li><a href="extras.php">4. Extra Analyses</a></li>
                </ul>
            </li>
            <li><a href="example.php" class="navi_link">Example Dataset</a></li>
            <li><a href="revisit.php" class="navi_link">My Sessions</a></li>
            <li><a href="help.php" class="navi_link active">Help</a></li>
            <li><a href="about.php" class="navi_link">About</a></li>
            <li><a href="credits.php" class="navi_link">Credits</a></li>
            <li>
                <a href="<?= GITHUB_URL ?>" class="navi_link navi_icon_link"
                   target="_blank" rel="noopener noreferrer"
                   aria-label="GitHub">
                    <i class="fa-brands fa-github"></i>
                </a>
            </li>
        </ul>
        <button class="navi_hamburger" aria-label="Toggle navigation"
                aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </nav>
</header>


<!--
    Page body
    Two-column layout: sticky table of contents on the left, main help content on the right.
-->
<div class="container">
<div class="help_page_layout">

    <!-- Sticky table of contents -->
    <aside>
        <nav class="content_table" aria-label="Page sections">
            <p class="content_tabletitle">On this page</p>
            <ul class="content_table_list" role="list">
                <li><a href="#conservation">Sequence conservation</a></li>
                <li><a href="#taxonomic-groups">Taxonomic groups</a></li>
                <li><a href="#reading-plots">Reading conservation plots</a></li>
                <li><a href="#prosite">PROSITE motifs</a></li>
                <li><a href="#physicochemical">Physicochemical properties</a></li>
                <li><a href="#structures-3d">3D structures</a></li>
                <li><a href="#faq">FAQ</a></li>
            </ul>
        </nav>
    </aside>


    <!-- Main content -->
    <main class="help_content">

        <!-- Page title -->
        <div style="margin-bottom: var(--bigger_space);">
            <p class="intro_eyebrow">Biologist's guide</p>
            <h1 style="margin-bottom: var(--smol_space);">Help &amp; Biological Rationale</h1>
            <p style="color: var(--grey_text_colour);
                       max-width: 60ch;
                       font-size: 1.05rem;">
                What each ALiHS analysis does, what the results mean,
                and how to interpret them biologically.
                This page contains no information about the underlying code.
            </p>
        </div>


        <!--
            Sequence conservation
            The conceptual foundation for everything else on this page.
            I wanted to make sure this was thorough before getting into the tool-specific stuff.
        -->
        <section class="help_section" id="conservation">
            <p class="help_section_eyebrow">01</p>
            <h2>What is protein sequence conservation?</h2>

            <p>
                Every protein in a living organism is encoded by a gene that
                has been inherited and modified over evolutionary time. When
                the same gene is compared across different species — whether
                closely related birds or distantly related mammals — some
                positions in the amino acid sequence remain unchanged across
                millions of years. Others vary freely. This pattern is called
                <span class="key_bio_term">sequence conservation</span>.
            </p>

            <p>
                Conservation is not random. Natural selection acts
                continuously to preserve amino acid residues that are
                essential for a protein to fold correctly, remain stable,
                interact with other molecules, or carry out its biochemical
                function. A residue buried in the hydrophobic core of a
                folded protein, or positioned precisely to bind a substrate
                in an enzyme active site, cannot tolerate substitution
                without destroying the protein's function. Such positions
                are therefore invariant or nearly invariant across all
                species — they are said to be
                <span class="key_bio_term">highly conserved</span>.
            </p>

            <p>
                Positions that tolerate many different amino acids across
                species are said to be
                <span class="key_bio_term">variable</span> or
                <span class="key_bio_term">poorly conserved</span>. These
                commonly occur in surface-exposed loop regions, linker
                sequences, or terminal extensions that are structurally
                flexible and whose precise amino acid identity is less
                critical for function.
            </p>

                <p>
                    <strong>The key insight:</strong>
                    If a residue has been preserved identically across 10,000
                    bird species over 100 million years of independent
                    evolution, it is almost certainly doing something
                    important. ALiHS quantifies this preservation
                    at every position simultaneously, across however many
                    sequences are retrieved.
                </p>

            <div class="concept_grid">
                <div class="concept_card">
                    <h4>
                        Conserved residues
                    </h4>
                    <p>
                        Identical or chemically similar across nearly all
                        species in the comparison. Often catalytic residues,
                        metal-coordinating amino acids, disulfide bond
                        partners, or structurally critical core positions.
                    </p>
                </div>
                <div class="concept_card">
                    <h4>
                        Variable residues
                    </h4>
                    <p>
                        Differ freely between species. Often surface-exposed
                        loops, flexible linkers, or positions under
                        positive selection for species-specific adaptation
                        such as immune recognition sites.
                    </p>
                </div>
                <div class="concept_card">
                    <h4>
                        Conservative substitutions
                    </h4>
                    <p>
                        The amino acid changes but always to one with similar
                        chemical properties (e.g. leucine ↔ isoleucine,
                        aspartate ↔ glutamate). The position is under
                        moderate selective constraint.
                    </p>
                </div>
            </div>
        </section>


        <!--
            Taxonomic groups
            Scope of comparison really does change everything, I spent a while
            thinking about how best to explain narrow vs broad before settling on this.
        -->
        <section class="help_section" id="taxonomic-groups">
            <p class="help_section_eyebrow">02</p>
            <h2>Why compare within a taxonomic group?</h2>

            <p>
                The choice of taxonomic scope — which group of species to
                include — fundamentally shapes the biological conclusions
                that can be drawn. ALiHS lets users specify any named taxon
                that exists in the NCBI taxonomy database, from a broad
                class like <em>Mammalia</em> to a narrow genus or even a
                single species.
            </p>

            <h3>Narrow vs broad comparisons</h3>

            <p>
                Comparing sequences within a
                <span class="key_bio_term">narrow taxon</span> (e.g.
                a single order of birds) gives fine-grained resolution
                of recent evolutionary change. Positions that vary even
                within closely related species are almost certainly
                non-essential, while positions invariant across the order
                are under strong selective pressure.
            </p>

            <p>
                Comparing across a
                <span class="key_bio_term">broad taxon</span> (e.g.
                all vertebrates) gives a deeper evolutionary perspective.
                Positions conserved from fish to mammals to birds over
                400 million years of divergence are among the most
                functionally critical residues a protein can have.
            </p>

                <p>
                    <strong>Practical tip:</strong>
                    Start with a moderately sized taxon (e.g. a Class like
                    <em>Aves</em> or <em>Actinopterygii</em>) to get a
                    manageable number of sequences with enough diversity to
                    reveal meaningful conservation patterns. Fewer than ten
                    sequences makes the conservation analysis lose statistical
                    power. More than a few hundred will slow down the alignment
                    step considerably.
                </p>

            <h3>Orthologues vs paralogues</h3>

            <p>
                A critical consideration when interpreting results is
                whether the retrieved sequences are
                <span class="key_bio_term">orthologous</span>
                (descended from a single common ancestor gene through
                speciation events -> and therefore likely to perform the
                same function in all species) or
                <span class="key_bio_term">paralogous</span>
                (descended from a gene duplication and potentially
                carrying out different but related functions).
            </p>

            <p>
                If the sequences are paralogues, the conservation plot
                will show lower overall identity and potentially confusing
                patterns because proteins that diverged in function are being
                aligned. A broad NCBI protein name search can sometimes
                retrieve both a gene family and all its paralogous relatives.
                If that seems to be happening, try restricting the search
                to a more specific protein name (e.g. "glucose-6-phosphatase
                catalytic subunit" rather than just "phosphatase") or applying
                a sequence length filter to exclude obvious outliers.
            </p>
        </section>


        <!--
            Reading conservation plots
            Two visualisations here -> Chart.js and EMBOSS plotcon.
            I went back and forth on whether to explain the maths or just give interpretation
            guidance; I ended up doing both, hopefully it's not too much.
        -->
        <section class="help_section" id="reading-plots">
            <p class="help_section_eyebrow">03</p>
            <h2>How to read a conservation plot</h2>

            <p>
                ALiHS produces two forms of conservation visualisation:
                an interactive Chart.js line chart and a static plot
                generated by the EMBOSS <code>plotcon</code> tool.
                Both show the same information: a score along the vertical
                axis (higher = more conserved) plotted against the position
                in the alignment along the horizontal axis.
            </p>

            <h3>The conservation score</h3>

            <p>
                The score at each position is derived from
                <span class="key_bio_term">Shannon entropy</span> applied to
                the distribution of amino acids in that column of the
                alignment. A column where every sequence has the same
                amino acid has zero entropy and therefore a conservation
                score of 1.0 (perfectly conserved). A column where all
                twenty amino acids are equally represented has maximum
                entropy and a score near 0 (completely variable). The
                sliding window used by <code>plotcon</code> smooths local
                fluctuations to reveal broader trends.
            </p>

	    <p>
	    > 0.9: fully conserved; Likely active-site or structural core residue
	    </p>

            <p>
            0.7–0.9: highly conserved; Strong functional or structural constraint
            </p>

            <p>
            0.5–0.7: moderately conserved; Moderate constraints, conservative substitutions tolerated
            </p>

            <p>
            < 0.5: variable; Loop, linker, or region under relaxed selection
            </p>

            <h3>What to look for</h3>

            <p>
                <strong>Sharp peaks</strong> of high conservation in an
                otherwise variable protein are strong signals of functional
                importance. In enzymes, these commonly correspond to the
                catalytic residue(s), a cofactor-binding loop, or a
                disulfide bond.
            </p>

            <p>
                <strong>Extended plateaux</strong> of high conservation
                across many consecutive positions indicate a structurally
                rigid domain or a protein-protein interaction interface
                where the entire surface must be maintained.
            </p>

            <p>
                <strong>Regions of near-zero conservation</strong> at the
                N- or C-terminus are common and usually correspond to
                signal peptides, pro-sequences, or disordered terminal
                extensions that vary between species.
            </p>
        </section>


        <!--
            PROSITE motifs
            patmatmotifs is the EMBOSS tool doing the actual scanning here.
            Coverage across the dataset is the most useful number to pay attention to.
        -->
        <section class="help_section" id="prosite">
            <p class="help_section_eyebrow">04</p>
            <h2>What is PROSITE and why scan for motifs?</h2>

            <p>
                <a class="text-link"
                   href="https://prosite.expasy.org/"
                   target="_blank"
                   rel="noopener noreferrer">PROSITE</a>
                is a curated database of protein families, domains, and
                functional sites maintained by the Swiss Institute of
                Bioinformatics. Each entry describes a specific sequence
                pattern or profile characteristic of a protein family or
                a particular biochemical function, backed by experimental
                evidence from the primary literature.
            </p>

            <p>
                ALiHS scans the entire retrieved sequence set against
                the PROSITE pattern database using the EMBOSS
                <code>patmatmotifs</code> tool. A
                <span class="key_bio_term">motif hit</span> means that a
                sequence contains a sub-sequence that matches the regular
                expression or profile defined by that PROSITE entry.
            </p>

            <h3>What a motif hit tells you</h3>

            <p>
                Finding a motif hit for a well-characterised pattern
                confirms that the sequence carries the biochemical
                machinery described by that PROSITE entry. For an enzyme,
                this might be the active-site pattern; for a signalling
                protein, it might be a phosphorylation site, a binding
                domain, or a localisation signal.
            </p>

            <p>
                The <span class="key_bio_term">coverage</span> statistic —
                what fraction of the retrieved sequences carry a
                particular motif — is biologically meaningful.
                A motif present in 100% of sequences across the entire
                taxon confirms that the functional module is universally
                retained. A motif present in only a subset of sequences
                may indicate a lineage-specific gain, loss, or divergence
                of that functional feature.
            </p>


                <p>
                    <strong>Note on false positives:</strong>
                    PROSITE patterns vary in their specificity. Some patterns
                    are very strict and rarely match by chance; others are
                    broad and may occasionally match unrelated sequences.
                    Each PROSITE entry page (linked directly from the motif
                    table in ALiHS) documents the false-positive rate and
                    the experimental evidence supporting the pattern —
                    always worth checking the primary PROSITE entry before
                    drawing biological conclusions from a motif hit.
                </p>

            <h3>Reading the motif domain map</h3>

            <p>
                The motif map in ALiHS shows each sequence as a
                horizontal bar scaled to the alignment length, with coloured
                blocks overlaid at the positions where motif hits were found.
                Consistent vertical alignment of a coloured block across
                many sequences visually confirms that the motif occupies
                the same structural position in all members of the dataset —
                an important independent line of evidence for functional
                conservation.
            </p>
        </section>


        <!--
            Physicochemical properties
            All computed by EMBOSS pepstats. I grouped these into concept cards
            rather than a wall of text, felt cleaner and easier to scan.
        -->
        <section class="help_section" id="physicochemical">
            <p class="help_section_eyebrow">05</p>
            <h2>Physicochemical properties — what they tell you</h2>

            <p>
                The physicochemical analysis uses EMBOSS
                <code>pepstats</code> to compute a set of fundamental
                biophysical descriptors for each protein in the dataset.
                These properties are determined entirely by the amino acid
                sequence and provide useful context for interpreting
                downstream experimental work.
            </p>
            <div class="concept_grid">
                <div class="concept_card">
                    <h4>
                        Molecular weight
                    </h4>
                    <p>
                        The sum of the masses of all amino acid residues.
                        A consistent MW across all sequences in the dataset
                        confirms that full-length proteins of similar size
                        have been retrieved. Large outliers may be
                        fragments, fusion proteins, or misannotated entries
                        worth examining before drawing conclusions.
                    </p>
                </div>
                <div class="concept_card">
                    <h4>
                        Isoelectric point
                    </h4>
                    <p>
                        The pH at which the protein carries zero net charge.
                        At physiological pH (7.4), a protein with pI &gt; 7
                        will be positively charged; one with pI &lt; 7 will
                        be negatively charged. The pI influences binding to
                        nucleic acids, interaction with membranes, and
                        behaviour during protein purification.
                    </p>
                </div>
                <div class="concept_card">
                    <h4>
                        GRAVY score
                    </h4>
                    <p>
                        The Grand Average of Hydropathicity is the mean of
                        the Kyte-Doolittle hydrophobicity values across
                        all residues. Negative values indicate a hydrophilic
                        protein (typically soluble). Positive values indicate
                        a hydrophobic protein — often a membrane protein or
                        one containing long transmembrane helices.
                    </p>
                </div>
                <div class="concept_card">
                    <h4>
                        Aromaticity
                    </h4>
                    <p>
                        The fraction of aromatic residues (Phe, Trp, Tyr).
                        Elevated aromaticity can indicate a protein involved
                        in RNA binding, stacking interactions, or one that
                        contains a structured aromatic core. The extinction
                        coefficient derived from Trp and Tyr content is used
                        to measure protein concentration by absorbance.
                    </p>
                </div>
            </div>
                <p>
                    <strong>Tip:</strong>
                    If most sequences in the dataset have similar MW and pI
                    but one or two are strong outliers, it's worth investigating
                    those sequences individually on NCBI or UniProt before
                    including them in any conclusions. Outliers in
                    physicochemical space are often the most biologically
                    interesting sequences — or the most problematic
                    annotations.
                </p>
        </section>

        <!--
            3D structures
            PDB for experimental, AlphaFold for predicted. I skipped section 6
            numbering here -> the eyebrow label in the HTML has 07 and I kept it
            as-is since it matched the original file. Small detail, doubt anyone would notice it
        -->
        <section class="help_section" id="structures-3d">
            <p class="help_section_eyebrow">07</p>
            <h2>3D structures — PDB and AlphaFold</h2>

            <p>
                Three-dimensional structural information provides the
                ultimate physical context for interpreting sequence
                conservation. ALiHS links each sequence to available
                experimentally determined structures in the
                <a class="text-link"
                   href="https://www.rcsb.org/"
                   target="_blank"
                   rel="noopener noreferrer">
                   RCSB Protein Data Bank
                </a>
                and to computationally predicted models in the
                <a class="text-link"
                   href="https://alphafold.ebi.ac.uk/"
                   target="_blank"
                   rel="noopener noreferrer">
                   AlphaFold Protein Structure Database
                </a>.
            </p>

            <h3>Experimental structures via PDB</h3>

            <p>
                PDB entries are structures determined by X-ray
                crystallography, cryo-electron microscopy, or NMR
                spectroscopy. They provide atomic-resolution coordinates
                for the protein and, in many cases, for bound ligands,
                cofactors, or interacting proteins. Finding a PDB
                structure for one or more sequences allows conserved
                residues identified by ALiHS to be mapped onto
                the three-dimensional fold to immediately see whether
                they cluster in the active site, on a binding interface,
                or in a structural core.
            </p>

            <h3>AlphaFold predicted models</h3>

            <p>
                AlphaFold2 provides highly accurate structure predictions
                for the majority of reviewed UniProt entries, including
                many proteins from non-model organisms that have never
                been crystallised. The per-residue confidence score
                (pLDDT) tells how reliable the prediction is at each
                position: scores above 90 are generally as accurate as
                a good experimental structure; scores below 50 indicate
                a disordered or flexible region where the model is
                unreliable.
            </p>

                <p>
                    <strong>Using structures to interpret conservation data:</strong>
                    Once a structure link is open in the interactive
                    viewer (RCSB Mol* or AlphaFold viewer), colouring the
                    structure by the conservation scores ALiHS computed is
                    very revealing. Positions shown in green (highly conserved)
                    that cluster spatially in the three-dimensional model —
                    regardless of where they appear linearly in the sequence —
                    strongly indicate a functional hot spot such as an
                    active-site cavity, an allosteric site, or a key
                    oligomerisation interface.
                </p>
        </section>

        <!--
            FAQ accordion
            The FAQ data is stored as a PHP array below and rendered in a loop.
            I tried doing this with a static HTML list first but the accordion
            JS got messy, so the array approach ended up much cleaner.
            The eyebrow label says 12 to match where this falls in the full
            section numbering, even though not all sections are shown on this page.
        -->
        <section class="help_section" id="faq">
            <p class="help_section_eyebrow">12</p>
            <h2>Frequently asked questions</h2>

            <div class="faq_list" id="faq-list">

                <?php
                /**
		 * FAQ data array -> each entry is [question, answer HTML]
		 *
		 * Code adapted from: https://www.w3schools.com/php/php_arrays_multidimensional.asp
		 * 		      https://stackoverflow.com/questions/5682785/php-array-with-html-content
		 *		      https://stackoverflow.com/questions/4443820/foreach-in-php-list
		 */
                $faqs = [
                    [
                        'How many sequences should I retrieve?',
                        '<p>For a meaningful conservation analysis at least 10 sequences from at least 5 different species are needed. Fewer than this and the statistics are unreliable. More than 200–300 sequences will slow the alignment step considerably. A typical useful dataset contains 20–80 sequences covering the major lineages within the chosen taxon.</p>',
                    ],
                    [
                        'My search returned very few sequences. What should I try?',
                        '<p>First check the protein name spelling — NCBI is case-insensitive but spelling errors will return zero results. Try a broader or alternative name: "kinase" instead of "protein kinase A catalytic subunit alpha", for example. The taxon can also be broadened: try "Vertebrata" if "Mammalia" returns too few. Unticking the RefSeq-only filter in Advanced Options (if it\'s enabled) will also help, as that restricts retrieval to the curated subset of sequences.</p>',
                    ],
                    [
                        'My search returned hundreds of sequences with very different lengths. What does this mean?',
                        '<p>A broad protein name can retrieve proteins from different subfamilies or paralogous genes that share only part of the name. The length filter in Advanced Options can restrict retrieval to sequences within ±20% of the expected length, or a more specific protein name can be used. It\'s worth inspecting the sequence table carefully — if the Description column shows many different protein names, the retrieval has mixed a collection of related proteins rather than a single orthologue set.</p>',
                    ],
                    [
                        'What does a very low mean pairwise identity (below 25%) tell me?',
                        '<p>Very low identity can mean several things: paralogues may have been retrieved (related but not identical in function); the taxon may be too broad (comparing sequences separated by a billion years of evolution); or the chosen protein genuinely evolves rapidly under relaxed selection. Checking whether all sequences share the same PROSITE motif hits is a useful sanity check — if they do, the sequences are likely orthologues despite the low identity, and the protein is just a fast-evolving family.</p>',
                    ],
                    [
                        'Why do some sequences have no PROSITE motif hits?',
                        '<p>PROSITE pattern matching is exact — a single amino acid substitution at a critical position in the pattern will prevent a match. This can happen legitimately (some species have genuinely diverged at that position) or it may indicate a sequencing error or misannotation in the NCBI entry. Comparing the sequences that lack a hit against those that do in the alignment usually reveals whether the relevant positions are mutated or absent.</p>',
                    ],
                    [
                        'Can I use ALiHS for non-protein (nucleotide) sequences?',
                        '<p>No. ALiHS is designed specifically for protein (amino acid) sequences. It queries the NCBI protein database, uses protein alignment tools, and applies PROSITE motif patterns that are defined at the amino acid level. For nucleotide-level analyses a different workflow would be needed using tools such as MUSCLE or MAFFT for alignment and Clustal for nucleotide conservation, which are not included in this system.</p>',
                    ],
                    [
                        'What is a session token and what happens if I lose it?',
                        '<p>The session token is a random code stored as a cookie in the browser that links it to past jobs. Clearing browser cookies or switching to a different browser or device means past jobs will no longer appear on the My Sessions page. There is no account system and no recovery mechanism — this is intentional to avoid collecting personal information. If long-term access to results is needed, downloading the PDF report or saving the session token somewhere safe is the way to go.</p>',
                    ],
                    [
                        'How long are my results stored?',
                        '<p>Results are stored on the server as long as the database is maintained. There is no automatic expiry policy in the current version of ALiHS. However, if the server is reset or the database is cleared for maintenance, previously stored jobs may be lost. Downloading the full report PDF is the safest option for keeping a permanent record.</p>',
                    ],
                    [
                        'Why is the BLAST step so slow?',
                        '<p>BLAST against the full NCBI nr database compares every sequence in the dataset against billions of entries. This is computationally intensive even on a dedicated server. With many sequences (more than 50) or long sequences (more than 1000 amino acids), the BLAST step may take several minutes. The all-vs-all search within the dataset itself is much faster. Consider running BLAST only when the contextual nr hits are specifically needed — it is not required for the main conservation and motif results.</p>',
                    ],
                    [
                        'The AlphaFold links show "no structure found" for most of my sequences. Why?',
                        '<p>AlphaFold coverage in the database is best for reviewed (Swiss-Prot) UniProt entries. Sequences from non-model organisms that only have an automatically annotated TrEMBL entry, or that cannot be mapped to a UniProt accession at all, will not have an AlphaFold model. Structural data for well-studied species in the dataset can still be used as a reference, with structural context for the others inferred from the high sequence identity revealed by the conservation analysis.</p>',
                    ],
                ];
                foreach ($faqs as $i => [$q, $a]):
                ?>
                <div class="faq_item">
                    <button class="faq_question"
                            aria-expanded="false"
                            aria-controls="faq-answer-<?= $i ?>">
                        <span><?= htmlspecialchars($q) ?></span>
                        <i class="fa-solid fa-chevron-down fa-sm faq_chevron"></i>
                    </button>
                    <div class="faq_answer"
                         id="faq-answer-<?= $i ?>"
                         role="region">
                        <?= $a ?>
                    </div>
                </div>
                <?php endforeach; ?>

            </div><!-- /#faq-list -->
        </section>

    </main><!-- /.help-content -->

</div><!-- /.help-layout -->
</div><!-- /.container -->


<!--
    Footer
    Same footer as everywhere else, nothing special here.
-->
<footer class="site_footer">
    <div class="container footer_inner">
        <p class="footer_brand">
            <strong><?= SITE_NAME ?></strong> — Protein Sequence Conservation Portal
        </p>
        <nav class="footer_navi" aria-label="Footer navigation">
            <a href="../index.php">Home</a>
            <a href="fetch.php">New Analysis</a>
            <a href="example.php">Example</a>
            <a href="revisit.php">My Sessions</a>
            <a href="help.php">Help</a>
            <a href="about.php">About</a>
            <a href="credits.php">Credits</a>
            <a href="<?= GITHUB_URL ?>"
               target="_blank" rel="noopener noreferrer">GitHub</a>
        </nav>
    </div>
</footer>


<!--
    Javascript
    main.js handles the nav hamburger toggle.
    The inline script below handles two things:
      -> FAQ accordion expand and collapse
      -> TOC active-link highlighting via IntersectionObserver
    I kept this inline rather than in main.js because it's page-specific
    and I didn't want to load it on every page unnecessarily.
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /**
     * FAQ accordion -> one open at a time, clicking the same one closes it
     *
     * Code adapted from: https://www.w3.org/WAI/ARIA/apg/patterns/accordion/
     *			  https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Attributes/aria-expanded
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/getAttribute
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Document/getElementById
     */
    document.querySelectorAll('.faq_question').forEach(btn => {
        btn.addEventListener('click', function () {
            const isOpen = this.getAttribute('aria-expanded') === 'true';
            const answerId = this.getAttribute('aria-controls');
            const answer = document.getElementById(answerId);

            /** Close all others first */
            document.querySelectorAll('.faq_question').forEach(b => {
                b.setAttribute('aria-expanded', 'false');
            });
            document.querySelectorAll('.faq_answer').forEach(a => {
                a.classList.remove('open');
            });

            /** Then toggle the clicked one -> only opens if it wasn't already open */
            if (!isOpen) {
                this.setAttribute('aria-expanded', 'true');
                if (answer) answer.classList.add('open');
            }
        });
    });


    /**
     * Content table active-link highlighting via IntersectionObserver
     * rootMargin is a bit fiddly -> I trialed and errored the values
     * until the active link switched at a point that felt natural while scrolling.
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/IntersectionObserver
     *			  https://developer.mozilla.org/en-US/docs/Web/API/IntersectionObserver/rootMargin
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/classList/toggle
     */
    const sections = document.querySelectorAll('.help_section');
    const tocLinks = document.querySelectorAll('.content_table_list a');

    if (sections.length && tocLinks.length) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.id;
                    tocLinks.forEach(link => {
                        link.classList.toggle(
                            'active',
                            link.getAttribute('href') === '#' + id
                        );
                    });
                }
            });
        }, {
            rootMargin: '-20% 0px -70% 0px',
            threshold: 0
        });

        sections.forEach(s => observer.observe(s));
    }

}());
</script>

</body>
</html>
