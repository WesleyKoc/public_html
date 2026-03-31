<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * pages/example.php -> pre-loaded example dataset
 *
 * This is a read-only GET page. I pull all pre-seeded G6Pase/Aves
 * data from the database, filtering on is_example = 1, and render
 * everything across four tabbed views:
 *
 * Tab 1 -> sequences (straight from the sequences table)
 * Tab 2 -> conservation (pre-generated plots and scores)
 * Tab 3 -> motifs (PROSITE hits stored in the DB)
 * Tab 4 -> extras (pepstats, structure links, UniProt)
 *
 * Nothing gets computed here. All plots are pre-generated PNGs
 * sitting in results/example/ -> this page just serves them up.
 * There's also a little biological intro panel at the top explaining
 * why G6Pase in Aves makes for a decent worked example.
 */

require_once __DIR__ . '/../config.php';


/**
 * Load example data from database
 * All queries filter on is_example = 1 via the jobs table.
 * I'm doing them one at a time rather than a big join, which
 * is probably not the most efficient thing ever but it keeps
 * each query readable and easy to debug.
 */

/** 1. Grab the example job record first, everything else depends on this */
$jobStmt = $pdo->prepare(
    'SELECT * FROM jobs WHERE is_example = 1 LIMIT 1'
);
$jobStmt->execute();
$exJob = $jobStmt->fetch();

/** 
 * Graceful fallback if the seed script hasn't been run yet, so the page doesn't just blow up
 *
 * Code adapted from: https://www.php.net/manual/en/language.operators.comparison.php
 */

$exJobId = $exJob ? (int) $exJob['job_id'] : 0;
$seeded = (bool) $exJob;

/** 2. Sequences -> sorted by organism name, seemed the most sensible default */
$sequences = [];
if ($seeded) {
    $seqStmt = $pdo->prepare(
        'SELECT seq_id, accession, organism, taxon_id,
                description, length, order_name
         FROM sequences
         WHERE job_id = ?
         ORDER BY organism ASC'
    );
    $seqStmt->execute([$exJobId]);
    $sequences = $seqStmt->fetchAll();
}

/** 3. Alignment summary -> just grabbing one row, there should only be one per job anyway */
$alignment = null;
if ($seeded) {
    $alnStmt = $pdo->prepare(
        'SELECT * FROM alignments WHERE job_id = ? LIMIT 1'
    );
    $alnStmt->execute([$exJobId]);
    $alignment = $alnStmt->fetch() ?: null;
}

/** 4. Conservation score summary stats -> I'm computing min, max, mean and a count of
 * highly conserved columns (threshold >= 0.8) all in one query rather than fetching
 * every row into PHP. Saves a lot of memory for longer alignments.
 */
$conservationStats = ['min' => null, 'max' => null, 'mean' => null, 'conserved_cols' => 0];
if ($alignment) {
    $csStmt = $pdo->prepare(
        'SELECT MIN(conservation_score) AS min_score,
                MAX(conservation_score) AS max_score,
                AVG(conservation_score) AS mean_score,
                SUM(CASE WHEN conservation_score >= 0.8 THEN 1 ELSE 0 END) AS conserved_cols
         FROM conservation_scores
         WHERE alignment_id = ?'
    );
    $csStmt->execute([$alignment['alignment_id']]);
    $csRow = $csStmt->fetch();
    if ($csRow) {
        $conservationStats = [
            'min' => round((float) $csRow['min_score'], 2),
            'max' => round((float) $csRow['max_score'], 2),
            'mean' => round((float) $csRow['mean_score'], 2),
            'conserved_cols' => (int) $csRow['conserved_cols'],
        ];
    }
}

/** 5. Motif overview via the v_motif_overview view -> I made a DB view for this because
 * the underlying query got pretty gnarly. Sorted by seq_count descending so the most
 * common motifs show up at the top of the table.
 */
$motifOverview = [];
if ($seeded) {
    $moStmt = $pdo->prepare(
        'SELECT * FROM v_motif_overview WHERE job_id = ?
         ORDER BY seq_count DESC'
    );
    $moStmt->execute([$exJobId]);
    $motifOverview = $moStmt->fetchAll();
}

/** 6. Total motif hit count -> just a scalar for the badge on the tab button */
$motifHitCount = 0;
if ($seeded) {
    $mhStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM motif_hits WHERE job_id = ?'
    );
    $mhStmt->execute([$exJobId]);
    $motifHitCount = (int) $mhStmt->fetchColumn();
}

