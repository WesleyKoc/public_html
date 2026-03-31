<?php
/**
 * pages/motifs.php — PROSITE Motif Scanning
 *
 * Handles the motif scanning step of the pipeline.
 * Does a few different things depending on how it's called:
 *
 * GET (no action) -> renders the motif scan options form. If results already exist in the DB, shows them instead.
 *
 * POST ?action=run -> calls pipeline.py --task motifs, parses the TSV output, and inserts motif_hits into the DB.
 *                     Returns JSON so the frontend knows what happened.
 *
 * GET ?action=poll -> returns job/scan status as JSON. Used by the progress bar while the pipeline runs.
 *
 * GET ?action=get_motifs -> returns motif hit data as JSON. Feeds the canvas-based motif map and the Chart.js chart.
 *
 * Any request with ?action= returns JSON and exits immediately.
 * Only the bare GET without any action actually renders HTML.
 *
 * Patterns adapted from fetch.php and analysis.php:
 * - JSON response helper
 * - Action routing pattern 
 * - PDO prepared statements
 * - runCommand() with escapeshellarg
 * - Job directory path resolution
 * - Error banner visibility
 * - Progress bar simulation
 * - Polling endpoint structure
 * - Pre-loading results server-side
 * - Summary statistics cards
 * - Web root to URL conversion
 * - Hidden fields for JavaScript state
 * - Transaction for batch inserts
 * - SQL with JOIN and conditions
 */

require_once __DIR__ . '/../config.php';


/**
 * Action: poll -> returns scan status
 *
 * The frontend hits this every couple seconds to see if the motif scan is done.
 * I just check the motif_hits table -> if there are any rows, the scan completed.
 * Also grab the job status from the jobs table for good measure.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll') {
    $jobId = (int) ($_GET['job_id'] ?? 0);
    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS hit_count,
                COUNT(DISTINCT motif_id) AS distinct_motifs,
                COUNT(DISTINCT seq_id)   AS seqs_with_hits
         FROM   motif_hits
         WHERE  job_id = ?'
    );
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();

    $jobStmt = $pdo->prepare('SELECT status FROM jobs WHERE job_id = ?');
    $jobStmt->execute([$jobId]);
    $jobRow = $jobStmt->fetch();

    jsonResponse([
        'job_status'      => $jobRow['status'] ?? 'unknown',
        'done'            => (int) $row['hit_count'] > 0,
        'hit_count'       => (int) $row['hit_count'],
        'distinct_motifs' => (int) $row['distinct_motifs'],
        'seqs_with_hits'  => (int) $row['seqs_with_hits'],
    ]);
}


/**
 * Action: get_motifs -> returns full hit data for the JS renderers
 *
 * This spits out everything the frontend needs to draw the canvas motif map
 * and the Chart.js frequency bar chart. I used to try and render the map server-side
 * but that got messy -> it's much cleaner to send JSON and let the canvas do the work.
 * Also returns a summary for the frequency chart so the frontend doesn't have to
 * do its own aggregation.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_motifs') {
    $jobId = (int) ($_GET['job_id'] ?? 0);
    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    $hitsStmt = $pdo->prepare(
        'SELECT mh.hit_id,
                mh.motif_id,
                mh.motif_name,
                mh.start_pos,
                mh.end_pos,
                s.seq_id,
                s.accession,
                s.organism,
                s.length AS seq_length
         FROM   motif_hits mh
         JOIN   sequences  s ON s.seq_id = mh.seq_id
         WHERE  mh.job_id = ?
           AND  s.excluded = 0
         ORDER  BY s.organism, mh.start_pos'
    );
    $hitsStmt->execute([$jobId]);
    $hits = $hitsStmt->fetchAll();

    $summaryStmt = $pdo->prepare(
        'SELECT mh.motif_id,
                mh.motif_name,
                COUNT(DISTINCT mh.seq_id) AS seq_count,
                COUNT(*)                  AS total_hits,
                MIN(mh.start_pos)         AS earliest_pos,
                MAX(mh.end_pos)           AS latest_pos
         FROM   motif_hits mh
         JOIN   sequences  s ON s.seq_id = mh.seq_id
         WHERE  mh.job_id = ? AND s.excluded = 0
         GROUP  BY mh.motif_id, mh.motif_name
         ORDER  BY seq_count DESC'
    );
    $summaryStmt->execute([$jobId]);
    $summary = $summaryStmt->fetchAll();

    $totalStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM sequences WHERE job_id = ? AND excluded = 0'
    );
    $totalStmt->execute([$jobId]);
    $totalSeqs = (int) $totalStmt->fetchColumn();

    jsonResponse([
        'hits'       => $hits,
        'summary'    => $summary,
        'total_seqs' => $totalSeqs,
    ]);
}


/**
 * Action: run -> executes the motif scan via pipeline.py
 *
 * This is where the magic happens. Takes the job_id and the include_weak flag,
 * shells out to the Python pipeline, then parses the TSV it spits out.
 * I delete any existing hits first -> that way re-running with different options
 * doesn't leave stale data lying around.
 *
 * The TSV parsing was a bit fiddly -> I originally tried reading the JSON output
 * from the pipeline, but the motif scan writes a TSV file anyway, so I just parse that.
 * It felt like less code overall, even if it means an extra file read.
 *
 * TSV file parsing code adapted from:  https://www.php.net/manual/en/function.fgetcsv.php
 * 					https://stackoverflow.com/questions/17248128/reading-tsv-file-in-php
 *					https://www.php.net/manual/en/book.filesystem.php
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'run') {
    $jobId       = (int) ($_POST['job_id'] ?? 0);
    $includeWeak = isset($_POST['include_weak']) ? 1 : 0;

    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    $jobStmt = $pdo->prepare(
        'SELECT j.session_dir,
                COUNT(s.seq_id) AS seq_count
         FROM   jobs j
         LEFT JOIN sequences s
               ON s.job_id = j.job_id AND s.excluded = 0
         WHERE  j.job_id = ?
         GROUP  BY j.job_id'
    );
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job) jsonResponse(['error' => 'Job not found.'], 404);
    if ((int) $job['seq_count'] < 1) jsonResponse(['error' => 'No sequences available for motif scanning.'], 400);

    $jobDir = $job['session_dir'];

    $cmd = sprintf(
        '%s %s --task motifs --job_id %d --results_dir %s --include_weak %d',
        escapeshellarg(PYTHON_BIN),
        escapeshellarg(PIPELINE_SCRIPT),
        $jobId,
        escapeshellarg($jobDir),
        $includeWeak
    );

    try {
        $stdout = runCommand($cmd);
    } catch (RuntimeException $e) {
        jsonResponse(['error' => 'Motif scan failed: ' . $e->getMessage()], 500);
    }

    $result = json_decode($stdout, true);

    error_log("Pipeline stdout: " . $stdout);
    error_log("Pipeline result: " . print_r($result, true));

    if (!empty($result['combined_tsv'])) {
        error_log("TSV file path: " . $result['combined_tsv']);
        error_log("TSV file exists: " . (file_exists($result['combined_tsv']) ? 'yes' : 'no'));
        if (file_exists($result['combined_tsv'])) {
            error_log("TSV file contents: " . file_get_contents($result['combined_tsv']));
        }
    }

    if (!$result || isset($result['error'])) {
        jsonResponse(['error' => $result['error'] ?? 'Pipeline returned invalid output.'], 500);
    }

    if (!empty($result['combined_tsv']) && file_exists($result['combined_tsv'])) {
        $pdo->prepare('DELETE FROM motif_hits WHERE job_id = ?')->execute([$jobId]);

        $handle = fopen($result['combined_tsv'], 'r');
        if ($handle !== false) {
            $header = fgetcsv($handle, 0, "\t");

            $ins = $pdo->prepare(
                'INSERT INTO motif_hits
                    (job_id, seq_id, motif_id, motif_name,
                     start_pos, end_pos, score)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

	    /**
	     * I decided to use conditional PDO inserts with loops to make things a bit simpler
	     * instead of doing it one by one
	     *
	     * Code adapted from: https://www.php.net/manual/en/pdo.transactions.php
	     *			  https://stackoverflow.com/questions/39532606/php-conditional-insert-with-pdo
	     */

            $pdo->beginTransaction();
            $inserted = 0;

            while (($row = fgetcsv($handle, 0, "\t")) !== false) {
                if (count($row) >= 6) {
                    $accession = trim($row[0]);
                    $start_pos = (int)$row[1];
                    $end_pos = (int)$row[2];
                    $score = !empty($row[3]) && $row[3] !== '-' ? (float)$row[3] : null;
                    $motif_id = trim($row[4]);
                    $motif_name = trim($row[5]);

                    if (empty($motif_id) && !empty($motif_name)) {
                        $motif_id = $motif_name;
                    }

                    $seqStmt = $pdo->prepare(
                        'SELECT seq_id FROM sequences
                         WHERE job_id = ? AND accession = ? AND excluded = 0'
                    );
                    $seqStmt->execute([$jobId, $accession]);
                    $seqRow = $seqStmt->fetch();

                    if ($seqRow) {
                        $ins->execute([
                            $jobId,
                            $seqRow['seq_id'],
                            $motif_id,
                            $motif_name,
                            $start_pos,
                            $end_pos,
                            $score
                        ]);
                        $inserted++;
                    }
                }
            }

            $pdo->commit();
            fclose($handle);

            $result['total_hits'] = $inserted;
            $result['distinct_motifs'] = count(array_unique(
                array_column($result['hits'] ?? [], 'motif_id')
            ));
        }
    }

    jsonResponse([
        'success'         => true,
        'hit_count'       => count($result['hits'] ?? []),
        'distinct_motifs' => count(array_unique(
            array_column($result['hits'] ?? [], 'motif_id')
        )),
        'motif_plot_svg'  => $result['motif_plot_svg'] ?? null,
    ]);
}


