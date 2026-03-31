<?php
/**
 * pages/credits.php -> statement of credits
 * Static GET-only page, no DB interaction, no computation at all.
 * Just lists every third-party tool, library, database, and asset
 * I used in ALiHS, with links where I could find them.
 *
 * To add a new credit, find the relevant $sections entry below
 * and drop a new item into its 'items' array following this structure:
 *
 *     [
 *         'name' => 'Tool Name',
 *         'url' => 'https://example.com',
 *         'version' => '1.2.3', If unknown -> leave ''
 *         'use' => 'One-line description of how it is used in ALiHS.',
 *         'licence' => 'MIT', If unknown -> leave ''
 *     ],
 *
 * To add a completely new section (e.g. "Acknowledgements"), just append
 * a new entry to $sections following the same pattern as the others below.
 */

require_once __DIR__ . '/../config.php';

/**
 * Credits data
 * Each section has a title, an icon (Font Awesome class),
 * and an array of items -> I tried a few other structures before settling on this one.
 */

$sections = [

    /* Bioinformatics tools */
    [
        'id' => 'bioinformatics',
        'title' => 'Bioinformatics Tools',
        'items' => [
            [
                'name' => 'EMBOSS (European Molecular Biology Open Software Suite)',
                'url' => 'https://emboss.sourceforge.net/',
                'version' => '6.6.0',
                'use' => 'Suite of sequence analysis tools used throughout '
                       . 'ALiHS: plotcon (conservation plots), patmatmotifs '
                       . '(PROSITE motif scanning)',
                'licence' => 'GPL',
            ],
            [
                'name' => 'ClustalOmega',
                'url' => 'http://www.clustal.org/omega/',
                'version' => '1.2.4',
                'use' => 'Multiple sequence alignment engine used in the '
                       . 'Conservation Analysis step. Produces Clustal and '
                       . 'FASTA-format alignments.',
                'licence' => 'GPL',
            ],
            [
                'name' => 'NCBI BLAST+ (blastp, makeblastdb)',
                'url' => 'https://blast.ncbi.nlm.nih.gov/doc/blast-help/',
                'version' => '2.17.0',
                'use' => 'All-vs-all pairwise protein similarity search within '
                       . 'the user\'s sequence set. Used in the Extra Analyses '
                       . 'BLAST Context tab.',
                'licence' => 'Public Domain (NCBI)',
            ],
        ],
    ],

    /* Databases */
    [
        'id' => 'databases',
        'title' => 'Databases & data sources',
        'items' => [
            [
                'name' => 'NCBI Protein Database & Entrez',
                'url' => 'https://www.ncbi.nlm.nih.gov/protein/',
                'version' => '',
                'use' => 'Primary source of all protein sequences retrieved by '
                        . 'ALiHS. Queried via the Entrez esearch/efetch API '
                        . 'using Biopython.',
                'licence' => 'Public Domain (NCBI)',
            ],
            [
                'name' => 'NCBI Taxonomy Database',
                'url' => 'https://www.ncbi.nlm.nih.gov/taxonomy',
                'version' => '',
                'use' => 'Source of organism names, taxonomy IDs, and lineage '
                        . 'data extracted from GenBank records during sequence '
                        . 'retrieval.',
                'licence' => 'Public Domain (NCBI)',
            ],
            [
                'name' => 'PROSITE (ExPASy)',
                'url' => 'https://prosite.expasy.org/',
                'version' => '',
                'use' => 'Pattern and profile database used for motif scanning '
                        . 'via EMBOSS patmatmotifs. Each motif hit links directly '
                        . 'to the relevant PROSITE entry.',
                'licence' => 'CC BY 4.0',
            ],
            [
                'name' => 'UniProt / UniProtKB',
                'url' => 'https://www.uniprot.org/',
                'version' => '',
                'use' => 'Source of curated functional annotations, catalytic '
                        . 'activity descriptions, pathway membership, and GO '
                        . 'terms. Accessed via the UniProt REST API.',
                'licence' => 'CC BY 4.0',
            ],
            [
                'name' => 'RCSB Protein Data Bank (PDB)',
                'url' => 'https://www.rcsb.org/',
                'version' => '',
                'use' => 'Source of experimentally determined 3D protein '
                        . 'structures. Structure IDs and metadata retrieved '
                        . 'via the RCSB search API.',
                'licence' => 'CC0 (data)',
            ],
            [
                'name' => 'AlphaFold Protein Structure Database',
                'url' => 'https://alphafold.ebi.ac.uk/',
                'version' => '',
                'use' => 'Source of AI-predicted protein structures for '
                        . 'sequences lacking experimental data. Accessed via '
                        . 'the AlphaFold REST API.',
                'licence' => 'CC BY 4.0',
            ],
            [
                'name' => 'NCBI nr (non-redundant protein) database',
                'url' => 'https://www.ncbi.nlm.nih.gov/books/NBK153387/',
                'version' => '',
                'use' => 'Used as the local BLAST target database for the '
                        . 'contextual BLAST search in the Extra Analyses step. '
                        . 'Pre-installed on the bioinfmsc8 server.',
                'licence' => 'Public Domain (NCBI)',
            ],
        ],
    ],

    /* External web resources, these are just linked from the results pages rather than called directly */
    [
        'id' => 'external-resources',
        'title' => 'External web resources',
        'items' => [
            [
                'name' => 'KEGG (Kyoto Encyclopedia of Genes and Genomes)',
                'url' => 'https://www.kegg.jp/',
                'version' => '',
                'use' => 'Linked from the External Resources tab to provide '
                        . 'pathway context for the protein family.',
                'licence' => '',
            ],
            [
                'name' => 'Reactome',
                'url' => 'https://reactome.org/',
                'version' => '',
                'use' => 'Linked from the External Resources tab for pathway '
                        . 'and reaction context.',
                'licence' => 'CC BY 4.0',
            ],
            [
                'name' => 'STRING (protein interaction network)',
                'url' => 'https://string-db.org/',
                'version' => '',
                'use' => 'Linked from the External Resources tab to show '
                        . 'predicted and known protein-protein interactions.',
                'licence' => 'CC BY 4.0',
            ],
            [
                'name' => 'PubMed',
                'url' => 'https://pubmed.ncbi.nlm.nih.gov/',
                'version' => '',
                'use' => 'Linked from the External Resources tab for primary '
                        . 'literature relevant to the protein family '
                        . 'and taxonomic group.',
                'licence' => 'Public Domain (NCBI)',
            ],
            [
                'name' => 'InterPro (EBI)',
                'url' => 'https://www.ebi.ac.uk/interpro/',
                'version' => '',
                'use' => 'Linked from the External Resources tab for domain '
                        . 'and family classification.',
                'licence' => 'CC BY 4.0',
            ],
            [
                'name' => 'NCBI Gene',
                'url' => 'https://www.ncbi.nlm.nih.gov/gene/',
                'version' => '',
                'use' => 'Linked from the External Resources tab for gene-level '
                        . 'annotation of the protein family.',
                'licence' => 'Public Domain (NCBI)',
            ],
        ],
    ],

    /* Python libraries -> the backbone of the actual analysis pipeline */
    [
        'id' => 'python',
        'title' => 'Python libraries',
        'items' => [
            [
                'name' => 'Biopython (Bio.Entrez, Bio.SeqIO, Bio.AlignIO)',
                'url' => 'https://biopython.org/',
                'version' => '1.83',
                'use' => 'NCBI Entrez sequence retrieval (esearch, efetch), '
                        . 'FASTA/GenBank parsing, and alignment parsing in '
                        . 'pipeline.py.',
                'licence' => 'Biopython Licence (BSD-like)',
            ],
            [
                'name' => 'Matplotlib',
                'url' => 'https://matplotlib.org/',
                'version' => '3.8',
                'use' => 'Generation of the pairwise identity heatmap, '
                        . 'motif domain map SVG, pepwindow hydrophobicity '
                        . 'profile PNG, and other static plots in pipeline.py.',
                'licence' => 'PSF / BSD',
            ],
            [
                'name' => 'NumPy',
                'url' => 'https://numpy.org/',
                'version' => '1.26',
                'use' => 'Numerical computation of pairwise identity matrices '
                        . 'and mean hydrophobicity profiles in pipeline.py.',
                'licence' => 'BSD',
            ],
        ],
    ],

    /* PHP & backend -> all DB calls go through PDO, I was pretty careful about this */
    [
        'id' => 'php-backend',
        'title' => 'PHP & backend',
        'items' => [
            [
                'name' => 'PHP 8 (PDO extension)',
                'url' => 'https://www.php.net/manual/en/book.pdo.php',
                'version' => '8.2',
                'use' => 'All database interactions in ALiHS use PHP\'s PDO '
                        . '(PHP Data Objects) extension with prepared statements. '
                        . 'No raw SQL string interpolation is used anywhere.',
                'licence' => 'PHP Licence',
            ],
            [
                'name' => 'MySQL 8',
                'url' => 'https://dev.mysql.com/',
                'version' => '8.0',
                'use' => 'Relational database storing all job metadata, '
                        . 'sequences, alignment statistics, motif hits, and '
                        . 'supplementary analysis results.',
                'licence' => 'GPL / Commercial',
            ],
        ],
    ],

    /* Frontend libraries & assets -> trial and error getting Chart.js to behave, not gonna lie */
    [
        'id' => 'frontend',
        'title' => 'Frontend libraries & assets',
        'items' => [
            [
                'name' => 'Chart.js',
                'url' => 'https://www.chartjs.org/',
                'version' => '4.4.1',
                'use' => 'Interactive conservation line chart, sequence length '
                        . 'histogram, and motif frequency bar chart. Loaded from '
                        . 'the Cloudflare CDN.',
                'licence' => 'MIT',
            ],
            [
                'name' => 'Font Awesome',
                'url' => 'https://fontawesome.com/',
                'version' => '6.5.0',
                'use' => 'Icon set used throughout the navigation, page headings, '
                        . 'buttons, and status indicators. Loaded from the '
                        . 'Cloudflare CDN.',
                'licence' => 'Font Awesome Free Licence (icons: CC BY 4.0)',
            ],
            [
                'name' => 'Google Fonts: Libre Baskerville',
                'url' => 'https://fonts.google.com/specimen/Libre+Baskerville',
                'version' => '',
                'use' => 'Serif display typeface used for page headings, '
                        . 'the site wordmark, and section titles.',
                'licence' => 'OFL (SIL Open Font Licence)',
            ],
            [
                'name' => 'Google Fonts: Source Sans 3',
                'url' => 'https://fonts.google.com/specimen/Source+Sans+3',
                'version' => '',
                'use' => 'Sans-serif body typeface used for all body text, '
                        . 'form labels, and navigation links.',
                'licence' => 'OFL (SIL Open Font Licence)',
            ],
        ],
    ],

    /* Development & deployment */
    [
        'id' => 'development',
        'title' => 'Development & deployment',
        'items' => [
            [
                'name' => 'NCBI Entrez Direct (edirect)',
                'url' => 'https://www.ncbi.nlm.nih.gov/books/NBK179288/',
                'version' => '',
                'use' => 'Documentation reference for NCBI Entrez utilities. '
                        . 'ALiHS uses the Biopython Bio.Entrez interface '
                        . 'rather than the command-line edirect tools directly.',
                'licence' => 'Public Domain (NCBI)',
            ],
            [
                'name' => 'GitHub',
                'url' => GITHUB_URL,
                'version' => '',
                'use' => 'Version control and source code hosting for the '
                        . 'ALiHS codebase.',
                'licence' => '',
            ],
	    [
                'name' => 'Claude AI',
                'url' => 'https://claude.ai/',
                'version' => 'Sonnet 4.6',
                'use' => 'Debugging, structural development, and' 
			. ' optimisation of the code for readability'
                        . ' and clarity. Boilerplate code, logic, workflow'
			. ' and bottom-up code is all human written.',
                'licence' => 'https://code.claude.com/docs/en/legal-and-compliance',
            ],
        ],
    ],

    /*
     * Contributors / acknowledgements
     * I left this commented out for now -> add people here if needed in the future.
     *
     * To add a person, uncomment the section below and fill in the items
     * with the same structure as everywhere else. The 'url' field can be
     * a personal website, ORCID, or GitHub profile -> leave it '' if not applicable.
     *
     * [
     *     'id' => 'contributors',
     *     'title' => 'Contributors & acknowledgements',
     *     'icon' => 'fa-people-group',
     *     'items' => [
     *         [
     *             'name' => 'Your Name Here',
     *             'url' => 'https://github.com/your-username',
     *             'version' => '',
     *             'use' => 'Original author of ALiHS.',
     *             'licence' => '',
     *         ],
     *         [
     *             'name' => 'Supervisor / Collaborator Name',
     *             'url' => '',
     *             'version' => '',
     *             'use' => 'Project supervision and biological domain guidance.',
     *             'licence' => '',
     *         ],
     *
     *         // Add more contributors below following the same pattern
     *         // [
     *         //     'name' => 'Another Person',
     *         //     'url' => 'https://orcid.org/0000-0000-0000-0000',
     *         //     'version' => '',
     *         //     'use' => 'Contributed the motif parsing code.',
     *         //     'licence' => '',
     *         // ],
     *     ],
     * ],
     */

];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits — <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/credits.css">
</head>