/** 7. Extra analyses summary -> grouped by analysis_type so I get one row per type,
 * with a record count and a sample filename. Useful for checking what's actually
 * been seeded without looping over everything.
 */
$extraSummaries = [];
if ($seeded) {
    $exStmt = $pdo->prepare(
        'SELECT analysis_type,
                COUNT(*) AS record_count,
                MIN(output_file) AS sample_file
         FROM extra_analyses
         WHERE job_id = ?
         GROUP BY analysis_type'
    );
    $exStmt->execute([$exJobId]);
    $extraSummaries = $exStmt->fetchAll();
}

/** 8. External links -> PDB, UniProt, KEGG etc., joined to sequences so I can show
 * the organism name alongside each link. Capped at 30 rows, that felt like enough
 * for an example page without drowning the user in links.
 */
$externalLinks = [];
if ($seeded) {
    $elStmt = $pdo->prepare(
        'SELECT el.database_name, el.external_id, el.url,
                el.annotation_summary,
                s.organism, s.accession
         FROM external_links el
         JOIN sequences s ON s.seq_id = el.seq_id
         WHERE s.job_id = ?
         ORDER BY el.database_name, s.organism
         LIMIT 30'
    );
    $elStmt->execute([$exJobId]);
    $externalLinks = $elStmt->fetchAll();
}

/** Derived summary numbers -> computed from the sequences array so I don't need extra queries.
 * array_unique on organism gives me species count, same trick for order names.
 * Mean length rounds to nearest integer because showing decimals for amino acid count looks weird.
 */
$speciesCount = count(array_unique(array_column($sequences, 'organism')));
$orderCount = count(array_unique(array_filter(array_column($sequences, 'order_name'))));
$lengths = array_column($sequences, 'length');
$meanLength = $lengths ? (int) round(array_sum($lengths) / count($lengths)) : 0;

/** File paths for pre-generated plots -> I need both the filesystem path to check if
 * files exist, and the web-accessible URL to actually serve them. SCRIPT_NAME gives
 * me the web root relative to the current page location.
 */
$webRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$exampleWebDir = $webRoot . '/results/example';
$exampleFsDir = EXAMPLE_DIR;

/**
 * exampleAsset -> returns the web URL for a pre-generated file if it actually
 * exists on disk, otherwise null. I use this everywhere below so the plots
 * degrade gracefully to a placeholder if the seed script hasn't been run yet.
 * 
 * Code adapted from: https://www.php.net/manual/en/language.variables.scope.php
 *		      https://www.php.net/manual/en/functions.anonymous.php
 */
function exampleAsset(string $filename): ?string {
    global $exampleFsDir, $exampleWebDir;
    return file_exists($exampleFsDir . '/' . $filename)
        ? $exampleWebDir . '/' . $filename
        : null;
}

$plotconUrl = exampleAsset('plotcon.png');
$motifMapUrl = exampleAsset('motif_image.png');
$pepstatsUrl = exampleAsset('pepstats_chart.png');
$garnierUrl = exampleAsset('garnier_chart.png');
$pepwindowUrl = exampleAsset('pepwindow_mean.png');
$fastaUrl = exampleAsset('sequences.fasta');
$alnUrl = exampleAsset('alignment.aln');
$reportUrl = exampleAsset('report.pdf');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Example Dataset &mdash; <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/example.css">
</head>

<body>

<!--
    Navigation
    Sticky header that stays at the top while scrolling.
    The hamburger toggle is handled over in main.js.
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
            <li><a href="example.php" class="navi_link active">Example Dataset</a></li>
            <li><a href="revisit.php" class="navi_link">My Sessions</a></li>
            <li><a href="help.php" class="navi_link">Help</a></li>
            <li><a href="about.php" class="navi_link">About</a></li>
            <li><a href="credits.php" class="navi_link">Credits</a></li>
            <li>
                <a href="<?= GITHUB_URL ?>" class="navi_link navi_icon_link"
                   target="_blank" rel="noopener noreferrer" aria-label="GitHub">
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
    Intro banner
    I origninally went with a deeper shade of blue here to visually distinguish this page
    from the analysis pages, which are a bit more utilitarian looking.
    But then I decided to stick with my old colour scheme.
