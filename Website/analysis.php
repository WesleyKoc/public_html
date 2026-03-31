<?php
/**
 * pages/analysis.php -> conservation analysis
 *
 * This is the main handler for the conservation analysis step. It does a few
 * different things depending on how it's called:
 *
 * GET (no action) -> render the alignment options form,
 * or skip straight to results if the alignment already exists in the db
 *
 * POST ?action=run -> shell out to pipeline.py with --task conservation
 * and --task plotcon, insert the results into the alignments and
 * conservation_scores tables via PDO, then return JSON
 *
 * GET ?action=poll -> return job/alignment status as JSON
 *
 * GET ?action=get_conservation -> return per-column scores as JSON for Chart.js
 *
 * All the ?action= branches return JSON and exit immediately.
 * Only the bare GET actually renders any HTML.
 *
 * Changes: Removed ID heatmap, tried getting it to work but no time to fix
 *
 * Recycled patterns and code from fetch.php:
 * - Action routing with $_GET['action']
 * - jsonResponse() for API responses
 * - PDO prepared statements
 * - runCommand() and escapeshellarg()
 * - JavaScript: setProgress(), showError()/hideError()
 * - Custom event dispatch
 */

require_once __DIR__ . '/../config.php';


/**
 * Action: poll -> return alignment computation status
 *
 * Simple status check -> join jobs to alignments and return whatever we know.
 * Returns 404 if the job_id doesn't exist, which shouldn't happen in normal
 * use but I'd rather handle it than let it explode.
 * 
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'poll') {

    $jobId = (int) ($_GET['job_id'] ?? 0);
    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    $stmt = $pdo->prepare(
        'SELECT j.status,
                a.alignment_id,
                a.avg_identity,
                a.alignment_length,
                a.num_sequences
         FROM jobs j
         LEFT JOIN alignments a ON a.job_id = j.job_id
         WHERE j.job_id = ?
         LIMIT 1'
    );
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();

    if (!$row) jsonResponse(['error' => 'Job not found.'], 404);

    jsonResponse([
        'job_status' => $row['status'],
        'alignment_id' => $row['alignment_id'],
        'avg_identity' => $row['avg_identity'],
        'alignment_length' => $row['alignment_length'],
        'num_sequences' => $row['num_sequences'],
    ]);
}


/**
 * Action: get_conservation -> per-column scores for Chart.js
 *
 * Pulls position, conservation_score, and gap_fraction out of
 * conservation_scores ordered by position. Chart.js then handles
 * rendering this on the frontend -> I spent way too long trying to
 * do this server-side before giving up and just sending JSON.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'get_conservation') {

    $alignmentId = (int) ($_GET['alignment_id'] ?? 0);
    if ($alignmentId <= 0) jsonResponse(['error' => 'Invalid alignment_id.'], 400);

    $stmt = $pdo->prepare(
        'SELECT position, conservation_score, gap_fraction
         FROM conservation_scores
         WHERE alignment_id = ?
         ORDER BY position ASC'
    );
    $stmt->execute([$alignmentId]);
    $scores = $stmt->fetchAll();

    jsonResponse(['scores' => $scores]);
}


/**
 * Action: get_heatmap -> pairwise identity matrix and organism labels
 *
 * Returns the organism labels and NxN identity matrix as a flat array
 * so the canvas heatmap renderer in main.js can draw it. The data comes
 * from blast_results -> if BLAST hasn't run yet I fall back to an empty
 * matrix rather than throwing an error, since the page can still show
 * the conservation chart without it.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'get_heatmap') {

    $jobId = (int) ($_GET['job_id'] ?? 0);
    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    /* Grab blast all-vs-all results for this job -> this is what feeds
       the identity matrix. If BLAST hasn't run yet this just comes back empty. */
    $stmt = $pdo->prepare(
        'SELECT query_accession, hit_accession, pct_identity
         FROM blast_results
         WHERE job_id = ?
         ORDER BY query_accession, hit_accession'
    );
    $stmt->execute([$jobId]);
    $blastRows = $stmt->fetchAll();

    /* Also need organism labels keyed by accession */
    $seqStmt = $pdo->prepare(
        'SELECT accession, organism
         FROM sequences
         WHERE job_id = ? AND excluded = 0
         ORDER BY organism'
    );
    $seqStmt->execute([$jobId]);
    $seqs = $seqStmt->fetchAll();

    jsonResponse([
        'sequences' => $seqs,
        'blast_pairs' => $blastRows,
    ]);
}