<body>

<!--
    Navigation
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
                    New Analysis
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
            <li><a href="help.php" class="navi_link">Help</a></li>
            <li><a href="about.php" class="navi_link">About</a></li>
            <li><a href="credits.php" class="navi_link active">Credits</a></li>
            <li>
                <a href="<?= GITHUB_URL ?>" class="navi_link navi_icon_link"
                   target="_blank" rel="noopener noreferrer"
                   aria-label="GitHub">
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
-->
<div class="container">
<div class="streetcred_page_layout">

    <!--
      Sticky section index on the side

      Code adapted from: https://www.php.net/manual/en/control-structures.foreach.php
			 https://stackoverflow.com/questions/16348556/accessing-multi-dimensional-array-in-foreach-loop
      -->
    <aside>
        <nav class="streetcred_content_table" aria-label="Credits sections">
            <p class="title_streetcred_content_table">Sections</p>
            <ul class="list_streetcred_content_table" role="list">
                <?php foreach ($sections as $section): ?>
                <li>
                    <a href="#<?= htmlspecialchars($section['id']) ?>">
                        <?= htmlspecialchars($section['title']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </aside>

    <!-- Main content -->
    <main>

        <!-- Page title -->
        <div style="margin-bottom: var(--bigger_space); padding-top: var(--bigger_space);">
            <p class="streetcred_eyebrow">Attribution</p>
            <h1 style="margin-bottom: var(--smol_space);">Statement of Credits</h1>
            <p style="color: var(--grey_text_colour); max-width: 60ch;">
                ALiHS is built on a foundation of open-source tools, public
                databases, and freely available libraries. Full attribution is
                given here to all third-party resources used in this project.
            </p>
            <br>
            <!-- AI Usage Statement -->
            <p class="streetcred_eyebrow">AI Usage Statement</p>
            <p>
                Development of ALiHS was supported by AI tools including <strong>Claude by Anthropic</strong> 
                and <strong>DeepSeek</strong>. These tools assisted with code cleanup and readability 
                (CSS, HTML, JS, PHP formatting), comment enhancement for maintainability, converting 
                planning notes into readable Help/About page content, generating database seed automation 
                scripts, creating temporary HTML/CSS/JS mockups for layout testing, debugging assistance, 
                and standardizing variable names across files. All core architecture decisions, database 
                schema design, biological workflow logic, UI/UX design choices, analytical pipeline 
                integration (<code>pipeline.py</code>), PDO/PHP database queries, and the overall project 
                concept were human-authored.
            </p>
        </div>

        <!-- Loop through and render each section as a table -->
        <?php foreach ($sections as $section): ?>
        <section class="streetcred_section"
                 id="<?= htmlspecialchars($section['id']) ?>">

            <h2>
                <?= htmlspecialchars($section['title']) ?>
            </h2>

            <div class="streetcred_tablewrap">
                <table class="streetcred_table">
                    <thead>
                        <tr>
                            <th style="width:28%;">Name</th>
                            <th>Usage in ALiHS</th>
                            <th style="width:8%;">Version</th>
                            <th style="width:12%;">Licence</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($section['items'] as $item): ?>
                        <tr>
                            <!-- 
			     Name + link, fall back to a plain span if there's no URL 

			     Code adapted from: https://www.php.net/manual/en/function.empty.php
						https://www.php.net/manual/en/language.operators.comparison.php
						https://www.php.net/manual/en/function.htmlspecialchars.php
						https://owasp.org/www-community/attacks/xss/
			     -->
                            <td>
                                <?php if (!empty($item['url'])): ?>
                                    <a class="tool_name"
                                       href="<?= htmlspecialchars($item['url']) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="tool_name" style="color:var(--base_text_colour);">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Usage description -->
                            <td style="color:var(--grey_text_colour);">
                                <?= htmlspecialchars($item['use']) ?>
                            </td>

                            <!-- Version badge, or a dash if I don't know it -->
                            <td>
                                <?php if (!empty($item['version'])): ?>
                                    <span class="version_badge">
                                        <?= htmlspecialchars($item['version']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--border_colour);">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Licence badge, same deal -->
                            <td>
                                <?php if (!empty($item['licence'])): ?>
                                    <span class="licence_badge">
                                        <?= htmlspecialchars($item['licence']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--border_colour);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </section>
        <?php endforeach; ?>

    </main><!-- /main -->

</div><!-- /.streetcred_page_layout -->
</div><!-- /.container -->


<!--
    Footer
-->
<footer class="site_footer">
    <div class="container footer_inner">
        <p class="footer_brand">
            <strong><?= SITE_NAME ?></strong> | A Little intelligent Homology Searcher
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
    The inline script below handles TOC active-link highlighting on scroll -> I used
    IntersectionObserver for this rather than a scroll event listener, which I tried first
    and it was a mess.

    Code adapted from:  https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
			https://developer.mozilla.org/en-US/docs/Glossary/IIFE
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /** 
     * Content table active-link highlighting via IntersectionObserver
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/IntersectionObserver
     *			  https://developer.mozilla.org/en-US/docs/Web/API/IntersectionObserverEntry
     *			  https://developer.mozilla.org/en-US/docs/Web/API/IntersectionObserver/rootMargin
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/classList/toggle
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/classList/toggle#parameters
     *			  https://developer.mozilla.org/en-US/docs/Web/API/NodeList/forEach
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Document/querySelectorAll
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/getAttribute
     */
    const sections = document.querySelectorAll('.streetcred_section');
    const tocLinks = document.querySelectorAll('.list_streetcred_content_table a');

    if (!sections.length || !tocLinks.length) return;

    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                const id = entry.target.id;
                tocLinks.forEach(function (link) {
                    link.classList.toggle(
                        'active',
                        link.getAttribute('href') === '#' + id
                    );
                });
            }
        });
    }, {
        rootMargin: '-20% 0px -70% 0px',
        threshold: 0,
    });

    sections.forEach(function (s) { observer.observe(s); });

}());
</script>

</body>
</html>