-->
<section class="eg_intro">
    <div class="container">
        <div class="eg_intro_inner">
            <div>
                <p class="eg_intro_eyebrow">Pre-loaded example dataset</p>
                <h1>
                    Glucose-6-Phosphatase<br>
                    in <em>Aves</em> (Birds)
                </h1>
                <p class="eg_intro_desc">
                    Glucose-6-phosphatase (G6Pase) catalyses the final committed step of gluconeogenesis and glycogenolysis, releasing free
                    glucose into the bloodstream. It is anchored to the endoplasmic reticulum membrane and is critical for fasting
                    glucose homeostasis. This dataset explores how G6Pase sequence and domain structure is conserved across the avian
                    class. This group spanns highly variable metabolic demands, from long-distance migratory songbirds to
                    flightless ratites.
                </p>
                <div class="eg_intro_badges">
                    <span class="eg_intro-badge">
                        <?= count($sequences) ?: '&ndash;' ?> sequences
                    </span>
                    <span class="eg_intro-badge">
                        <?= $speciesCount ?: '&ndash;' ?> species
                    </span>
                    <span class="eg_intro-badge">
                        <?= $orderCount ?: '&ndash;' ?> orders
                    </span>
                    <?php if ($alignment): ?>
                    <span class="eg_intro-badge">
                        <?= (int) $alignment['alignment_length'] ?> aa alignment
                    </span>
                    <span class="eg_intro-badge">
                        <?= round((float) $alignment['avg_identity'], 1) ?>% mean identity
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$seeded): ?>
            <div class="unseed_notice">
                <p style="font-weight:700; margin-bottom: var(--smol_space);">
                    Example data not yet loaded
                </p>
                <p style="font-size:0.85rem; color:rgba(255,255,255,0.8);">
                    Run <code>sql/seed_example.sql</code> against your MySQL
                    database and place pre-generated plots in
                    <code>results/example/</code> to populate this page.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>


<!--
    Call to action strip
    A little nudge to run an actual analysis after browsing the example.
-->
<div class="calltoaction_strip">
    <div class="container calltoaction_strip_inner">
        <p>
            Explore the example below, then run your own analysis.
        </p>
        <a href="fetch.php"
           class="button button_light"
           style="background:#fff; color:var(--accent_colour); border-color:#fff;">
            Start my own analysis
        </a>
    </div>
</div>


<!--
    Main content
    Everything below is conditionally rendered depending on whether
    the seed data is present. If $seeded is false, I just show a
    friendly notice instead of a bunch of empty tables.
-->
<main class="container"
      style="padding-top: var(--bigger_space);
             padding-bottom: var(--giant_space);">

<?php if (!$seeded): ?>

<!-- Unseeded empty state -> tells the user what to run to populate the page -->
    <div class="unseeded_state">
        <h2 style="margin-bottom: var(--mid_space);">Example data not yet loaded</h2>
        <p style="color: var(--grey_text_colour);
                   max-width: 46ch; margin-inline: auto;">
            Import <code>sql/schema.sql</code> then
            <code>sql/seed_example.sql</code> into MySQL, and place
            the pre-generated result files in <code>results/example/</code>
            to see the full example dataset here.
        </p>
        <a href="fetch.php"
           class="button button_primary"
           style="margin-top: var(--giant_space); display: inline-flex;">
            Run your own analysis instead
        </a>
    </div>

