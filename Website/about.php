<?php
/**
 * Code adapted from: https://www.php.net/manual/en/function.require-once.php
 * Code adapted from: https://www.php.net/manual/en/language.constants.magic.php
 */
require_once __DIR__ . '/../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About ALiHS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/about.css">
</head>

<body>

<!-- Top navigation bar, sticky so it follows the page down -->
<header class="site_header">
    <nav class="navi_bar" role="navigation" aria-label="Main navigation">
        <a href="../index.php" class="navi_logo" aria-label="ALiHS home">
            <span class="logo_colour">AH</span><span class="logo_accent">i</span><span class="logo_colour">HS</span>
        </a>
        <ul class="navi_links" role="list">
            <li><a href="../index.php" class="navi_link">Home</a></li>
            <li class="navi_dropdown">
                <a href="fetch.php" class="navi_link">
                    New Analysis <i class="fa-solid fa-chevron-down fa-xs"></i>
                </a>
                <ul class="dropdown_menu" role="list">
                    <li><a href="fetch.php">Fetch Sequences</a></li>
                    <li><a href="analysis.php">Conservation Analysis</a></li>
                    <li><a href="motifs.php">Motif Scanning</a></li>
                    <li><a href="extras.php">Extra Analyses</a></li>
                </ul>
            </li>
            <li><a href="example.php" class="navi_link">Example Dataset</a></li>
            <li><a href="revisit.php" class="navi_link">My Sessions</a></li>
            <li><a href="help.php" class="navi_link">Help</a></li>
            <li><a href="about.php" class="navi_link active">About</a></li>
            <li><a href="credits.php" class="navi_link">Credits</a></li>
            <li>
                <a href="https://github.com/WesleyKoc/public_html/tree/main/Website" class="navi_link navi_icon-link"
                   target="_blank" rel="noopener noreferrer" aria-label="GitHub">
                    <i class="fa-brands fa-github"></i>
                </a>
            </li>
        </ul>
        <button class="navi_hamburger" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </nav>
</header>

<!--
 Page body: two-column layout -> sticky content table on the left, main content on the right
 The content table highlights the active section as the page scrolls, handled by the IntersectionObserver at the bottom