/**
 * Action: run -> execute alignment and conservation pipeline
 *
 * Expects POST fields: job_id, aln_format, window_size.
 * Runs pipeline.py twice -> once for conservation, once for plotcon.
 * plotcon is non-fatal so if it fails I just log it and move on.
 * Took me a while to decide on that behaviour but it felt wrong to
 * block the whole analysis just because the static PNG didn't generate.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_GET['action'] ?? '') === 'run') {

    $jobId     = (int) ($_POST['job_id'] ?? 0);
    $alnFormat = trim($_POST['aln_format'] ?? DEFAULT_ALN_FORMAT);
    $winSize   = (int) ($_POST['window_size'] ?? DEFAULT_PLOTCON_WINDOW);

    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    /**
     * Validate alignment format -> silently fall back to default if something weird comes in
     * Code adapted from: https://www.php.net/manual/en/function.in-array.php
     *			  https://stackoverflow.com/questions/10142860/clamping-numbers-in-php
     */
    $allowedFormats = ['clustal', 'fasta', 'phylip'];
    if (!in_array($alnFormat, $allowedFormats, true)) {
        $alnFormat = DEFAULT_ALN_FORMAT;
    }

    /* Clamp window size to 1-20, anything outside that range breaks plotcon */
    $winSize = max(1, min($winSize, 20));

    /* Verify the job exists and actually has sequences to align */
    $jobStmt = $pdo->prepare(
        'SELECT j.job_id, j.session_dir,
                COUNT(s.seq_id) AS seq_count
         FROM   jobs j
         LEFT JOIN sequences s
               ON s.job_id = j.job_id AND s.excluded = 0
         WHERE  j.job_id = ?
         GROUP  BY j.job_id'
    );
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job) {
        jsonResponse(['error' => 'Job not found.'], 404);
    }
    if ((int) $job['seq_count'] < MIN_SEQUENCES_FOR_ALIGNMENT) {
        jsonResponse([
            'error' => 'At least ' . MIN_SEQUENCES_FOR_ALIGNMENT .
                       ' non-excluded sequences are required for alignment.'
        ], 400);
    }

    $jobDir = $job['session_dir'];

    /* Run pipeline.py with --task conservation */
    $conservationCmd = sprintf(
        '%s %s --task conservation --job_id %d --results_dir %s ' .
        '--aln_format %s --window_size %d',
        escapeshellarg(PYTHON_BIN),
        escapeshellarg(PIPELINE_SCRIPT),
        $jobId,
        escapeshellarg($jobDir),
        escapeshellarg($alnFormat),
        $winSize
    );

    try {
        $conservationOutput = runCommand($conservationCmd);
    } catch (RuntimeException $e) {
        jsonResponse(['error' => 'Conservation pipeline failed: ' . $e->getMessage()], 500);
    }

    $conservationResult = json_decode($conservationOutput, true);
    if (!$conservationResult || isset($conservationResult['error'])) {
        jsonResponse([
            'error' => $conservationResult['error'] ?? 'Conservation pipeline returned invalid output.'
        ], 500);
    }

    /* Run pipeline.py with --task plotcon -> non-fatal, just log and continue if it breaks */
    $plotconCmd = sprintf(
        '%s %s --task plotcon --job_id %d --results_dir %s --window_size %d',
        escapeshellarg(PYTHON_BIN),
        escapeshellarg(PIPELINE_SCRIPT),
        $jobId,
        escapeshellarg($jobDir),
        $winSize
    );

    try {
        $plotconOutput = runCommand($plotconCmd);
    } catch (RuntimeException $e) {
        /**
	 * plotcon failing isn't the end of the world -> log it and carry on; 
	 * Code adapted from: https://www.php.net/manual/en/function.error-log.php
	 */
        error_log("plotcon failed for job $jobId: " . $e->getMessage());
        $plotconResult = ['plotcon_png' => null];
    }

    if (!isset($plotconResult)) {
        $plotconResult = json_decode($plotconOutput, true) ?? ['plotcon_png' => null];
    }

    /* Insert alignment record into the db via PDO */
    $insAln = $pdo->prepare(
        'INSERT INTO alignments
            (job_id, tool_used, num_sequences, alignment_length,
             avg_identity, output_file, clustal_file)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insAln->execute([
        $jobId,
        'clustalo',
        $conservationResult['num_sequences'] ?? 0,
        $conservationResult['alignment_length'] ?? 0,
        $conservationResult['avg_identity'] ?? 0.0,
        $jobDir . '/alignment.aln',
        $jobDir . '/alignment.clustal',
    ]);
    $alignmentId = (int) $pdo->lastInsertId();

    /* Insert per-column conservation scores via PDO
       Using a transaction here because inserting thousands of rows without one
       is painfully slow -> learned that the hard way on a big alignment */
    if (!empty($conservationResult['scores'])) {
        $insScore = $pdo->prepare(
            'INSERT INTO conservation_scores
                (alignment_id, position, conservation_score, gap_fraction)
             VALUES (?, ?, ?, ?)'
        );
        /*
         * Wrapping in a transaction so all those inserts commit at once
         * rather than one auto-commit per row. Makes a real difference
         * on large alignments -> probably should have done this from the start.
	 * 
	 * Code adapted from: https://www.php.net/manual/en/pdo.transactions.php
         */
        $pdo->beginTransaction();
        foreach ($conservationResult['scores'] as $score) {
            $insScore->execute([
                $alignmentId,
                (int) $score['position'] ?? 0,
                (float) $score['conservation_score'] ?? 0.0,
                (float) $score['gap_fraction'] ?? 0.0,
            ]);
        }
        $pdo->commit();
    }

    jsonResponse([
        'success' => true,
        'alignment_id' => $alignmentId,
        'num_sequences' => $conservationResult['num_sequences'] ?? 0,
        'alignment_length' => $conservationResult['alignment_length'] ?? 0,
        'avg_identity' => round($conservationResult['avg_identity'] ?? 0, 2),
        'plotcon_png' => $plotconResult['plotcon_png'] ?? null,
        'heatmap_png' => $conservationResult['heatmap_png'] ?? null,
    ]);
}