<?php else: ?>

    <!--
        Four-tab navigation
        The tab switching logic is all inline JS at the bottom of the page.
        I use aria-selected and role="tab" here to keep it accessible.
    -->
    <div class="main_tab_navi" role="tablist" aria-label="Example dataset results">

        <button class="main_tab_ active"
                role="tab" data-tab="sequences"
                aria-selected="true" aria-controls="panel-sequences">
            Sequences
            <span class="main_tab_count"><?= count($sequences) ?></span>
        </button>

        <button class="main_tab_"
                role="tab" data-tab="conservation"
                aria-selected="false" aria-controls="panel-conservation">
            Conservation
        </button>

        <button class="main_tab_"
                role="tab" data-tab="motifs"
                aria-selected="false" aria-controls="panel-motifs">
            Motifs
            <span class="main_tab_count"><?= $motifHitCount ?></span>
        </button>

        <button class="main_tab_"
                role="tab" data-tab="extras"
                aria-selected="false" aria-controls="panel-extras">
            Extra Analyses
        </button>

    </div>


    <!--
        Tab 1 -> sequences
        Shows the stat cards up top, a little biological context callout,
        then the live-searchable sequence table. The FASTA download link
        only renders if the file actually exists on disk.
    -->
    <div class="main_tab_panel active" id="panel-sequences" role="tabpanel">

        <div class="stat_cards">
            <div class="stat_card">
                <div class="stat_value"><?= count($sequences) ?></div>
                <div class="stat_label">Sequences</div>
            </div>
            <div class="stat_card">
                <div class="stat_value"><?= $speciesCount ?></div>
                <div class="stat_label">Species</div>
            </div>
            <div class="stat_card">
                <div class="stat_value"><?= $orderCount ?></div>
                <div class="stat_label">Avian orders</div>
            </div>
            <div class="stat_card">
                <div class="stat_value"><?= $meanLength ?></div>
                <div class="stat_label">Mean length (aa)</div>
            </div>
            <div class="stat_card">
                <div class="stat_value">
                    <?= $lengths ? min($lengths) : '&ndash;' ?>
                    <span style="font-size:1rem; color:var(--grey_text_colour);">&ndash;</span>
                    <?= $lengths ? max($lengths) : '&ndash;' ?>
                </div>
                <div class="stat_label">Length range (aa)</div>
            </div>
        </div>

            <p>
                <strong>NCBI query used:</strong>
                <code><?= htmlspecialchars(
                    $exJob['ncbi_query_string']
                    ?? '"glucose-6-phosphatase"[Protein Name] AND "Aves"[Organism]'
                ) ?></code>
            </p>
            <p>
                Sequences span <?= $orderCount ?> avian orders.
                This breadth allows conservation analysis across deep
                phylogenetic divergence times estimated at over 100 million years,
                revealing which residues have remained invariant since the
                diversification of modern birds.
            </p>