-->
<div class="container">
    <div class="about_layout">

        <!-- Sticky table of contents, anchors link to the section IDs below -->
        <aside class="about_toc">
            <nav class="content_table" aria-label="Page sections">
                <p class="content_table_title">On this page</p>
                <ul class="content_table_list" role="list">
                    <li><a href="#overview">Overview</a></li>
                    <li><a href="#architecture">System architecture</a></li>
                    <li><a href="#pipeline">Computation pipeline</a></li>
                    <li><a href="#database">Database design</a></li>
                    <li><a href="#integrations">External integrations</a></li>
                </ul>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="about_content">

            <!-- Page title and intro blurb -->
            <div style="margin-bottom: var(--bigger_space);">
                <p class="section_eyebrow">Developer documentation</p>
                <h1 style="margin-bottom: var(--smol_space);">About ALiHS</h1>
                <p style="color: var(--grey_text_colour); max-width: 60ch; font-size: 1.05rem;">
                    This is a technical overview of how ALiHS was built, structured,
                    and deployed. Hopefully this helps fellow web developers and 
                    bioinformaticians who want to understand or extend the system.
                </p>
            </div>

            <!--
             Overview section
             Kept this brief on purpose, more detail comes in the sections below
	     Can't be bothered to repeat myself
            -->
            <section class="about_section" id="overview">
                <p class="section_eyebrow">01</p>
                <h2>Overview</h2>
                <p>
                    ALiHS is a server-side web application that provides a guided
                    four-step pipeline for protein sequence retrieval, multiple sequence
                    alignment, motif scanning, and supplementary bioinformatics analyses.
                    It is designed to run on a Linux server with a standard LAMP stack
                    (Linux, Apache, MySQL, PHP) alongside a set of bioinformatics tools
                    installed at the system level.
                </p>
                <p>
                    The application is built without any PHP framework deliberately.
                    Each page is a self-contained PHP file that handles both HTML
                    rendering and its own AJAX endpoints, keeping the codebase flat
                    and easy to follow for maintainers with a bioinformatics background
                    rather than a web-development background.
                </p>
                <p>
                    All database interaction uses PHP's
                    <span class="tech_tag">PDO</span> with prepared statements
                    throughout, making the data layer database-agnostic and
                    protected against SQL injection by default.
                    Heavy computation is delegated to a single Python dispatcher
                    script (<span class="tech_tag">pipeline.py</span>) and to
                    system-level tools including
                    <span class="tech_tag">ClustalOmega</span>,
                    <span class="tech_tag">EMBOSS</span>, and
                    <span class="tech_tag">BLAST+</span>.
                </p>
            </section>

            <!--
             System architecture section
             The diagram below was a bit of a pain to get right layout-wise,
             trialed and errored the CSS quite a bit -> ended up with flex layers
             separated by arrow dividers, which I think reads pretty clearly
            -->
            <section class="about_section" id="architecture">
                <p class="section_eyebrow">02</p>
                <h2>System architecture</h2>
                <p>
                    The system follows a classical three-tier web architecture:
                    a browser-based presentation layer, a PHP application layer,
                    and a MySQL persistence layer — extended with a computation
                    layer that runs Python and EMBOSS processes on the server.
                </p>

                <!-- Architecture diagram: each layer is a row, arrows show the communication between them -->
                <div class="architecture_diagram" aria-label="System architecture layers">
                    <div class="architecture_layer">
                        <span class="architecture_label">Browser</span>
                        <div class="architecture_boxes">
                            <span class="architecture_box layer-browser">HTML / CSS</span>
                            <span class="architecture_box layer-browser">Vanilla JS</span>
                            <span class="architecture_box layer-browser">Chart.js</span>
                            <span class="architecture_box layer-browser">Canvas API</span>
                        </div>
                    </div>

                    <div class="architecture_arrow">↕ HTTP / JSON</div>

                    <div class="architecture_layer">
                        <span class="architecture_label">PHP layer</span>
                        <div class="architecture_boxes">
                            <span class="architecture_box layer-php">index.php</span>
                            <span class="architecture_box layer-php">fetch.php</span>
                            <span class="architecture_box layer-php">analysis.php</span>
                            <span class="architecture_box layer-php">motifs.php</span>
                            <span class="architecture_box layer-php">extras.php</span>
                            <span class="architecture_box layer-php">config.php</span>
                        </div>
                    </div>

                    <div class="architecture_arrow">↕ shell_exec / stdout JSON</div>

                    <div class="architecture_layer">
                        <span class="architecture_label">Python layer</span>
                        <div class="architecture_boxes">
                            <span class="architecture_box layer-python">pipeline.py</span>
                            <span class="architecture_box layer-python">Biopython</span>
                            <span class="architecture_box layer-python">Matplotlib</span>
                            <span class="architecture_box layer-python">Seaborn</span>
                            <span class="architecture_box layer-python">fpdf2</span>
                        </div>
                    </div>

                    <div class="architecture_arrow">↕ subprocess</div>

                    <div class="architecture_layer">
                        <span class="architecture_label">Tools</span>
                        <div class="architecture_boxes">
                            <span class="architecture_box layer-tools">ClustalOmega</span>
                            <span class="architecture_box layer-tools">EMBOSS suite</span>
                            <span class="architecture_box layer-tools">BLAST+ 2.17</span>
                            <span class="architecture_box layer-tools">NCBI Entrez</span>
                        </div>
                    </div>

                    <div class="architecture_arrow">↕ PDO / SQL</div>

                    <div class="architecture_layer">
                        <span class="architecture_label">Database</span>
                        <div class="architecture_boxes">
                            <span class="architecture_box layer-database">MySQL</span>
                            <span class="architecture_box layer-database">9 tables</span>
                            <span class="architecture_box layer-database">3 views</span>
                        </div>
                    </div>
                </div>

                <h3>File and directory layout</h3>
                <p>
                    The project root contains <code>index.php</code> and
                    <code>config.php</code>. All other pages live under
                    <code>pages/</code>. The Python dispatcher and any helper
                    scripts are in <code>scripts/</code>. Per-job output files
                    (FASTA sequences, alignment files, plots, and reports) are
                    written to <code>results/{job_id}/</code>, which is served
                    as a static directory by Apache. The pre-generated example
                    dataset lives at <code>results/example/</code>. Front-end
                    assets (CSS and JavaScript) are in <code>assets/</code>.
                    SQL schema and seed files are in <code>sql/</code>.
                </p>

                <h3>Server environment</h3>
                <p>
                    ALiHS targets the <strong>bioinfmsc8</strong> server running
                    Ubuntu 24 with Apache, PHP 8+, MySQL, Python 3, and the full
                    EMBOSS suite and BLAST+ 2.17 installed at system level.
                    The pre-installed NCBI <code>nr</code> database is available
                    locally for BLAST searches, eliminating the need for remote
                    BLAST calls and significantly reducing query time for large
                    sequence sets.
                </p>
            </section>

            <!--
             Computation pipeline section
             This one took the most thought to structure -> the pipeline was
             a strict sequence of steps, so an ordered list felt right here.
             Each PHP page calls pipeline.py and then handles DB inserts itself,
             which keeps credentials out of the Python process entirely
             -->
            <section class="about_section" id="pipeline">
                <p class="section_eyebrow">03</p>
                <h2>Computation pipeline</h2>
                <p>
                    All computational work is orchestrated by a single Python
                    dispatcher script, <code>pipeline.py</code>, which accepts
                    a <code>--task</code> argument and dispatches to the
                    appropriate internal function. PHP pages invoke it via
                    <code>shell_exec()</code>, capture its standard output
                    (always a JSON object), parse the JSON, and perform all
                    database inserts themselves using PDO.
                </p>
                <p>
                    This design keeps database logic in PHP where PDO is
                    available, keeps the Python script stateless and testable
                    in isolation, and avoids passing database credentials into
                    a child process.
                </p>

                <!-- Numbered pipeline steps --> each step maps to a --task flag in pipeline.py -->
                <ol class="pipeline_flow" aria-label="Pipeline execution steps">
                    <li>
                        <div class="pipeline_flow_dot">01</div>
                        <div class="pipeline_flow_content">
                            <strong>User submits form (fetch.php)</strong>
                            <span>PHP validates inputs, creates a job record
                                  in the database with status <em>queued</em>,
                                  and generates the results directory.</span>
                        </div>
                    </li>
                    <li>
                        <div class="pipeline_flow_dot">02</div>
                        <div class="pipeline_flow_content">
                            <strong>Sequence retrieval (pipeline.py --task fetch)</strong>
                            <span>Biopython Entrez issues an esearch then efetch
                                  against the NCBI protein database. Sequences are
                                  written to <code>sequences.fasta</code>. A JSON
                                  array of sequence records is printed to stdout.</span>
                        </div>
                    </li>
                    <li>
                        <div class="pipeline_flow_dot">03</div>
                        <div class="pipeline_flow_content">
                            <strong>PHP inserts sequences via PDO</strong>
                            <span>The JSON from stdout is parsed and each sequence
                                  record is inserted into the <code>sequences</code>
                                  table. The job status is set to <em>done</em>.</span>
                        </div>
                    </li>
                    <li>
                        <div class="pipeline_flow_dot">04</div>
                        <div class="pipeline_flow_content">
                            <strong>Alignment (pipeline.py --task conservation)</strong>
                            <span>ClustalOmega is called via subprocess. The resulting
                                  alignment is parsed with Bio.AlignIO to compute
                                  per-column Shannon entropy conservation scores
                                  and a seaborn pairwise identity heatmap PNG.</span>
                        </div>
                    </li>
                    <li>
                        <div class="pipeline_flow_dot">05</div>
                        <div class="pipeline_flow_content">
                            <strong>Conservation plot (pipeline.py --task plotcon)</strong>
                            <span>EMBOSS <code>plotcon</code> is called via subprocess
                                  on the alignment file to produce a static PNG using
                                  a sliding-window conservation score.</span>
                        </div>
                    </li>
                    <li>
                        <div class="pipeline_flow_dot">06</div>
                        <div class="pipeline_flow_content">
                            <strong>Motif scanning (pipeline.py --task motifs)</strong>
                            <span>The FASTA is split into individual sequence files.
                                  EMBOSS <code>patmatmotifs</code> is run on each.
                                  The fixed-width output format is parsed and motif
                                  hits are returned as JSON.</span>
                        </div>
                    </li>
                    <li>
                        <div class="pipeline_flow_dot">07</div>
                        <div class="pipeline_flow_content">
                            <strong>Supplementary analyses (pipeline.py --task *)</strong>
                            <span>Each extras tab triggers an independent task:
                                  pepstats, garnier, pepwindow, blast, uniprot,
                                  pdb, or report — all following the same
                                  stdout-JSON contract.</span>
                        </div>
                    </li>
                </ol>

                <!--
                 Error handling callout -> worth flagging explicitly since it's easy to miss
                 Every shell_exec call goes through runCommand() in PHP -> if Python exits non-zero,
                 a RuntimeException is thrown and the job gets marked failed in the DB.
                 Stderr is captured separately so it shows up in the server logs, not just swallowed
		 Code adapted from: https://www.php.net/manual/en/function.shell-exec.php
                -->
                <div class="informant_callout">
                    <p>
                        <strong>Error handling:</strong>
                        Every <code>runCommand()</code> call in PHP wraps the
                        shell execution in a try/catch. If the Python process
                        exits with a non-zero code, a <code>RuntimeException</code>
                        is thrown, the job is marked as <em>failed</em> in the
                        database, and a JSON error response is returned to the
                        browser. Standard error output from the child process is
                        captured separately and included in the exception message
                        for server-side logging.
                    </p>
                </div>
            </section>

            <!--
             Database design section
             Nine tables sounds like a lot but most of them are pretty slim.
             The table below lays out what each one stores and how they relate.
             The three views are there mainly to keep the PHP pages clean -> rather
             than writing the same multi-join query in three different files
             -->
            <section class="about_section" id="database">
                <p class="section_eyebrow">04</p>
                <h2>Database design</h2>
                <p>
                    ALiHS uses a MySQL database with nine tables and three
                    views. All interaction goes through a single PDO connection
                    object instantiated in <code>config.php</code> and imported
                    by every page. Every query uses prepared statements with
                    bound parameters. There are no string-interpolated SQL
                    queries anywhere in the codebase.
                </p>

                <h3>Tables</h3>
                <!-- Wrapped in overflow-x: auto so the table doesn't break the layout on smaller screens -->
                <div style="overflow-x: auto;">
                    <table class="database_schema_table">
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th>Primary purpose</th>
                                <th>Key relationships</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="database_table_name">users</span></td>
                                <td>Stores session tokens — one per browser session, no login required.</td>
                                <td>Parent of <code>jobs</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">jobs</span></td>
                                <td>One record per analysis run. Stores the protein family, taxon, NCBI query string, pipeline status, and path to the results directory.</td>
                                <td>Child of <code>users</code>; parent of all other tables</td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">sequences</span></td>
                                <td>One record per retrieved protein sequence. Stores accession, organism, NCBI taxon ID, description, the raw sequence, length, and a soft-exclude flag set by the user.</td>
                                <td>Child of <code>jobs</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">alignments</span></td>
                                <td>Alignment metadata: tool used, sequence count, alignment length, mean pairwise identity, and output file paths.</td>
                                <td>Child of <code>jobs</code>; parent of <code>conservation_scores</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">conservation_scores</span></td>
                                <td>Per-column conservation score and gap fraction for every position in an alignment. Can be hundreds to thousands of rows per job.</td>
                                <td>Child of <code>alignments</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">motif_hits</span></td>
                                <td>One record per PROSITE motif match: motif ID, name, start/end position, and source sequence.</td>
                                <td>Child of <code>jobs</code> and <code>sequences</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">blast_results</span></td>
                                <td>Top BLAST hits per sequence: query and hit accessions, organism, percent identity, E-value, and bitscore.</td>
                                <td>Child of <code>jobs</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">extra_analyses</span></td>
                                <td>Results from supplementary analyses (pepstats, garnier, UniProt annotations, etc.) stored as JSON in a <code>result_summary</code> column, alongside a path to the full output file.</td>
                                <td>Child of <code>jobs</code>; optionally linked to <code>sequences</code></td>
                            </tr>
                            <tr>
                                <td><span class="database_table_name">external_links</span></td>
                                <td>Cross-references to external databases (UniProt, PDB, KEGG) per sequence: external accession, URL, and a brief annotation summary.</td>
                                <td>Child of <code>sequences</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3>Views</h3>
                <p>
                    Three SQL views are defined to simplify common multi-table
                    queries and keep PHP pages clean:
                </p>
                <!-- 
		 detail_grid reused from global.css for consistent card layout 
		 Approach adapted from: https://risingwave.com/blog/mastering-mysql-views-a-comprehensive-guide/
		-->
                <div class="detail_grid" style="margin-top: var(--mid_space);">
                    <div class="detail_card">
                        <h4>v_job_summary</h4>
                        <p>
                            Joins <code>jobs</code>, <code>sequences</code>,
                            <code>alignments</code>, and <code>motif_hits</code>
                            to produce a single summary row per job — used by the
                            Revisit page to render job cards without multiple
                            round-trip queries.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>v_motif_overview</h4>
                        <p>
                            Aggregates <code>motif_hits</code> per job: distinct
                            motif names, how many sequences carry each motif, and
                            the positional range. Powers the motif summary table
                            on the motifs page.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>v_conservation_overview</h4>
                        <p>
                            Joins <code>conservation_scores</code> with
                            <code>alignments</code> and <code>jobs</code> to
                            provide min, max, and mean conservation per job —
                            used for summary cards and the Revisit dashboard.
                        </p>
                    </div>
                </div>

                <!--
                 Performance note for conservation_scores -> this table can get big fast,
                 a single long alignment can push thousands of rows.
                 Wrapping all those inserts in one PDO transaction made a huge
                 difference compared to auto-committing each row individually.
                 Indexing alignment_id, job_id, and sequences.job_id also helped a lot
                 with result-page load times as the database filled up
		 Approach adapted from: https://www.php.net/manual/en/pdo.transactions.php
                -->
                <h3>Performance considerations</h3>
                <p>
                    Large alignments can produce thousands of rows in
                    <code>conservation_scores</code>. These rows are inserted
                    inside a single PDO transaction to avoid the overhead of
                    individual auto-committed inserts. The
                    <code>conservation_scores.alignment_id</code> column is
                    indexed, as is <code>motif_hits.job_id</code> and
                    <code>sequences.job_id</code>, to keep result-page queries
                    fast even as the database grows.
                </p>
            </section>

            <!--
             External integrations section
             Some of these are live API calls at analysis time, others are just
             outbound links built from accession strings --> the distinction matters
             for rate-limiting and error handling so flagged it per service below
            -->
            <section class="about_section" id="integrations">
                <p class="section_eyebrow">08</p>
                <h2>External integrations</h2>
                <p>
                    ALiHS integrates with a range of external services and
                    databases, accessed either at analysis time (via Python
                    REST calls) or as static outbound links generated from
                    sequence accessions.
                </p>

                <div class="detail_grid">
                    <div class="detail_card">
                        <h4>NCBI Entrez</h4>
                        <p>
                            Used for all sequence retrieval. Accessed via
                            Biopython's <code>Bio.Entrez</code> module using
                            an API key for a higher rate limit (10 requests/s
                            vs 3/s without a key). The email address and
                            tool name are passed in every request as required
                            by NCBI's usage policy.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>PROSITE (ExPASy)</h4>
                        <p>
                            Motif scanning is performed locally using the PROSITE
                            pattern database bundled with the EMBOSS installation.
                            Each motif hit links to the live PROSITE entry at
                            <code>prosite.expasy.org</code> for full pattern
                            documentation and literature references.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>UniProt REST API</h4>
                        <p>
                            Accessed at analysis time to retrieve functional
                            annotation, catalytic activity, pathway membership,
                            GO terms, and disease associations per sequence.
                            Uses the UniProt ID Mapping endpoint to convert
                            NCBI accessions to UniProt accessions before
                            fetching annotation records.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>PDB &amp; AlphaFold</h4>
                        <p>
                            The RCSB PDB search API and the AlphaFold DB API
                            are queried by accession to retrieve available
                            3D structures. Results are stored in the
                            <code>external_links</code> table and presented
                            as direct links to the structure viewers at
                            <code>rcsb.org</code> and <code>alphafold.ebi.ac.uk</code>.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>STRING, KEGG &amp; Reactome</h4>
                        <p>
                            These resources are linked to dynamically from the
                            Extras page using URL templates populated with the
                            protein family name and sequence accessions.
                            They are outbound links only — no API calls are
                            made, so no rate-limiting concerns apply.
                        </p>
                    </div>
                    <div class="detail_card">
                        <h4>PubMed</h4>
                        <p>
                            A contextual PubMed search link is constructed from
                            the protein family and taxon query terms
                            and displayed in the Extras page sidebar, pointing
                            toward relevant primary literature without
                            requiring an API call.
                        </p>
                    </div>
                </div>
            </section>

        </main>
    </div><!-- /.about_layout -->