/**
 * HTML render -> bare GET, no action parameter
 *
 * This is the main page view. Requires job_id in the URL -> motifs.php?job_id=N
 * If motif hits already exist for this job, the results section gets pre-populated
 * server-side. That way when you come back to the page later, everything just shows up
 * without any extra API calls. I also pre-load the motif summary here for the table
 * and the per-sequence accordion data -> it's faster than making the frontend fetch it.
 */
$jobId = (int) ($_GET['job_id'] ?? 0);

if ($jobId <= 0) {
    header('Location: fetch.php');
    exit;
}

$jobStmt = $pdo->prepare(
    'SELECT j.*,
            COUNT(s.seq_id) AS included_seqs
     FROM   jobs j
     LEFT JOIN sequences s
           ON s.job_id = j.job_id AND s.excluded = 0
     WHERE  j.job_id = ?
     GROUP  BY j.job_id'
);
$jobStmt->execute([$jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    header('Location: fetch.php');
    exit;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM motif_hits WHERE job_id = ?');
$countStmt->execute([$jobId]);
$scanDone = (int) $countStmt->fetchColumn() > 0;

$motifSummary   = [];
$hitsBySeq      = [];
$totalHits      = 0;
$distinctMotifs = 0;
$seqsWithHits   = 0;
$totalSeqs      = (int) $job['included_seqs'];

if ($scanDone) {
    $sumStmt = $pdo->prepare(
        'SELECT mh.motif_id,
                mh.motif_name,
                COUNT(DISTINCT mh.seq_id) AS seq_count,
                COUNT(*)                  AS total_hits,
                MIN(mh.start_pos)         AS earliest_pos,
                MAX(mh.end_pos)           AS latest_pos
         FROM   motif_hits mh
         JOIN   sequences  s ON s.seq_id = mh.seq_id
         WHERE  mh.job_id = ? AND s.excluded = 0
         GROUP  BY mh.motif_id, mh.motif_name
         ORDER  BY seq_count DESC, mh.motif_name ASC'
    );
    $sumStmt->execute([$jobId]);
    $motifSummary = $sumStmt->fetchAll();

    $distinctMotifs = count($motifSummary);
    $totalHits      = (int) array_sum(array_column($motifSummary, 'total_hits'));

    $swStmt = $pdo->prepare('SELECT COUNT(DISTINCT seq_id) FROM motif_hits WHERE job_id = ?');
    $swStmt->execute([$jobId]);
    $seqsWithHits = (int) $swStmt->fetchColumn();

    /**
     * Code adapted from: https://www.w3schools.com/sql/func_mysql_group_concat.asp
     */

    $seqStmt = $pdo->prepare(
        'SELECT s.seq_id, s.accession, s.organism, s.length,
                COUNT(mh.hit_id)          AS hit_count,
                GROUP_CONCAT(mh.motif_name
                             ORDER BY mh.start_pos
                             SEPARATOR "; ") AS motif_list
         FROM   sequences  s
         JOIN   motif_hits mh ON mh.seq_id = s.seq_id
         WHERE  s.job_id = ? AND s.excluded = 0
         GROUP  BY s.seq_id
         ORDER  BY s.organism ASC
         LIMIT  50'
    );
    $seqStmt->execute([$jobId]);
    $hitsBySeq = $seqStmt->fetchAll();
}

$jobDir      = $job['session_dir'] ?? (RESULTS_DIR . '/' . $jobId);
$svgPath     = $jobDir . '/motif_plot.svg';
$svgExists   = file_exists($svgPath);
$webRoot     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$jobWeb      = $webRoot . '/results/' . $jobId;
$svgUrl      = $jobWeb  . '/motif_plot.svg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motif Scanning (ALiHS)</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>

    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/motifs.css">
</head>

<body>

<!--
    Navigation header
    Same across all pages -> keeps things consistent.
    The active step in the dropdown changes based on where we are.
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
                    New Analysis <i class="fa-solid fa-chevron-down fa-xs"></i>
                </a>
                <ul class="dropdown_menu" role="list">
                    <li><a href="fetch.php?job_id=<?= $jobId ?>">1. Fetch Sequences</a></li>
                    <li><a href="analysis.php?job_id=<?= $jobId ?>">2. Conservation Analysis</a></li>
                    <li><a href="motifs.php?job_id=<?= $jobId ?>">3. Motif Scanning</a></li>
                    <li><a href="extras.php?job_id=<?= $jobId ?>">4. Extra Analyses</a></li>
                </ul>
            </li>
            <li><a href="example.php"  class="navi_link">Example Dataset</a></li>
            <li><a href="revisit.php"  class="navi_link">My Sessions</a></li>
            <li><a href="help.php"     class="navi_link">Help</a></li>
            <li><a href="about.php"    class="navi_link">About</a></li>
            <li><a href="credits.php"  class="navi_link">Credits</a></li>
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
    Main content area
    This is where all the visible stuff lives. I've structured the page so the
    options form shows first if the scan hasn't run yet, otherwise the results
    section gets displayed immediately. Saves the user from waiting for an
    extra API call when they come back to the page later.
-->
<main class="container"
      style="padding-top: var(--bigger_space);
             padding-bottom: var(--giant_space);">

    <!--
        Step breadcrumb
	Same getup as the rest of the other sites.
        Like before, it helps people not get lost
        when they're jumping between steps. The first two steps are clickable
        links so you can go back if you need to tweak something.
    -->
    <nav class="step_breaddcrum" aria-label="Pipeline steps">
        <span class="step_breaddcrum_item done">
            <a href="fetch.php?job_id=<?= $jobId ?>"
               style="color:inherit;text-decoration:none;">
                Fetch Sequences
            </a>
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item done">
            <a href="analysis.php?job_id=<?= $jobId ?>"
               style="color:inherit;text-decoration:none;">
                Conservation Analysis
            </a>
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item active">
            Motif Scanning
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item">Extra Analyses</span>
    </nav>

    <h1 style="margin-bottom: var(--tiny_space);">Motif Scanning</h1>
    <p style="color: var(--grey_text_colour);
               margin-bottom: var(--bigger_space);
               max-width: 65ch;">
        Scan every sequence against the PROSITE pattern database to identify
        known functional domains, active-site signatures, and binding motifs.
    </p>

    <!--
        Job context bar
        A little info panel at the top so it's always clear which job I'm
        looking at. I've had too many moments where I had multiple tabs open
        and lost track of which job was which -> this helps.
    -->
    <div class="job_contexxt">
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
            <strong><?= $totalSeqs ?></strong> sequences
        </span>
    </div>

    <!--
        Error banner
        Hidden by default, pops up when something goes wrong.
        I use role="alert" so screen readers announce it immediately.
        Learned that the hard way after getting accessibility feedback.
    -->
    <div class="errror_banner" id="error-banner" role="alert">
        <span id="error-message">An error occurred. Please try again.</span>
    </div>

    <!--
        Options form
        This section shows the user the available settings for the motif scan.
        If the scan has already run, I hide it -> but the re-run button brings
        it back. I tried keeping it visible all the time but it made the page
        feel too cluttered when results were already there.
    -->
    <section id="options-section" <?= $scanDone ? 'style="display:none;"' : '' ?>>
        <div class="optionss_card">
            <h2 style="font-size:1.1rem; margin-bottom:var(--big_space);">
                Scan options
            </h2>

            <div style="display:grid;
                         grid-template-columns:repeat(auto-fit, minmax(220px,1fr));
                         gap:var(--big_space);
                         margin-bottom:var(--big_space);">
                <div class="feildd_group">
                    <label>Include weak / low-scoring matches</label>
                    <div class="refseq_toggle_row">
                        <label class="refseq_toggle_switch"
                               aria-label="Include weak matches">
                            <input type="checkbox"
                                   id="include_weak"
                                   name="include_weak">
                            <span class="refseq_toggle_slider"></span>
                        </label>
                        <span class="feildd_hint" style="margin:0;">
                            Off by default -> weak matches have higher false-positive rates.
                        </span>
                    </div>
                </div>
            </div>

            <p>
                <strong>What this analysis tells you:</strong>
                PROSITE patterns are curated from experimentally characterised proteins.
                A motif hit confirms the presence of a specific functional signature,
                like an active-site residue arrangement, a cofactor-binding loop,
                or a post-translational modification site. Coverage across the taxon
                shows whether the functional feature is universally retained or
                lineage-specific. I find this particularly useful when comparing
                closely related species -> a motif missing in one lineage often points
                to a functional divergence.
            </p>

            <div style="display:flex; justify-content:flex-end;">
                <button class="button_primary"
                        id="run-btn" type="button">
                    Run Motif Scan
                </button>
            </div>
        </div>
    </section>

    <!--
        Job status bar
        Shows up while the scan is running. I originally tried to get real
        progress updates from the pipeline but that proved to be a nightmare
        with the way PROSITE scan works. So instead I just animate it from 10%
        to 100% and call it done. Not perfect but it gives the user something
        to look at while waiting.
    -->
    <div class="job_status_bar" id="job-status-bar"
         role="status" aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>
        <div class="progress_track">
            <div class="progress_bar" id="progress-bar"></div>
        </div>
        <span id="status_message">Preparing scan&hellip;</span>
    </div>

    <!--
        Results section
        This is the big one. If the scan already completed, I pre-populate
        everything server-side -> stats cards, motif map, summary table,
        and the per-sequence accordion. That way the page loads with everything
        ready instead of waiting for JavaScript to fetch and render.
        The "visible" class controls whether it shows up at all.
    -->
    <section class="rezults_section <?= $scanDone ? 'visible' : '' ?>"
             id="results-section">

        <!--
            Summary stat cards
            Quick numbers at the top so the user gets an immediate sense
            of what the scan found. Total hits, distinct motifs, sequences
            with hits, and overall coverage.
        -->
        <div class="summ_stats_cards">
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-total-hits">
                    <?= $scanDone ? $totalHits : '—' ?>
                </div>
                <div class="summ_stats_label">Total hits</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-distinct-motifs">
                    <?= $scanDone ? $distinctMotifs : '—' ?>
                </div>
                <div class="summ_stats_label">Distinct motifs</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-seqs-with-hits">
                    <?= $scanDone ? $seqsWithHits : '—' ?>
                </div>
                <div class="summ_stats_label">Sequences with hits</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-coverage">
                    <?= ($scanDone && $totalSeqs > 0) ? round($seqsWithHits / $totalSeqs * 100, 0) . '%' : '—' ?>
                </div>
                <div class="summ_stats_label">Overall coverage</div>
            </div>
        </div>

        <!--
            Motif domain map
            This is the visual centrepiece. If the pipeline generated an SVG,
            I inline it directly -> that was a last-minute addition because
            the canvas version was too slow for large datasets. The canvas
            fallback is still there for smaller jobs or if something went
            wrong with the SVG generation.

	    Code adapted from:  https://www.php.net/manual/en/function.preg-replace.php
				https://stackoverflow.com/questions/1680491/how-to-remove-xml-prolog-from-generated-svg
        -->
        <div class="plotnchart_-container">
            <div class="plotnchart_-header">
                <div>
                    <p class="plotnchart_-title">
                        Motif domain map
                    </p>
                    <p class="plotnchart_-subtitle">
                        Each row is one sequence scaled to alignment length.
                        Coloured blocks show PROSITE motif hits.
                        Hover a block for details.
                    </p>
                </div>
            </div>

            <?php if ($scanDone): ?>
                <?php if ($svgExists): ?>
                <div class="motf_domainmap_wrapper" id="motif-map-wrapper">
                    <?php
                        $svgContent = file_get_contents($svgPath);
                        $svgContent = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $svgContent);
                        $svgContent = preg_replace('/<!DOCTYPE[^>]*\>\s*/i', '', $svgContent);
                        echo $svgContent;
                    ?>
                </div>
                <?php else: ?>
                <div class="motf_domainmap_wrapper" id="motif-map-wrapper">
                    <canvas id="motif-map-canvas" /* Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Canvas_API */
                            aria-label="Motif domain map"></canvas>
                    <p style="font-size:0.78rem;
                               color:var(--grey_text_colour);
                               margin-top:var(--smol_space);
                               padding:0 var(--smol_space);">
                        Canvas-rendered from stored motif hit data.
                    </p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="motf_domainmap_wrapper"
                     style="min-height:120px;
                            display:flex;
                            align-items:center;
                            justify-content:center;">
                    <p style="color:var(--grey_text_colour);
                               font-size:0.875rem;">
                        Run the scan above to generate the motif map.
                    </p>
                </div>
            <?php endif; ?>

            <div class="download_button_row">
                <?php if ($svgExists): ?>
                <a class="download_button"
                   href="<?= $svgUrl ?>" download>
                    motif_plot.svg
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!--
            Motif frequency bar chart
            Chart.js does the heavy lifting here. I pass it the summary data
            embedded as JSON, and it draws a bar chart showing how many
            sequences carry each motif. The colours match the domain map
            so it's easier to cross-reference.
        -->
        <div class="plotnchart_-container">
            <div class="plotnchart_-header">
                <div>
                    <p class="plotnchart_-title">
                        Motif frequency across sequences
                    </p>
                    <p class="plotnchart_-subtitle">
                        Number of sequences carrying each PROSITE motif.
                        Bar colour consistent with the domain map above.
                    </p>
                </div>
            </div>
            <canvas id="motif-freq-chart" height="140"
                    aria-label="Motif frequency bar chart"></canvas>
        </div>

        <!--
            Motif summary table
            A more detailed breakdown of each motif: counts, coverage,
            position range, and total hits. I made the PROSITE ID a link
            so people can click through to the original documentation.
            The table is sortable by clicking the column headers.
        -->
        <h2 style="font-size:1.1rem;
                    margin-bottom:var(--mid_space);">
            Motif summary
        </h2>

        <?php if ($scanDone && $motifSummary): ?>
        <div class="motif_tablewrap">
            <table class="motif_table" id="motif-summary-table">
                <thead>
                    <tr>
                        <th data-col="motif_id">PROSITE ID</th>
                        <th data-col="motif_name">Motif name</th>
                        <th data-col="seq_count">Sequences</th>
                        <th data-col="coverage">Coverage</th>
                        <th data-col="position">Position range</th>
                        <th data-col="total_hits">Total hits</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($motifSummary as $motif):
                    $coverage = $totalSeqs > 0 ? round($motif['seq_count'] / $totalSeqs * 100, 1) : 0;
                ?>
                    <tr>
                        <td>
                            <a class="prosiet_id_link"
                               href="https://prosite.expasy.org/<?= htmlspecialchars($motif['motif_id']) ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               title="View <?= htmlspecialchars($motif['motif_id']) ?> on PROSITE">
                                <?= htmlspecialchars($motif['motif_id']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($motif['motif_name']) ?></td>
                        <td>
                            <?= (int)$motif['seq_count'] ?>
                            <span style="color:var(--grey_text_colour);
                                         font-size:0.78rem;">
                                / <?= $totalSeqs ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;
                                         align-items:center;
                                         gap:var(--smol_space);">
                                <div class="coverage_bartrack">
                                    <span class="coverage_barfill"
                                          style="width:<?= $coverage ?>%;"></span>
                                </div>
                                <span style="font-size:0.78rem;
                                             color:var(--grey_text_colour);
                                             min-width:36px;">
                                    <?= $coverage ?>%
                                </span>
                            </div>
                        </td>
                        <td style="font-family:var(--font_code);
                                   font-size:0.82rem;
                                   color:var(--grey_text_colour);">
                            <?= (int)$motif['earliest_pos'] ?>
                            &ndash;
                            <?= (int)$motif['latest_pos'] ?>
                        </td>
                        <td><?= (int)$motif['total_hits'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="download_button_row" style="margin-bottom:var(--bigger_space);">
            <a class="download_button"
               href="<?= $jobWeb ?>/motif_results.txt" download>
                motif_results.txt
            </a>
            <a class="download_button"
               id="dl-motif-tsv"
               href="#"
               download="motif_summary.tsv">
                motif_summary.tsv
            </a>
        </div>

        <!--
            Per-sequence accordion
            This was a late addition after I realised the summary table wasn't
            enough for people who wanted to see exactly which motifs hit which
            sequences. I limit it to 50 sequences to keep the page from getting
            too huge, and I load the actual hit details lazily when you click.
            The lazy loading saves a ton of bandwidth when the dataset is large.
        -->
        <h2 style="font-size:1.1rem;
                    margin-bottom:var(--mid_space);">
            Per-sequence motif detail
        </h2>

        <div class="perseqquenc_accordion" id="seq-accordion">
        <?php foreach ($hitsBySeq as $seqRow): ?>
            <div class="perseqquenc_accordion_header"
                 data-seq-id="<?= (int)$seqRow['seq_id'] ?>">
                <div class="perseqquenc_accordion_left">
                    <em style="font-size:0.875rem;">
                        <?= htmlspecialchars($seqRow['organism']) ?>
                    </em>
                    <span style="font-family:var(--font_code);
                                 font-size:0.78rem;
                                 color:var(--grey_text_colour);">
                        <?= htmlspecialchars($seqRow['accession']) ?>
                    </span>
                </div>
                <div style="display:flex;
                             align-items:center;
                             gap:var(--mid_space);
                             font-size:0.78rem;
                             color:var(--grey_text_colour);">
                    <span>
                        <?= (int)$seqRow['hit_count'] ?>
                        hit<?= $seqRow['hit_count'] != 1 ? 's' : '' ?>
                    </span>
                    <span style="max-width:240px;
                                 overflow:hidden;
                                 text-overflow:ellipsis;
                                 white-space:nowrap;">
                        <?= htmlspecialchars($seqRow['motif_list'] ?? '') ?>
                    </span>
                </div>
                <i class="fa-solid fa-chevron-right perseqquenc_accordion_chevron"></i>
            </div>
            <div class="perseqquenc_accordion_body"
                 id="acc-body-<?= (int)$seqRow['seq_id'] ?>">
                <p style="padding:var(--mid_space);
                           color:var(--grey_text_colour);
                           font-size:0.82rem;"
                   class="acc-loading">
                    Loading hits&hellip;
                </p>
            </div>
        <?php endforeach; ?>
        </div>

        <?php elseif ($scanDone): ?>
        <!--
            No hits found
            This happens sometimes, especially with poorly characterised families.
            I try to be helpful rather than just showing an empty table.
        -->
        <div class="whups_no_hits_message">
            <p>
                No PROSITE motif hits were found in this sequence set.
            </p>
            <p style="font-size:0.82rem; margin-top:var(--smol_space);">
                This can happen if the protein family has no characterised
                PROSITE patterns, or if the sequences diverge significantly
                from the pattern consensus. Consider enabling "weak matches"
                and re-running the scan.
            </p>
        </div>
        <?php endif; ?>

        <!--
            Biological interpretation
            A bit of guidance on what to do next. I found that users often
            get to this step and don't know what the results actually mean,
            so I added this to point them in the right direction.
        -->
        <?php if ($scanDone): ?>
        <div class="biologica_callout" style="margin-bottom:var(--bigger_space);">
            <p>
                <strong>Next steps:</strong>
                Cross-reference any motif hits with the conservation plot
                from the previous step. Motifs that fall within highly-conserved
                alignment regions provide strong convergent evidence for functional importance.
                For each PROSITE entry, follow the link to read the original
                experimental characterisation and confirm the biological meaning
                of the pattern in your protein family.
            </p>
            <p>
                Proceed to
                <a href="extras.php?job_id=<?= $jobId ?>"
                   style="color:var(--primary_colour);">
                    Extra Analyses
                </a>
                for 3D structure cross-references, physicochemical properties,
                and pathway context that will further contextualise the motifs
                identified here.
            </p>
        </div>
        <?php endif; ?>

        <!--
            Proceed row
            Navigation buttons to move forward or backward in the pipeline.
            I keep these at the bottom so you don't have to scroll back up
            to find the next step.
        -->
        <div class="proceed_row">
            <a href="extras.php?job_id=<?= $jobId ?>"
               class="button_primary">
                Proceed to Extra Analyses
            </a>
            <button class="button_outline"
                    id="rerun-btn" type="button">
                Re-run with different options
            </button>
            <a href="analysis.php?job_id=<?= $jobId ?>"
               class="button_outline">
                Back to conservation
            </a>
        </div>

    </section>

    <!--
        Hidden fields
        These hold data that the JavaScript needs to do its job.
        The motif summary JSON gets embedded here too -> saves an API call.
	
	Code adapted from: https://www.php.net/manual/en/function.json-encode.php
			   https://stackoverflow.com/questions/2390312/json-encoding-for-script-tag
    -->
    <input type="hidden" id="active-job-id" value="<?= $jobId ?>">
    <input type="hidden" id="scan-done"
           value="<?= $scanDone ? '1' : '0' ?>">
    <input type="hidden" id="total-seqs"
           value="<?= $totalSeqs ?>">

    <script id="motif-summary-data" type="application/json">
        <?= json_encode($motifSummary, JSON_HEX_TAG | JSON_HEX_AMP) ?>
    </script>

</main>

    <!--
        Hidden fields
        These hold data that the JavaScript needs to do its job.
        The motif summary JSON gets embedded here too -> saves an API call.
    -->
    <input type="hidden" id="active-job-id" value="<?= $jobId ?>">
    <input type="hidden" id="scan-done"
           value="<?= $scanDone ? '1' : '0' ?>">
    <input type="hidden" id="total-seqs"
           value="<?= $totalSeqs ?>">

    <script id="motif-summary-data" type="application/json">
        <?= json_encode($motifSummary, JSON_HEX_TAG | JSON_HEX_AMP) ?>
    </script>

</main>


<!--
    Footer
    Same footer across all pages. I kept the GitHub link here because
    a few people asked where to report bugs.
-->
<footer class="site_footer">
    <div class="container footer_inner">
        <p class="footer_brand">
            <strong>ALiHS</strong> | A Little Intelligent Homology Searcher
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
    JavaScript
    main.js handles the nav hamburger and the canvas motif map renderer.
    It listens for a custom event called 'alihs:load-motif-map' to know
    when to draw the canvas version. The inline script below handles
    everything else: running the scan, polling for progress, the Chart.js
    bar chart, the per-sequence accordion with lazy loading, table sorting,
    and TSV download generation.
    
    I originally tried putting all of this in main.js but it got way too
    messy with all the page-specific stuff. So the generic bits live in
    main.js and the motifs-page-specific stuff lives here.
    
    DOMContentLoaded wrapper code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
						https://developer.mozilla.org/en-US/docs/Glossary/IIFE
-->
<script src="../assets/js/main.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    (function () {
        'use strict';

        const jobId = parseInt(document.getElementById('active-job-id').value);
        const scanDone = document.getElementById('scan-done').value === '1';
        const totalSeqs = parseInt(document.getElementById('total-seqs').value) || 0;
        const runBtn = document.getElementById('run-btn');
        const rerunBtn = document.getElementById('rerun-btn');
        const optSection = document.getElementById('options-section');
        const statusBar = document.getElementById('job-status-bar');
        const progressBar = document.getElementById('progress-bar');
        const statusMsg = document.getElementById('status_message');
        const resultsSection = document.getElementById('results-section');
        const errorBanner = document.getElementById('error-banner');
        const errorMsg = document.getElementById('error-message');

        console.log('Motif script loaded, runBtn found:', !!runBtn);

        /** 
         *  Colour palette for motifs
         *  I wanted consistent colours across the domain map and the bar chart,
         *  so I assign a colour to each motif ID as it appears. This way the
         *  same motif always has the same colour on both visualisations.
         *  The palette is hardcoded -> I just picked colours that look decent
         *  next to each other without being too jarring.
         */
        const MOTIF_COLOURS = [
            '#1a6b72','#e8a020','#2e7d4f','#9a3a60','#3a5aa0',
            '#c05a20','#5a3a9a','#2a7a5a','#a05a1a','#1a5a8a',
            '#8a1a60','#5a8a20','#3a3a8a','#8a5a1a','#1a8a5a',
        ];
        const motifColourMap = {};

        function getMotifColour(motifId) {
            if (!motifColourMap[motifId]) {
                const idx = Object.keys(motifColourMap).length % MOTIF_COLOURS.length;
                motifColourMap[motifId] = MOTIF_COLOURS[idx];
            }
            return motifColourMap[motifId];
        }

        /**
         *  Run button handler
         *  Posts to the server to start the scan. I disable the button
         *  immediately so people don't click it twice by accident.
         *  The progress bar just does a fake animation -> I couldn't figure
         *  out how to get real progress from the PROSITE scan.
         */
        if (runBtn) {
            console.log('Attaching click handler to run button');
            runBtn.addEventListener('click', function () {
                console.log('Run button clicked!');
                hideError();
                this.disabled = true;
                this.innerHTML = 'Submitting…';
                
                statusBar.classList.add('visible');
                setProgress(10, 'Preparing sequences for scan…');
            
                const formData = new FormData();
                formData.append('job_id', jobId);
                if (document.getElementById('include_weak')?.checked) {
                    formData.append('include_weak', '1');
                }
            
                fetch('motifs.php?action=run', {
                    method: 'POST',
                    body: formData,
                })
                .then(r => r.json())
                .then(data => {
                    console.log('Response:', data);
                    if (data.error) throw new Error(data.error);
                    setProgress(100, 'Scan complete. Loading results…');
                    setTimeout(() => {
                        window.location.href = 'motifs.php?job_id=' + jobId;
                    }, 600);
                })
                .catch(err => {
                    console.error('Error:', err);
                    showError(err.message || 'Scan failed. Please try again.');
                    statusBar.classList.remove('visible');
                    runBtn.disabled = false;
                    runBtn.innerHTML = 'Run Motif Scan';
                });
            });
        } else {
            console.error('Run button not found in DOM!');
        }

        /**
         *  Re-run button
         *  Hides the results and shows the options form again.
         *  Simple but effective.
         */
        if (rerunBtn) {
            rerunBtn.addEventListener('click', function () {
                resultsSection.classList.remove('visible');
                optSection.style.display = '';
                if (runBtn) {
                    runBtn.disabled = false;
                    runBtn.innerHTML = 'Run Motif Scan';
                }
            });
        }

        /**
         * Chart.js frequency bar chart
         * Reads the motif summary from the embedded JSON and draws a bar chart.
         * I spent way too long getting the colours to match the domain map,
         * but it was worth it in the end.
	 *
	 * Code adapted from: https://www.chartjs.org/docs/latest/
	 * 		      https://www.chartjs.org/docs/latest/configuration/tooltip.html#tooltip-callbacks
	 * 		      https://www.chartjs.org/docs/latest/developers/plugins.html
	 *		      https://www.chartjs.org/docs/latest/axes/
	 * 		      https://www.chartjs.org/docs/latest/axes/styling.html
         */
        const freqCanvas = document.getElementById('motif-freq-chart');
        if (freqCanvas) {
            const summaryEl = document.getElementById('motif-summary-data');
            let summary = [];
            try {
                summary = JSON.parse(summaryEl?.textContent || '[]');
            } catch (e) { /* no data yet, chart will stay empty */ }

            if (summary.length > 0) {
                const labels  = summary.map(m => m.motif_name || m.motif_id);
                const counts  = summary.map(m => parseInt(m.seq_count));
                const colours = summary.map(m => getMotifColour(m.motif_id));

                new Chart(freqCanvas, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Sequences with motif',
                            data: counts,
                            backgroundColor: colours,
                            borderRadius: 3,
                        }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: ctx => {
                                        const pct = totalSeqs > 0
                                            ? ((ctx.raw / totalSeqs) * 100).toFixed(1)
                                            : '—';
                                        return `${ctx.raw} sequences (${pct}% coverage)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: totalSeqs,
                                title: {
                                    display: true,
                                    text: 'Number of sequences',
                                    font: { size: 11 },
                                },
                                ticks: { stepSize: 1 },
                            },
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    font: { size: 11 },
                                }
                            }
                        },
                    },
                });
            } else {
                freqCanvas.style.display = 'none';
            }
        }

        /**
         *  Canvas motif map
         *  If there's no pre-generated SVG, I dispatch an event that tells
         *  main.js to render the canvas version using the motif hits data.
         *  This is a fallback for smaller jobs or when SVG generation fails.
         */
        if (scanDone && !document.querySelector('#motif-map-wrapper svg')) {
            document.dispatchEvent(new CustomEvent('alihs:load-motif-map', {
                detail: { jobId }
            }));
        }

        /**
         * Per-sequence accordion with lazy loading
         * This was a bit tricky. When you click a sequence header, I check
         * if its body already has content. If not, I fetch the full hit data
         * and filter it to just that sequence, then render a table.
         * I originally loaded everything at once but that was too slow for
         * datasets with hundreds of sequences -> this lazy approach works much better.
	 *
	 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/Performance/Lazy_loading
	 *		      https://developer.mozilla.org/en-US/docs/Web/API/Element/querySelector
         */
        document.querySelectorAll('.perseqquenc_accordion_header').forEach(header => {
            header.addEventListener('click', function () {
                const seqId = this.dataset.seqId;
                const bodyId = 'acc-body-' + seqId;
                const body = document.getElementById(bodyId);
                const isOpen = body && body.classList.contains('open');

                document.querySelectorAll('.perseqquenc_accordion_body').forEach(b =>
                    b.classList.remove('open')
                );
                document.querySelectorAll('.perseqquenc_accordion_header').forEach(h =>
                    h.classList.remove('open')
                );

                if (!isOpen && body) {
                    body.classList.add('open');
                    this.classList.add('open');

                    if (body.querySelector('.acc-loading')) {
                        fetch(`motifs.php?action=get_motifs&job_id=${jobId}`)
                        .then(r => r.json())
                        .then(data => {
                            const seqHits = (data.hits || []).filter(
                                h => String(h.seq_id) === String(seqId)
                            );
                            renderHitTable(body, seqHits);
                        })
                        .catch(() => {
                            body.innerHTML =
                                '<p style="padding:var(--mid_space);' +
                                'color:var(--danger_colour);font-size:0.82rem;">' +
                                'Failed to load hit detail.</p>';
                        });
                    }
                }
            });
        });

        function renderHitTable(container, hits) {
            if (!hits.length) {
                container.innerHTML =
                    '<p style="padding:var(--mid_space);' +
                    'color:var(--grey_text_colour);font-size:0.82rem;">' +
                    'No hits for this sequence.</p>';
                return;
            }

            let html = '<table class="hit_table_perseqquenc_accordion">' +
                '<thead><tr>' +
                '<th>Motif</th>' +
                '<th>PROSITE ID</th>' +
                '<th>Start</th>' +
                '<th>End</th>' +
                '<th>Length (aa)</th>' +
                '</tr></thead><tbody>';

            hits.forEach(h => {
                const colour = getMotifColour(h.motif_id);
                const len    = (parseInt(h.end_pos) - parseInt(h.start_pos) + 1);
                html +=
                    `<tr>
                        <td>
                            <span class="motif_colour_dot"
                                  style="background:${colour};
                                         margin-right:6px;"></span>
                            ${escHtml(h.motif_name)}
                        </td>
                        <td>
                            <a class="prosiet_id_link"
                               href="https://prosite.expasy.org/${escHtml(h.motif_id)}"
                               target="_blank" rel="noopener noreferrer">
                               ${escHtml(h.motif_id)}
                            </a>
                        </td>
                        <td>${h.start_pos}</td>
                        <td>${h.end_pos}</td>
                        <td>${len}</td>
                    </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        /**
         * Motif table column sorting
         * Click on any column header to sort. I keep track of the current
         * sort column and direction. Not the most sophisticated but it works.
	 *
	 * Code adapted from:   https://stackoverflow.com/questions/3160279/sort-html-table-with-javascript
	 * 			https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/isNaN
         */
        const motifTable = document.getElementById('motif-summary-table');
        if (motifTable) {
            let sortCol = 'seq_count';
            let sortDir = -1;

            motifTable.querySelectorAll('th[data-col]').forEach(th => {
                th.addEventListener('click', function () {
                    const col = this.dataset.col;
                    if (col === sortCol) {
                        sortDir *= -1;
                    } else {
                        sortCol = col;
                        sortDir = -1;
                    }
                    sortMotifTable(motifTable, col, sortDir);

                    motifTable.querySelectorAll('th').forEach(t =>
                        t.style.opacity = '1'
                    );
                    this.style.opacity = '0.7';
                });
            });
        }

        function sortMotifTable(table, col, dir) {
            const tbody = table.querySelector('tbody');
            const rows  = Array.from(tbody.querySelectorAll('tr'));
            const colIndex = {
                motif_id: 0,
                motif_name: 1,
                seq_count: 2,
                coverage: 2,
                position: 4,
                total_hits: 5,
            }[col] ?? 2;

	   /**
	    * ? and ?? code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Optional_chaining
	    * 				  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Nullish_coalescing
	    */

            rows.sort((a, b) => {
                const aText = a.cells[colIndex]?.textContent.trim() ?? ''; 
                const bText = b.cells[colIndex]?.textContent.trim() ?? '';
                const aNum = parseFloat(aText);
                const bNum = parseFloat(bText);
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return (aNum - bNum) * dir;
                }
                return aText.localeCompare(bText) * dir;
            });

            rows.forEach(r => tbody.appendChild(r));
        }

        /**
         * TSV download
         * Builds a TSV file from the motif summary data and triggers a download.
         * I added this after a user requested a machine-readable format for
         * downstream analysis. Took about ten minutes to implement but my friends seem
         * to appreciate it.
	 * 
	 * Code adapted from:   https://developer.mozilla.org/en-US/docs/Web/API/Blob
	 *			https://developer.mozilla.org/en-US/docs/Web/API/URL/createObjectURL
	 *			https://developer.mozilla.org/en-US/docs/Web/API/URL/revokeObjectURL
         */
        const dlTsvBtn = document.getElementById('dl-motif-tsv');
        if (dlTsvBtn) {
            dlTsvBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const summaryEl = document.getElementById('motif-summary-data');
                let summary = [];
                try {
                    summary = JSON.parse(summaryEl?.textContent || '[]');
                } catch (ex) { return; }

                const header = [
                    'motif_id', 'motif_name', 'seq_count',
                    'coverage_pct', 'earliest_pos', 'latest_pos', 'total_hits'
                ].join('\t');

                const rows = summary.map(m => {
                    const cov = totalSeqs > 0
                        ? (parseInt(m.seq_count) / totalSeqs * 100).toFixed(1)
                        : '0';
                    return [
                        m.motif_id, m.motif_name, m.seq_count,
                        cov, m.earliest_pos, m.latest_pos, m.total_hits
                    ].join('\t');
                });

                const tsv  = [header, ...rows].join('\n');
                const blob = new Blob([tsv], { type: 'text/tab-separated-values' });
                const url  = URL.createObjectURL(blob);
                const a    = document.createElement('a');
                a.href     = url;
                a.download = `motif_summary_job${jobId}.tsv`;
                a.click();
                URL.revokeObjectURL(url);
            });
        }

        /**
         * Helper functions
         * Nothing fancy here -> just progress bar updates, error messages,
         * and HTML escaping to prevent XSS.
	 *
	 * Code adapted from:   https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
	 *			https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace
         */
        function setProgress(pct, msg) {
            if (progressBar) progressBar.style.width = pct + '%';
            if (statusMsg) statusMsg.textContent = msg;
        }
        
        function showError(msg) {
            if (errorMsg) errorMsg.textContent = msg;
            if (errorBanner) errorBanner.classList.add('visible');
        }
        
        function hideError() {
            if (errorBanner) errorBanner.classList.remove('visible');
        }
        
        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

    }());
});
</script>

</body>
</html>