<!-- Live-search input -> filters the table rows client-side via the inline JS below -->
        <div class="table_toolbar">
            <input type="text"
                   class="table_search"
                   id="seq-search"
                   placeholder="Filter by organism, order or accession&hellip;"
                   aria-label="Filter sequences">
            <span style="font-size:0.82rem; color:var(--grey_text_colour);"
                  id="seq-count-display">
                Showing <?= count($sequences) ?> sequences
            </span>
        </div>

        <div class="sequence_tablewrap">
            <table class="sequence_table" id="example-seq-table"
                   aria-label="G6Pase sequences in Aves">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Accession</th>
                        <th>Organism</th>
                        <th>Order</th>
                        <th>Length (aa)</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sequences as $i => $seq): ?>
                    <tr>
                        <td style="color:var(--grey_text_colour); font-size:0.78rem;">
                            <?= $i + 1 ?>
                        </td>
                        <td>
                            <a class="accession_link"
                               href="https://www.ncbi.nlm.nih.gov/protein/<?= htmlspecialchars($seq['accession']) ?>"
                               target="_blank" rel="noopener noreferrer">
                                <?= htmlspecialchars($seq['accession']) ?>
                            </a>
                        </td>
                        <td style="font-style:italic;">
                            <?= htmlspecialchars($seq['organism']) ?>
                        </td>
                        <td>
                            <?php if ($seq['order_name']): ?>
                                <span class="order_badge">
                                    <?= htmlspecialchars($seq['order_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--grey_text_colour);">&ndash;</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:var(--font_code); font-size:0.85rem;">
                            <?= (int) $seq['length'] ?>
                        </td>
                        <td style="color:var(--grey_text_colour);
                                   font-size:0.82rem;
                                   max-width:280px;
                                   overflow:hidden;
                                   text-overflow:ellipsis;
                                   white-space:nowrap;">
                            <?= htmlspecialchars($seq['description']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="download_row">
            <?php if ($fastaUrl): ?>
            <a class="download_button" href="<?= $fastaUrl ?>" download>
                Download FASTA
            </a>
            <?php endif; ?>
        </div>

    </div><!-- /#panel-sequences -->


    <!--
        Tab 2 -> conservation
        Stats cards, a biological interpretation callout, then the two
        pre-generated plots -> plotcon and the identity heatmap.
        Both are PNGs served straight from results/example/.
    -->
    <div class="main_tab_panel" id="panel-conservation" role="tabpanel">

        <div class="stat_cards">
            <div class="stat_card">
                <div class="stat_value">
                    <?= $alignment ? (int) $alignment['num_sequences'] : '&ndash;' ?>
                </div>
                <div class="stat_label">Sequences aligned</div>
            </div>
            <div class="stat_card">
                <div class="stat_value">
                    <?= $alignment ? (int) $alignment['alignment_length'] : '&ndash;' ?>
                </div>
                <div class="stat_label">Alignment length (aa)</div>
            </div>
            <div class="stat_card">
                <div class="stat_value">
                    <?= $alignment
                        ? round((float) $alignment['avg_identity'], 1) . '%'
                        : '&ndash;' ?>
                </div>
                <div class="stat_label">Mean pairwise identity</div>
            </div>
            <div class="stat_card">
                <div class="stat_value">
                    <?= $conservationStats['conserved_cols'] !== 0
                        ? $conservationStats['conserved_cols']
                        : '&ndash;' ?>
                </div>
                <div class="stat_label">Columns &gt;80% conserved</div>
            </div>
            <div class="stat_card">
                <div class="stat_value">
                    <?= $conservationStats['mean'] !== null
                        ? $conservationStats['mean']
                        : '&ndash;' ?>
                </div>
                <div class="stat_label">Mean conservation score</div>
            </div>
        </div>

            <p>
                <strong>Biological interpretation:</strong>
                A high mean pairwise identity across Aves indicates G6Pase is
                under strong purifying selection in birds &mdash; consistent with
                its essential role in glucose homeostasis. Loss-of-function
                mutations cause Glycogen Storage Disease Type Ia in mammals,
                and the equivalent selective constraint appears conserved
                across the avian lineage. Sharply conserved peaks in the
                plot below correspond to the catalytic histidine and
                surrounding residues of the active site.
            </p>

<!-- Plotcon conservation plot -> sliding window, pre-generated by seed_example_data.py -->
        <div class="plot_card">
            <p class="plot_card_title">
                EMBOSS plotcon &mdash; sliding-window conservation
            </p>
            <p class="plot_card_subtitle">
                Window size: <?= DEFAULT_PLOTCON_WINDOW ?> residues. Y-axis: conservation score.
                Peaks correspond to the most invariant alignment positions.
            </p>
            <?php if ($plotconUrl): ?>
                <img class="plot-image"
                     src="<?= htmlspecialchars($plotconUrl) ?>"
                     alt="EMBOSS plotcon conservation plot for G6Pase in Aves">
                <div class="score_legend">
                    <span>Low conservation</span>
                    <div class="score_legend_gradient"></div>
                    <span>High conservation</span>
                </div>
            <?php else: ?>
                <div class="plot-placeholder">
                    <span>plotcon.png not yet generated</span>
                    <span style="font-size:0.75rem;">Run seed_example_data.py to populate</span>
                </div>
            <?php endif; ?>
        </div>
    </div><!-- /#panel-conservation -->


    <!--
        Tab 3 -> motifs
        PROSITE hit counts, the pre-generated motif domain map SVG,
        and a summary table from the v_motif_overview view.
        The frequency bar in each table row is just a CSS-width trick.
    -->
    <div class="main_tab_panel" id="panel-motifs" role="tabpanel">

        <div class="stat_cards">
            <div class="stat_card">
                <div class="stat_value"><?= $motifHitCount ?></div>
                <div class="stat_label">Total motif hits</div>
            </div>
            <div class="stat_card">
                <div class="stat_value"><?= count($motifOverview) ?></div>
                <div class="stat_label">Distinct motifs</div>
            </div>
            <div class="stat_card">
                <div class="stat_value"><?= count($sequences) ?></div>
                <div class="stat_label">Sequences scanned</div>
            </div>
        </div>

            <p>
                <strong>EMBOSS patmatmotifs against PROSITE:</strong>
                Each sequence was scanned against the full PROSITE pattern
                database. The glucose-6-phosphatase catalytic subunit carries
                the
                <a href="https://prosite.expasy.org/PS00463"
                   target="_blank" rel="noopener noreferrer">
                    G6PASE active-site signature (PS00463)
                </a>
                &mdash; any sequences lacking this hit may represent truncated
                entries or lineage-specific divergence in the surrounding
                sequence context.
            </p>

<!-- Motif domain map -> pre-generated SVG, one row per sequence -->
        <div class="plot_card">
            <p class="plot_card_title">
                PROSITE motif domain map
            </p>
            <p class="plot_card_subtitle">
                Each row = one sequence. Coloured blocks = PROSITE motif hits.
                Sequences ordered by organism name.
            </p>
            <?php if ($motifMapUrl): ?>
                <div style="overflow-x:auto;">
                    <img class="plot-image"
                         src="<?= htmlspecialchars($motifMapUrl) ?>"
                         alt="PROSITE motif domain map for G6Pase in Aves">
                </div>
            <?php else: ?>
                <div class="plot-placeholder">
                    <span>motif_plot.svg not yet generated</span>
                </div>
            <?php endif; ?>
        </div>

<!-- Motif summary table -> pulled from v_motif_overview, already sorted by seq_count DESC -->
        <?php if (!empty($motifOverview)): ?>
        <div style="overflow-x:auto;
                    border:1px solid var(--border_colour);
                    border-radius:var(--bigger_corner_rounding);
                    margin-bottom:var(--big_space);">
            <table class="motif_table" aria-label="PROSITE motif summary">
                <thead>
                    <tr>
                        <th>PROSITE ID</th>
                        <th>Motif name</th>
                        <th>Sequences with hit</th>
                        <th>Position range</th>
                        <th>PROSITE link</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($motifOverview as $motif):
                    $pct = count($sequences) > 0
                           ? round(($motif['seq_count'] / count($sequences)) * 100)
                           : 0;
                ?>
                    <tr>
                        <td>
                            <span class="linkto_motif_id">
                                <?= htmlspecialchars($motif['motif_id'] ?? '&ndash;') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($motif['motif_name'] ?? '&ndash;') ?></td>
                        <td>
                            <div class="frequency_barwrap">
                                <div class="frequency_bartrack">
                                    <div class="frequency_barfill"
                                         style="width:<?= $pct ?>%;"></div>
                                </div>
                                <span class="frequency-label">
                                    <?= (int) $motif['seq_count'] ?>
                                    / <?= count($sequences) ?>
                                    (<?= $pct ?>%)
                                </span>
                            </div>
                        </td>
                        <td style="font-family:var(--font_code);
                                   font-size:0.82rem;
                                   color:var(--grey_text_colour);">
                            <?= (int) ($motif['min_start'] ?? 0) ?>
                            &ndash;
                            <?= (int) ($motif['max_end'] ?? 0) ?>
                        </td>
                        <td>
                            <?php if (!empty($motif['motif_id'])): ?>
                            <a href="https://prosite.expasy.org/<?= htmlspecialchars($motif['motif_id']) ?>"
                               target="_blank" rel="noopener noreferrer"
                               style="font-size:0.82rem;">
                                View on PROSITE
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p style="color:var(--grey_text_colour); font-size:0.875rem;
                       padding:var(--big_space) 0;">
                No motif data found. Ensure the seed data includes motif hit records.
            </p>
        <?php endif; ?>

    </div><!-- /#panel-motifs -->


    <!--
        Tab 4 -> extra analyses with nested sub-tabs
        I ended up nesting a second tab system inside this panel because
        the extras section covers four fairly different things. Tried
        doing it as one big scrollable page first but it got very long.
        The sub-tab switching logic mirrors the main tab switcher below.
    -->
    <div class="main_tab_panel" id="panel-extras" role="tabpanel">

        <div class="extras_sub_tabs" role="tablist" aria-label="Extra analysis types">
            <button class="extras_sub_tab_button"
                    role="tab" data-extras-tab="structure" aria-selected="false">
                3D Structures
            </button>
            <button class="extras_sub_tab_button"
                    role="tab" data-extras-tab="uniprot" aria-selected="false">
                UniProt annotations
            </button>
            <button class="extras_sub_tab_button"
                    role="tab" data-extras-tab="resources" aria-selected="false">
                External resources
            </button>
        </div>

<!-- 3D structures sub-panel -> PDB and AlphaFold cross-references fetched via UniProt ID Mapping API -->
        <div class="extras_panel active" id="extras-structure">
                <p>
                    <strong>PDB and AlphaFold cross-references</strong>
                    retrieved via the UniProt ID Mapping API and the RCSB
                    PDB / AlphaFold DB APIs. Experimental structures for
                    transmembrane proteins like G6Pase are limited, but
                    AlphaFold2 predictions are available for many species
                    and provide high-confidence 3D models of the catalytic domain.
                </p>
            <?php
            $structureLinks = array_filter(
                $externalLinks,
                fn($l) => in_array($l['database_name'], ['PDB','AlphaFold'])
            );
            ?>
            <?php if (!empty($structureLinks)): ?>
            <div class="grid_of_links">
                <?php foreach ($structureLinks as $link): ?>
                <div class="link_card">
                    <span class="link_card_database">
                        <?= htmlspecialchars($link['database_name']) ?>
                    </span>
                    <a class="link_card_id"
                       href="<?= htmlspecialchars($link['url']) ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?= htmlspecialchars($link['external_id']) ?>
                    </a>
                    <span class="link_card_organism">
                        <?= htmlspecialchars($link['organism']) ?>
                    </span>
                    <?php if ($link['annotation_summary']): ?>
                    <p class="link_card_annotation">
                        <?= htmlspecialchars($link['annotation_summary']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <p style="color:var(--grey_text_colour); font-size:0.875rem;
                           padding:var(--big_space) 0;">
                    No structure cross-references in the example dataset.
                    Ensure seed data includes external_links records for PDB
                    and AlphaFold.
                </p>
            <?php endif; ?>
        </div>

<!-- UniProt annotations sub-panel -> functional metadata retrieved via the UniProt REST API -->
        <div class="extras_panel" id="extras-uniprot">
                <p>
                    <strong>UniProt functional annotations</strong> retrieved
                    via the UniProt REST ID Mapping API. Key fields for G6Pase:
                    catalytic activity (hydrolysis of glucose-6-phosphate to
                    glucose + phosphate), subcellular localisation (ER membrane),
                    pathway membership (gluconeogenesis, glycogen degradation),
                    and disease associations (GSD-Ia in mammals).
                </p>
            <?php
            $uniprotLinks = array_filter(
                $externalLinks,
                fn($l) => $l['database_name'] === 'UniProt'
            );
            ?>
            <?php if (!empty($uniprotLinks)): ?>
            <div class="grid_of_links">
                <?php foreach ($uniprotLinks as $link): ?>
                <div class="link_card">
                    <span class="link_card_database">UniProt</span>
                    <a class="link_card_id"
                       href="<?= htmlspecialchars($link['url']) ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?= htmlspecialchars($link['external_id']) ?>
                    </a>
                    <span class="link_card_organism">
                        <?= htmlspecialchars($link['organism']) ?>
                    </span>
                    <?php if ($link['annotation_summary']): ?>
                    <p class="link_card_annotation">
                        <?= htmlspecialchars($link['annotation_summary']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <p style="color:var(--grey_text_colour); font-size:0.875rem;
                           padding:var(--big_space) 0;">
                    No UniProt annotation records found in the example dataset.
                </p>
            <?php endif; ?>
        </div>

<!-- External resources sub-panel -> static links I hardcoded, constructed from the protein family name -->
        <div class="extras_panel" id="extras-resources">
                <p>
                    The links below are constructed from the protein family name
                    and open in external databases. They serve as starting points
                    for further literature and pathway exploration.
                </p>
            <div class="grid_of_links">
                <div class="link_card">
                    <span class="link_card_database">KEGG Pathway</span>
                    <a class="link_card_id"
                       href="https://www.kegg.jp/kegg-bin/search_pathway_text?q=glucose-6-phosphatase"
                       target="_blank" rel="noopener noreferrer">
                        Gluconeogenesis / Glycolysis
                    </a>
                    <p class="link_card_annotation">
                        G6Pase catalyses the final step of gluconeogenesis
                        (EC&nbsp;3.1.3.9) and appears in the glycogen
                        degradation pathway.
                    </p>
                </div>
                <div class="link_card">
                    <span class="link_card_database">Reactome</span>
                    <a class="link_card_id"
                       href="https://reactome.org/content/query?q=glucose-6-phosphatase"
                       target="_blank" rel="noopener noreferrer">
                        Search Reactome
                    </a>
                    <p class="link_card_annotation">
                        Reactome pathway browser for G6Pase reactions and
                        their regulatory context.
                    </p>
                </div>
                <div class="link_card">
                    <span class="link_card_database">PubMed</span>
                    <a class="link_card_id"
                       href="https://pubmed.ncbi.nlm.nih.gov/?term=glucose-6-phosphatase+Aves"
                       target="_blank" rel="noopener noreferrer">
                        Recent literature
                    </a>
                    <p class="link_card_annotation">
                        PubMed search for G6Pase in avian species &mdash;
                        includes metabolic adaptation studies in migratory birds.
                    </p>
                </div>
                <div class="link_card">
                    <span class="link_card_database">PROSITE</span>
                    <a class="link_card_id"
                       href="https://prosite.expasy.org/PS00463"
                       target="_blank" rel="noopener noreferrer">
                        PS00463 &mdash; G6PASE active site
                    </a>
                    <p class="link_card_annotation">
                        The canonical PROSITE pattern for the G6Pase catalytic
                        subunit active-site signature.
                    </p>
                </div>
                <div class="link_card">
                    <span class="link_card_database">NCBI Taxonomy</span>
                    <a class="link_card_id"
                       href="https://www.ncbi.nlm.nih.gov/Taxonomy/Browser/wwwtax.cgi?name=Aves"
                       target="_blank" rel="noopener noreferrer">
                        Aves &mdash; taxonomic browser
                    </a>
                    <p class="link_card_annotation">
                        NCBI taxonomy tree for class Aves &mdash; explore
                        the orders represented in this dataset.
                    </p>
                </div>
                <div class="link_card">
                    <span class="link_card_database">OMIM</span>
                    <a class="link_card_id"
                       href="https://omim.org/entry/232200"
                       target="_blank" rel="noopener noreferrer">
                        GSD Type Ia (OMIM&nbsp;232200)
                    </a>
                    <p class="link_card_annotation">
                        Glycogen Storage Disease Type Ia &mdash; the human
                        disease caused by G6Pase deficiency, providing context
                        for the conserved residues identified here.
                    </p>
                </div>
            </div>
        </div>

    </div><!-- /#panel-extras -->

<?php endif; /* end $seeded */ ?>

</main>


<!--
    Footer
    Same footer across every page, just links and the site name.
-->
<footer class="site_footer">
    <div class="container footer_inner">
        <p class="footer_brand">
            <strong><?= SITE_NAME ?></strong> &mdash; Protein Sequence Conservation Portal
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
    main.js handles the nav hamburger toggle, that's all it does really.
    The inline script below takes care of:
    -> main four-tab switching
    -> nested extras sub-tab switching
    -> sequence table live search
    No AJAX on this page, everything was loaded server-side up top.
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /** 
     * Main tab switching -> deactivate everything, then activate the clicked tab and its panel
     *
     * Code adapted from: https://www.w3.org/WAI/ARIA/apg/patterns/tabs/
     *			  https://developer.mozilla.org/en-US/docs/Web/API/EventTarget/addEventListener
     */
    
    const tabBtns = document.querySelectorAll('.main_tab_');
    const tabPanels = document.querySelectorAll('.main_tab_panel');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            tabBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            tabPanels.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            const target = document.getElementById('panel-' + this.dataset.tab);
            if (target) target.classList.add('active');
        });
    });

    /* Nested extras sub-tab switching -> same pattern as above, just scoped to the extras panel */
    const extrasBtns = document.querySelectorAll('.extras_sub_tab_button');
    const extrasPanels = document.querySelectorAll('.extras_panel');

    extrasBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            extrasBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            extrasPanels.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            const target = document.getElementById('extras-' + this.dataset.extrasTab);
            if (target) target.classList.add('active');
        });
    });

    /** 
     * Sequence table live search -> filters rows client-side on every keystroke.
     * I'm hiding non-matching rows with display:none rather than removing them from the DOM,
     * so the total count stays accurate and I can just unhide them when the query is cleared.
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Element/input_event
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Node/textContent
     *			  https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/style
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Template_literals
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Conditional_Operator
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Node/textContent
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/innerHTML#security_considerations
     * 			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Functions/Arrow_functions
     */
    const searchInput = document.getElementById('seq-search');
    const seqTable = document.getElementById('example-seq-table');
    const seqCountDisplay = document.getElementById('seq-count-display');
    const totalSeqs = <?= count($sequences) ?>;

    if (searchInput && seqTable) {
        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            const rows = seqTable.querySelectorAll('tbody tr');
            let visible = 0;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = !query || text.includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            if (seqCountDisplay) {
                seqCountDisplay.textContent = query
                    ? 'Showing ' + visible + ' of ' + totalSeqs + ' sequences'
                    : 'Showing ' + totalSeqs + ' sequences';
            }
        });
    }

}());
</script>

</body>
</html>