</div><!-- /.container -->

<!-- Site footer -->
<footer class="site_footer">
    <div class="container footer_inner">
        <p class="footer_brand">ALiHS: A Little intelligent Homology Searcher</p>
        <nav class="footer_navi" aria-label="Footer navigation">
            <a href="../index.php">Home</a>
            <a href="fetch.php">New Analysis</a>
            <a href="example.php">Example</a>
            <a href="revisit.php">My Sessions</a>
            <a href="help.php">Help</a>
            <a href="about.php">About</a>
            <a href="credits.php">Credits</a>
            <a href="https://github.com/WesleyKoc/public_html.git"
               target="_blank"
               rel="noopener noreferrer">GitHub</a>
        </nav>
        <p class="footer_note">
            Built using
            <a href="https://biopython.org/" target="_blank" rel="noopener noreferrer">Biopython</a>,
            <a href="https://emboss.sourceforge.net/" target="_blank" rel="noopener noreferrer">EMBOSS</a>,
            <a href="http://www.clustal.org/omega/" target="_blank" rel="noopener noreferrer">ClustalOmega</a>,
            and <a href="https://prosite.expasy.org/" target="_blank" rel="noopener noreferrer">PROSITE</a>.
            &nbsp;|&nbsp; Data sourced from
            <a href="https://www.ncbi.nlm.nih.gov/" target="_blank" rel="noopener noreferrer">NCBI</a>
            and <a href="https://www.uniprot.org/" target="_blank" rel="noopener noreferrer">UniProt</a>.
        </p>
    </div>