/**
 * Html render -> bare GET, no action parameter
 *
 * Requires job_id in the URL: analysis.php?job_id=N
 * If an alignment already exists for this job the results section gets
 * pre-populated server-side so it doesn't flash in after load.
 * Also resolve paths to any pre-generated plot files here -> they may or
 * may not exist depending on whether the pipeline has already run.
 */
$jobId = (int) ($_GET['job_id'] ?? 0);

/* Redirect back to fetch if no job was provided -> nothing useful to show */
if ($jobId <= 0) {
    header('Location: fetch.php');
    exit;
}

/* Load job details including a count of non-excluded sequences */
$jobStmt = $pdo->prepare(
    'SELECT j.*,
            COUNT(s.seq_id) AS included_seqs
     FROM jobs j
     LEFT JOIN sequences s
           ON s.job_id = j.job_id AND s.excluded = 0
     WHERE j.job_id = ?
     GROUP BY j.job_id'
);
$jobStmt->execute([$jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    header('Location: fetch.php');
    exit;
}

/* Check if an alignment already exists for this job */
$alnStmt = $pdo->prepare(
    'SELECT * FROM alignments WHERE job_id = ? LIMIT 1'
);
$alnStmt->execute([$jobId]);
$existingAlignment = $alnStmt->fetch() ?: null;

/* Paths to pre-generated plots -> these might not exist yet if the pipeline hasn't run */
$jobDir      = $job['session_dir'] ?? (RESULTS_DIR . '/' . $jobId);
$plotconPath = $jobDir . '/plotcon.png';

/**
 * Convert absolute paths to web-accessible URLs.
 * Assumes results/ is served under /results/ from the web root -> had to double-check
 * this assumption when setting up the server, it wasn't obvious at first. 
 * 
 * Code adapted from: https://stackoverflow.com/questions/176719/how-to-get-the-root-path-in-php
 */
$webRoot    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$plotconUrl = file_exists($plotconPath)
              ? $webRoot . '/results/' . $jobId . '/plotcon.png'
              : null;

/* Alignment file download URL */
$alnUrl = $webRoot . '/results/' . $jobId . '/alignment.aln';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conservation Analysis — <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Chart.js for the interactive conservation line chart -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>

    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/analysis.css">
</head>

<body>

<!--
    Navigation
    Standard site header and nav -> same structure as the other pages.
-->
<header class="site_header">
    <nav class="navi_bar" role="navigation" aria-label="Main navigation">
        <a href="../index.php" class="navi_logo" aria-label="ALiHS home">
            <span class="logo_colour">AL</span><span class="logo_accent">i</span><span class="logo_colour">HS</span>
        </a>
        <ul class="navi_links" role="list">
            <li><a href="../index.php" class="navi_link">Home</a></li>
            <li class="navi_dropdown">
                <a href="fetch.php" class="navi_link active">
                    New Analysis
                </a>
                <ul class="dropdown_menu" role="list">
                    <li><a href="fetch.php?job_id=<?= $jobId ?>">1. Fetch Sequences</a></li>
                    <li><a href="analysis.php?job_id=<?= $jobId ?>">2. Conservation Analysis</a></li>
                    <li><a href="motifs.php?job_id=<?= $jobId ?>">3. Motif Scanning</a></li>
                    <li><a href="extras.php?job_id=<?= $jobId ?>">4. Extra Analyses</a></li>
                </ul>
            </li>
            <li><a href="example.php" class="navi_link">Example Dataset</a></li>
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
        <button class="navi_hamburger" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </nav>
</header>


<!--
    Page body
    Main content area -> step breadcrumb, options form, status bar, results.
-->
<main class="container" style="padding-top: var(--giant_space);
                                padding-bottom: var(--bigger_space);">

    <!-- Step breadcrumb -> shows where we are in the pipeline -->
    <nav class="step_breaddcrum" aria-label="Pipeline steps">
        <span class="step_breaddcrum_item done">
            <a href="fetch.php?job_id=<?= $jobId ?>"
               style="color: inherit; text-decoration: none;">1. Fetch Sequences</a>
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item active">2. Conservation Analysis</span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item">3. Motif Scanning</span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item">4. Extra Analyses</span>
    </nav>

    <h1 style="margin-bottom: var(--smol_space);">Conservation Analysis</h1>
    <p style="color: var(--grey_text_colour);
               margin-bottom: var(--big_space);
               max-width: 65ch;">
        Align your sequences with ClustalOmega, score per-column conservation,
        and visualise how sequence identity varies across the full alignment length.
    </p>

    <!-- Job context bar -> a quick summary of what job we're working with -->
    <div class="job_contexxt_bar">
        <span class="job_contexxt_bar_item">
            Job <strong>#<?= $jobId ?></strong>
        </span>
        <span class="job_contexxt_bar_separater">|</span>
        <span class="job_contexxt_bar_item">
            <strong><?= htmlspecialchars($job['protein_family']) ?></strong>
        </span>
        <span class="job_contexxt_bar_separater">|</span>
        <span class="job_contexxt_bar_item">
            <em><?= htmlspecialchars($job['taxonomic_group']) ?></em>
        </span>
        <span class="job_contexxt_bar_separater">|</span>
        <span class="job_contexxt_bar_item">
            <strong><?= (int) $job['included_seqs'] ?></strong> sequences
        </span>
    </div>


    <!--
        Error banner
        Hidden by default, shown by JS when something goes wrong.
        role="alert" makes screen readers announce it automatically.
    -->
    <div class="errror_banner" id="error-banner" role="alert">
        <span id="error-message">An error occurred. Please try again.</span>
    </div>


    <!--
        Alignment options form
        Hidden once results are shown, unless the re-run button is clicked.
        The server hides it immediately if an alignment already exists
        so there's no flicker on reload.
	
	Code adapted from: https://www.php.net/manual/en/language.operators.comparison.php
			   https://stackoverflow.com/questions/11552713/php-conditionally-render-html
    -->
    <section id="options-section"
             <?= $existingAlignment ? 'style="display:none;"' : '' ?>>

        <div class="optionss_form_card">

            <h2 style="font-size: 1.1rem; margin-bottom: var(--big_space);">
                Alignment &amp; analysis options
            </h2>

            <div class="optionss_form_grid">

                <!-- Alignment tool -> only ClustalOmega for now, may add MUSCLE or MAFFT later -->
                <div class="feildd_group">
                    <label for="aln_tool">Alignment tool</label>
                    <select id="aln_tool" name="aln_tool">
                        <option value="clustalo" selected>ClustalOmega (recommended)</option>
                    </select>
                    <span class="feildd_hint">
                        Additional tools can be added in future versions.
                    </span>
                </div>

                <!-- Output format -> clustal is the most readable, fasta suits downstream tools -->
                <div class="feildd_group">
                    <label for="aln_format">Alignment output format</label>
                    <select id="aln_format" name="aln_format">
                        <option value="clustal" selected>Clustal (.aln)</option>
                        <option value="fasta">FASTA (.fasta)</option>
                        <option value="phylip">Phylip (.phy)</option>
                    </select>
                    <span class="feildd_hint">
                        Clustal format is most readable; FASTA suits downstream tools.
                    </span>
                </div>

                <!-- Plotcon window size -> sliding window for the EMBOSS plotcon smoothing -->
                <div class="feildd_group">
                    <label for="window_size">
                        Conservation window size
                        <span id="window-val"
                              style="color: var(--primary_colour);
                                     font-weight: 700;">
                            <?= DEFAULT_PLOTCON_WINDOW ?>
                        </span>
                    </label>
                    <input type="range"
                           id="window_size"
                           name="window_size"
                           min="1" max="20"
                           value="<?= DEFAULT_PLOTCON_WINDOW ?>"
                           style="accent-color: var(--primary_colour); width: 100%;">
                    <span class="feildd_hint">
                        Sliding window used by EMBOSS <code>plotcon</code> (default: 4).
                    </span>
                </div>

            </div>

            <!-- Output options checkboxes -->
            <div style="margin-bottom: var(--big_space);">
                <p style="font-size: 0.85rem; font-weight: 600;
                           margin-bottom: var(--smol_space);">
                    Generate outputs
                </p>
                <div class="chekbox_group">
                    <label class="chekbox_roww">
                        <input type="checkbox" id="out_conservation" checked>
                        Interactive conservation score chart (Chart.js)
                    </label>
                    <label class="chekbox_roww">
                        <input type="checkbox" id="out_plotcon" checked>
                        EMBOSS plotcon static PNG
                    </label>
                    <label class="chekbox_roww">
                        <input type="checkbox" id="out_heatmap" checked>
                        Pairwise identity heatmap
                    </label>
                    <label class="chekbox_roww">
                        <input type="checkbox" id="out_conserved_regions" checked>
                        Highlight highly conserved regions (&gt;80% identity)
                    </label>
                </div>
            </div>

            <!-- Biological context callout -->
                <p>
                    <strong>What this analysis tells you:</strong>
                    Highly conserved positions (score near 1.0) typically correspond to
                    residues that are functionally or structurally critical — such as active-site
                    amino acids, metal-coordinating residues, or buried hydrophobic core positions.
                    Regions of low conservation may reflect lineage-specific adaptations or
                    loop regions under relaxed selective pressure.
                </p>

            <div style="display: flex; justify-content: flex-end;">
                <button class="button button_primary" id="run-btn" type="button">
                    Run Alignment &amp; Conservation Analysis
                </button>
            </div>

        </div>

    </section><!-- /#options-section -->


    <!--
        Job status bar
        Shown while the pipeline is running -> progress bar and status message
        are both updated by JS as the job moves along.
        aria-live="polite" so screen readers pick up the updates without
        interrupting whatever the user is doing.
    -->
    <div class="job_status_bar" id="job-status-bar"
         role="status" aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>
        <div class="progress_track">
            <div class="progress_bar" id="progress-bar"></div>
        </div>
        <span id="status-message">Preparing sequences&hellip;</span>
    </div>


    <!--
        Results section
        Pre-shown server-side if an alignment already exists for this job
        so returning to the page doesn't make everything disappear.
        JS adds the "visible" class after a fresh run completes.
    -->
    <section class="rezults_section <?= $existingAlignment ? 'visible' : '' ?>"
             id="results-section">

        <!-- Summary stat cards -> quick overview of what the alignment produced -->
        <div class="summ_stats_cards">
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-sequences">
                    <?= $existingAlignment ? (int) $existingAlignment['num_sequences'] : '—' ?>
                </div>
                <div class="summ_stats_label">Sequences aligned</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-aln-length">
                    <?= $existingAlignment ? (int) $existingAlignment['alignment_length'] : '—' ?>
                </div>
                <div class="summ_stats_label">Alignment length (aa)</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-avg-identity">
                    <?= $existingAlignment
                        ? round((float) $existingAlignment['avg_identity'], 1) . '%'
                        : '—' ?>
                </div>
                <div class="summ_stats_label">Mean pairwise identity</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-conserved-cols">—</div>
                <div class="summ_stats_label">Columns &gt;80% conserved</div>
            </div>
        </div>


        <!-- Conservation score chart -> interactive Chart.js line chart with tab switching -->
        <div class="plotnchart_container" id="conservation-chart-container">

            <div class="plotnchart_header">
                <div>
                    <p class="plotnchart_title">
                        Per-position conservation score
                    </p>
                    <p class="plotnchart_subtitle">
                        Shannon entropy-derived conservation score per alignment column.
                        Higher values indicate greater sequence identity at that position.
                    </p>
                </div>
                <div class="conservation_score_legend">
                    <span>Low</span>
                    <div class="score_legend_gradient"></div>
                    <span>High</span>
                </div>
            </div>

            <!-- Tab switcher -> interactive Chart.js view vs static plotcon PNG -->
            <div class="plotnchart_tabs" role="tablist">
                <button class="plotnchart_tab active"
                        role="tab"
                        data-tab="interactive"
                        aria-selected="true">
                    Interactive (Chart.js)
                </button>
                <button class="plotnchart_tab"
                        role="tab"
                        data-tab="plotcon"
                        aria-selected="false">
                    EMBOSS plotcon (static)
                </button>
            </div>

            <!-- Interactive Chart.js panel -->
            <div class="plotnchart_panel active" id="panel-interactive">
                <canvas id="conservation-chart" height="140"
                        aria-label="Conservation score per alignment position"></canvas>
                <!-- Conserved regions bar -> highlighted strip below the main chart -->
                <div class="highlit_conserved_barwrap">
                    <p class="highlit_conserved_barlabel">
                        Highly conserved regions (&gt;80%) highlighted below
                    </p>
                    <canvas id="conserved-regions-bar"
                            aria-label="Conserved regions overview bar"></canvas>
                </div>
            </div>

            <!-- Static plotcon PNG panel -> falls back to a placeholder message if the image isn't ready -->
            <div class="plotnchart_panel" id="panel-plotcon">
                <?php if ($plotconUrl): ?>
                    <img src="<?= htmlspecialchars($plotconUrl) ?>"
                         alt="EMBOSS plotcon conservation plot"
                         style="max-width: 100%; border-radius: var(--bigger_corner_rounding);">
                <?php else: ?>
                    <p style="color: var(--grey_text_colour); font-size: 0.875rem;
                               padding: var(--big_space) 0;">
                        Plotcon image will appear here after the analysis completes.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Download row -->
            <div class="download_button_row">
                <a class="download_button"
                   id="dl-scores"
                   href="<?= $webRoot . '/results/' . $jobId . '/conservation_scores.tsv' ?>"
                   download>
                    Conservation scores (.tsv)
                </a>
                <?php if ($plotconUrl): ?>
                <a class="download_button"
                   href="<?= htmlspecialchars($plotconUrl) ?>"
                   download>
                    Plotcon plot (.png)
                </a>
                <?php endif; ?>
            </div>

        </div><!-- /#conservation-chart-container -->

        <!-- Biological interpretation guidance -->
            <p>
                <strong>Interpreting the results:</strong>
                A mean pairwise identity above ~40% across a taxonomic group
                typically indicates a single orthologous protein family under
                strong purifying selection. Values below ~25% may suggest
                the query retrieved paralogues or distantly related homologues —
                consider refining the search term or applying a length filter.
            </p>
            <p>
                Sharply conserved peaks in the conservation plot often correspond
                to catalytic residues, metal-binding sites, or key structural elements.
                Cross-reference these positions with the
                <a href="motifs.php?job_id=<?= $jobId ?>">PROSITE motif scan</a>
                and the
                <a href="extras.php?job_id=<?= $jobId ?>">3D structure viewer</a>
                to confirm functional significance.
            </p>


        <!-- Proceed row -->
        <div class="proceed_row">
            <a href="motifs.php?job_id=<?= $jobId ?>"
               class="button button_primary">
                Proceed to Motif Scanning
            </a>
            <button class="button button_outline" id="rerun-btn" type="button">
                Re-run with different options
            </button>
            <a href="fetch.php?job_id=<?= $jobId ?>"
               class="button button_outline">
                Back to sequences
            </a>
        </div>

    </section><!-- /#results-section -->

    <!-- Hidden fields for JS -> these get read on page load to decide whether to fire chart events -->
    <input type="hidden" id="active-job-id"
           value="<?= $jobId ?>">
    <input type="hidden" id="existing-alignment-id"
           value="<?= $existingAlignment ? (int) $existingAlignment['alignment_id'] : '' ?>">

</main>


<!--
    Footer
    Same footer as the rest of the site -> kept consistent so nothing feels out of place.
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
    main.js handles the conservation Chart.js line chart, canvas heatmap renderer,
    and conserved-regions bar. The inline script below wires up the analysis-page-specific
    interactions -> run button, re-run button, tab switching, and chart loading on page load
    if an alignment already exists. I tried putting all of this in main.js at first but
    it got messy fast, so the page-specific stuff lives here instead.
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /* Element references -> grab everything up front */
    const jobId = parseInt(document.getElementById('active-job-id').value);
    const existingAlnId = parseInt(document.getElementById('existing-alignment-id').value) || 0;
    const runBtn = document.getElementById('run-btn');
    const rerunBtn = document.getElementById('rerun-btn');
    const optionsSection = document.getElementById('options-section');
    const statusBar = document.getElementById('job-status-bar');
    const progressBar = document.getElementById('progress-bar');
    const statusMsg = document.getElementById('status-message');
    const resultsSection = document.getElementById('results-section');
    const errorBanner = document.getElementById('error-banner');
    const errorMsg = document.getElementById('error-message');
    const windowSlider = document.getElementById('window_size');
    const windowVal = document.getElementById('window-val');

    /* Window-size slider label sync -> just keeps the displayed value in step with the slider */
    windowSlider.addEventListener('input', function () {
        windowVal.textContent = this.value;
    });

    /**
     * Chart tab switching -> toggles active class on tabs and panels 
     * 
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Roles/tab_role
     *			  https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Attributes/aria-selected
     */
    document.querySelectorAll('.plotnchart_tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.plotnchart_tab').forEach(t => {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.plotnchart_panel').forEach(p =>
                p.classList.remove('active')
            );
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            document.getElementById('panel-' + this.dataset.tab)
                    .classList.add('active');
        });
    });

    /** 
     * Run button -> POST to ?action=run, show progress, then render results 
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/HTMLButtonElement/disabled
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Element/innerHTML
     */
    runBtn.addEventListener('click', function () {

        hideError();
        runBtn.disabled = true;
        runBtn.innerHTML = 'Starting\u2026';

        statusBar.classList.add('visible');
        setProgress(10, 'Preparing alignment\u2026');
	
	/**
	 * I constructed the FormData API here recycled from pattern from fetch.php
	 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/FormData
	 */
        const formData = new FormData();
        formData.append('job_id', jobId);
        formData.append('aln_format', document.getElementById('aln_format').value);
        formData.append('window_size', windowSlider.value);

        fetch('analysis.php?action=run', {
            method: 'POST',
            body: formData,
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) throw new Error(data.error);

	    /**
	     * Here I set up the promise chain and delayed UI updates
             * Approach and code adapted from: https://javascript.info/settimeout-setinterval
             *                                 https://stackoverflow.com/questions/33289726/combination-of-async-function-await-settimeout
	     *
	     * I also decided to use null coalescing for conditional property access
             * Approach and code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Nullish_coalescing
             *                                 https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Optional_chaining
	     */

            setProgress(100, 'Analysis complete.');

            document.getElementById('stat-sequences').textContent =
                data.num_sequences ?? '—';
            document.getElementById('stat-aln-length').textContent =
                data.alignment_length ?? '—';
            document.getElementById('stat-avg-identity').textContent =
                data.avg_identity != null ? data.avg_identity + '%' : '—';

            /* Show results -> brief delay so the progress bar reaching 100% is visible */
            setTimeout(() => {
                statusBar.classList.remove('visible');
                optionsSection.style.display = 'none';
                resultsSection.classList.add('visible');

                /**
                 * Fire custom event so main.js renders the charts.
                 * main.js listens for 'alihs:load-conservation'
		 *
		 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/CustomEvent
		 *		      https://developer.mozilla.org/en-US/docs/Learn/JavaScript/Building_blocks/Events
                 */
                document.dispatchEvent(new CustomEvent('alihs:load-conservation', {
                    detail: { alignmentId: data.alignment_id, jobId }
                }));

                /** 
		 * Swap in the plotcon image if the pipeline generated one
		 *
		 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Document/createElement
		 */
                if (data.plotcon_png) {
                    const plotconPanel = document.getElementById('panel-plotcon');
                    plotconPanel.innerHTML =
                        `<img src="${data.plotcon_png}"
                              alt="EMBOSS plotcon conservation plot"
                              style="max-width:100%; border-radius: var(--bigger_corner_rounding);">`;
                }

            }, 500);
        })
        .catch(err => {
            showError(err.message || 'Analysis failed. Please try again.');
            statusBar.classList.remove('visible');
            runBtn.disabled = false;
            runBtn.innerHTML =
                'Run Alignment &amp; Conservation Analysis';
        });
    });

    /* Re-run button -> hide results, show options form again */
    if (rerunBtn) {
        rerunBtn.addEventListener('click', function () {
            resultsSection.classList.remove('visible');
            optionsSection.style.display = '';
            runBtn.disabled = false;
            runBtn.innerHTML =
                'Run Alignment &amp; Conservation Analysis';
        });
    }

    /* If an alignment already exists, fire the chart event immediately on load */
    if (existingAlnId > 0) {
        document.dispatchEvent(new CustomEvent('alihs:load-conservation', {
            detail: { alignmentId: existingAlnId, jobId }
        }));
    }

    /* Helpers -> small utility functions, nothing fancy */
    function setProgress(pct, msg) {
        progressBar.style.width = pct + '%';
        statusMsg.textContent = msg;
    }
    function showError(msg) {
        errorMsg.textContent = msg;
        errorBanner.classList.add('visible');
    }
    function hideError() {
        errorBanner.classList.remove('visible');
    }

}());
</script>

</body>
</html>