</footer>

<!-- Mobile and tablet javascript -->
<script src="../assets/js/main.js"></script>

<!--
 Table of contents scroll spy -> uses IntersectionObserver to track which section
 is currently on screen and highlights the matching TOC link accordingly.
 The rootMargin is set so the active link switches roughly when the section
 heading hits the upper third of the viewport, trialed and errored until it felt right
 Code adapted from: https://jsguides.dev/tutorials/browser-intersection-observer/
 		    https://www.boag.online/notepad/post/on-scroll-animation-with-intersectionobserver-api
		    https://stackoverflow.com/questions/64088484/what-is-the-main-difference-between-rootmargin-threshold-in-intersection-observer-api
-->
<script>
(function () {
    'use strict';

    const sections = document.querySelectorAll('.about_section');
    const tocLinks = document.querySelectorAll('.content_table_list a');

    if (!sections.length || !tocLinks.length) return;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.getAttribute('id');
                tocLinks.forEach(link => {
                    const href = link.getAttribute('href');
                    if (href === '#' + id) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            }
        });
    }, {
        rootMargin: '-20% 0px -70% 0px', <!-- top offset 20%, bottom offset 70% -> active zone is the middle band -->
        threshold: 0
    });

    sections.forEach(section => observer.observe(section));

}());
</script>

</body>
</html>
