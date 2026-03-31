<?php
/**
 * pages/fetch.php —> Sequence Retrieval
 *
 * This is the main handler for the sequence retrieval step. It wears a few
 * different hats depending on what the frontend asks for:
 *
 * GET (no action) -> render the retrieval form,
 * or if a job_id is provided, pre-load an existing completed job.
 *
 * POST ?action=submit -> create a new job, shell out to pipeline.py --task fetch,
 * insert sequences into the database via PDO, then return JSON.
 *
 * GET ?action=poll -> return job status as JSON so the frontend poller knows
 * when the pipeline is done.
 *
 * GET ?action=get_sequences -> return the sequence list as JSON for the table
 * renderer.
 *
 * POST ?action=exclude -> soft-exclude selected seq_ids via PDO (sets excluded=1).
 *
 * All ?action= branches return JSON and exit immediately.
 * Only the bare GET actually renders HTML, or preloads results if a job_id is
 * present.
 */

require_once __DIR__ . '/../config.php';
ini_set('display_errors', 0);
error_reporting(0);

/**
 * Action: poll -> return current job status
 *
 * Called every couple of seconds by the job_poller in main.js.
 * I originally had this checking the filesystem for status files,
 * but moving to database polling was way more reliable and let me
 * keep everything in one place. Returns 404 if the job doesn't exist,
 * though in practice that shouldn't happen unless the user messes with
 * the URL.
 *
 * Code adapted from: https://www.w3schools.com/php/php_mysql_prepared_statements.asp
 *		      https://www.php.net/manual/en/json.constants.php
 * 
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'poll') {

    $jobId = (int) ($_GET['job_id'] ?? 0);

    if ($jobId <= 0) {
        jsonResponse(['error' => 'Invalid job_id.'], 400);
    }

    $stmt = $pdo->prepare(
        'SELECT j.status, j.protein_family, j.taxonomic_group,
                COUNT(s.seq_id) AS seq_count
         FROM   jobs j
         LEFT JOIN sequences s ON s.job_id = j.job_id
         WHERE  j.job_id = ?
         GROUP  BY j.job_id'
    );
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['error' => 'Job not found.'], 404);
    }

    jsonResponse([
        'status' => $row['status'],
        'protein_family' => $row['protein_family'],
        'taxon' => $row['taxonomic_group'],
        'seq_count' => (int) $row['seq_count'],
    ]);
}


/**
 * Action: get_sequences -> return the sequence list for the table
 *
 * Grabs everything about the sequences for a given job, ordered by organism
 * because that's what made the most sense for browsing. The frontend table
 * handles sorting client-side anyway, so the order here doesn't matter much
 * beyond being consistent. I tried adding pagination here at first but
 * decided the frontend was better equipped for that kind of interactivity.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'get_sequences') {

    $jobId = (int) ($_GET['job_id'] ?? 0);

    if ($jobId <= 0) {
        jsonResponse(['error' => 'Invalid job_id.'], 400);
    }

    $stmt = $pdo->prepare(
        'SELECT seq_id, accession, organism, taxon_id,
                description, length, order_name, excluded
         FROM sequences
         WHERE job_id = ?
         ORDER BY organism ASC'
    );
    $stmt->execute([$jobId]);
    $sequences = $stmt->fetchAll();

    jsonResponse(['sequences' => $sequences]);
}


/**
 * Action: exclude -> soft-exclude selected sequences
 *
 * This one took a couple of iterations to get right. The frontend sends a
 * JSON payload with job_id and an array of seq_ids to exclude. I clear all
 * exclusions for the job first, then set excluded=1 for the ones that were
 * unchecked. It's a bit heavy-handed but it guarantees the state matches
 * exactly what the user sees in the table. Learned the hard way that
 * incremental updates could get out of sync with the UI.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_GET['action'] ?? '') === 'exclude') {

    $body  = json_decode(file_get_contents('php://input'), true);
    $jobId = (int) ($body['job_id'] ?? 0);
    $ids   = (array) ($body['seq_ids'] ?? []);

    if ($jobId <= 0) {
        jsonResponse(['error' => 'Invalid job_id.'], 400);
    }

    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });

    $clearStmt = $pdo->prepare(
        'UPDATE sequences SET excluded = 0 WHERE job_id = ?'
    );
    $clearStmt->execute([$jobId]);

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $setStmt = $pdo->prepare(
            "UPDATE sequences SET excluded = 1
             WHERE  job_id = ? AND seq_id IN ($placeholders)"
        );
        $setStmt->execute(array_merge([$jobId], $ids));
    }

    jsonResponse(['success' => true, 'excluded_count' => count($ids)]);
}


/**
 * Action: submit -> create job and fetch sequences
 *
 * This is the heavy lifter. It validates inputs, builds the NCBI Entrez
 * query string, creates a job record, then shells out to pipeline.py
 * with --task fetch. The Python script does the actual fetching via
 * BioPython Entrez, which I found much easier than trying to handle
 * all the NCBI logic in PHP. The output is JSON, which I then parse
 * and stuff into the database via PDO.
 *
 * I used to do this entirely in Python and just have PHP as a thin
 * wrapper, but keeping the job records and sequences in the database
 * made the whole status polling system much simpler. It also means
 * I can do soft-exclusions without re-fetching anything.
 *
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_GET['action'] ?? '') === 'submit') {

    $proteinFamily = trim($_POST['protein_family'] ?? '');
    $taxon = trim($_POST['taxon']          ?? '');
    $maxSeqs = (int) ($_POST['max_seqs']     ?? 50);
    $minLen = (int) ($_POST['min_length']   ?? MIN_SEQ_LENGTH);
    $maxLen = (int) ($_POST['max_length']   ?? MAX_SEQ_LENGTH);
    $refseqOnly = isset($_POST['refseq_only']) ? 1 : 0;

    if ($proteinFamily === '' || $taxon === '') {
        jsonResponse(['error' => 'Protein family and taxonomic group are required.'], 400);
    }

    $maxSeqs = max(1, min($maxSeqs, MAX_SEQUENCES));
    $minLen = max(MIN_SEQ_LENGTH, $minLen);
    $maxLen = min(MAX_SEQ_LENGTH, $maxLen);

    $queryParts = [
        '"' . $proteinFamily . '"[Protein Name]',
        '"' . $taxon . '"[Organism]',
    ];
    if ($refseqOnly) {
        $queryParts[] = 'refseq[filter]';
    }
    if ($minLen > MIN_SEQ_LENGTH || $maxLen < MAX_SEQ_LENGTH) {
        $queryParts[] = $minLen . ':' . $maxLen . '[Sequence Length]';
    }
    $ncbiQuery = implode(' AND ', $queryParts);

    $sessionToken = $_COOKIE[SESSION_COOKIE] ?? null; /* Code adapted from: https://developer.mozilla.org/en-US/docs/Web/HTTP/Cookies */

    if ($sessionToken) {
        $uStmt = $pdo->prepare(
            'INSERT IGNORE INTO users (session_token) VALUES (?)'
        );
        $uStmt->execute([$sessionToken]);
    }

    $insertJob = $pdo->prepare(
        'INSERT INTO jobs
            (session_token, protein_family, taxonomic_group,
             ncbi_query_string, status, is_example)
         VALUES (?, ?, ?, ?, "queued", 0)'
    );
    $insertJob->execute([$sessionToken, $proteinFamily, $taxon, $ncbiQuery]);
    $jobId = (int) $pdo->lastInsertId();

    try {
        $jobDir = createJobDir($jobId);
    } catch (RuntimeException $e) {
        jsonResponse(['error' => 'Could not create results directory: ' . $e->getMessage()], 500);
    }

    $pdo->prepare('UPDATE jobs SET status = "running" WHERE job_id = ?')
        ->execute([$jobId]);

    /**
     * Code adapted from: https://www.php.net/manual/en/function.shell-exec.php
     */
    
    $cmd = sprintf(
        '%s %s --task fetch --job_id %d --results_dir %s ' .
        '--protein_family %s --taxon %s --max_seqs %d ' .
        '--min_length %d --max_length %d --ncbi_query %s ' .
        '--api_key %s --email %s',
        escapeshellarg(PYTHON_BIN),
        escapeshellarg(PIPELINE_SCRIPT),
        $jobId,
        escapeshellarg($jobDir),
        escapeshellarg($proteinFamily),
        escapeshellarg($taxon),
        $maxSeqs,
        $minLen,
        $maxLen,
        escapeshellarg($ncbiQuery),
        escapeshellarg(NCBI_API_KEY),
        escapeshellarg(NCBI_EMAIL)
    );

    /**
     * Code adapted from: https://www.php.net/manual/en/class.runtimeexception.php
     */
    try {
        $stdout = runCommand($cmd);
    } catch (RuntimeException $e) {
        $pdo->prepare('UPDATE jobs SET status = "failed" WHERE job_id = ?')
            ->execute([$jobId]);
        jsonResponse(['error' => 'Pipeline fetch failed: ' . $e->getMessage()], 500);
    }

    $result = json_decode($stdout, true);

    if (!$result || !isset($result['sequences'])) {
        $pdo->prepare('UPDATE jobs SET status = "failed" WHERE job_id = ?')
            ->execute([$jobId]);
        jsonResponse(['error' => 'Pipeline returned invalid output.'], 500);
    }

    $insertSeq = $pdo->prepare(
        'INSERT INTO sequences
            (job_id, accession, organism, taxon_id,
             description, sequence, length, order_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($result['sequences'] as $seq) {
        $insertSeq->execute([
            $jobId,
            $seq['accession'] ?? '',
            $seq['organism'] ?? '',
            $seq['taxon_id'] ?? null,
            $seq['description'] ?? '',
            $seq['sequence'] ?? '',
            $seq['length'] ?? 0,
            $seq['order_name'] ?? null,
        ]);
    }

    $pdo->prepare(
        'UPDATE jobs
         SET status = "done",
             num_sequences = ?,
             completed_at = NOW(),
             session_dir = ?
         WHERE job_id = ?'
    )->execute([count($result['sequences']), $jobDir, $jobId]);

    jsonResponse([
        'success' => true,
        'job_id' => $jobId,
        'seq_count' => count($result['sequences']),
        'fasta_path' => $jobDir . '/sequences.fasta',
    ]);
}


/**
 * HTML Render -> bare GET with no action parameter
 *
 * If a job_id is provided in the URL, I assume the user is returning
 * to a completed job and pre-load the results section. This avoids
 * the flicker of seeing the form then having it hide itself after JS loads.
 * I spent way too long chasing a white flash on page reload before
 * settling on this approach.
 */
$returnJobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : null;
$returnJob = null;

if ($returnJobId) {
    $stmt = $pdo->prepare(
        'SELECT * FROM jobs WHERE job_id = ? AND status = "done"'
    );
    $stmt->execute([$returnJobId]);
    $returnJob = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fetch Sequences — <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>

    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/fetch.css">
</head>

<body>

<!--
    Navigation
    Same site header and nav structure as the rest of the pages.
    The active class on the New Analysis dropdown indicates where we are.
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
    Main content area -> step breadcrumb, retrieval form, status bar, results.
    I kept the padding consistent with analysis.php so everything feels
    like it belongs to the same application.
-->
<main class="container" style="padding-top: var(--big_space); padding-bottom: var(--bigger_space);">

    <!--
        Step breadcrumb
        Shows the user where they are in the pipeline. The active class
        highlights the current step. I debated whether to make these links
        functional when the step isn't available yet, but decided against it
        to avoid confusing workflows.
    -->
    <nav class="step_breaddcrum" aria-label="Pipeline steps">
        <span class="step_breaddcrum_item active">1. Fetch Sequences</span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item">2. Conservation Analysis</span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item">3. Motif Scanning</span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item">4. Extra Analyses</span>
    </nav>

    <h1 style="margin-bottom: var(--smol_space);">Fetch Sequences</h1>
    <p style="color: var(--grey_text_colour); margin-bottom: var(--big_space); max-width: 60ch;">
        Define a protein family and a taxonomic group. ALiHS will query NCBI Entrez
        and retrieve all matching protein sequences for your analysis.
    </p>


    <!--
        Error banner
        Hidden by default, becomes visible when something goes wrong.
        role="alert" ensures screen readers announce it immediately.
    -->
    <div class="errror_banner" id="error-banner" role="alert">
        <span id="error-message">An error occurred. Please try again.</span>
    </div>


    <!--
        Retrieval form
        This is the main input area. JS hides it once a job is submitted
        and results are ready to show. I originally kept it visible but that
        just confused people who tried to submit multiple jobs at once.
    -->
    <section id="fetch-form-section">

        <div style="background: var(--card_background_colour);
                    border: 1px solid var(--border_colour);
                    border-radius: var(--bigger_corner_rounding);
                    padding: var(--big_space);
                    margin-bottom: var(--mid_space);">

            <div class="fetch_form_griddy">

                <div class="feildd_group">
                    <label for="protein_family">
                        Protein family / keyword
                        <span style="color: var(--danger_colour);">*</span>
                    </label>
                    <input type="text"
                           id="protein_family"
                           name="protein_family"
                           placeholder="e.g. glucose-6-phosphatase"
                           autocomplete="off"
                           required>
                    <span class="feildd_hint">
                        Used as <code>[Protein Name]</code> in the Entrez query.
                    </span>
                </div>

                <div class="feildd_group">
                    <label for="taxon">
                        Taxonomic group
                        <span style="color: var(--danger_colour);">*</span>
                    </label>
                    <input type="text"
                           id="taxon"
                           name="taxon"
                           placeholder="e.g. Aves, Mammalia, Rodentia"
                           autocomplete="off"
                           required>
                    <span class="feildd_hint">
                        Used as <code>[Organism]</code> in the Entrez query.
                    </span>
                </div>

            </div>

            <!--
                Live query preview
                Updates as the user types so they can see exactly what's being
                sent to NCBI. I added this after realising most users don't
                know how Entrez queries are constructed.
            -->
            <div style="margin-bottom: var(--mid_space);">
                <p style="font-size: 0.8rem; font-weight: 600;
                           color: var(--grey_text_colour);
                           margin-bottom: var(--smol_space);
                           letter-spacing: 0.04em;
                           text-transform: uppercase;">
                    NCBI Entrez query preview
                </p>
                <div class="live_query_preview" id="query-preview">
                    Start typing above to see your query&hellip;
                </div>
            </div>

            <!--
                Advanced options toggle
                Hides the more technical parameters to keep the form approachable.
                I used to have everything visible by default but that just
                overwhelmed new users.
            -->
            <button class="advvancd_options_toggle"
                    id="advanced-toggle"
                    type="button"
                    aria-expanded="false"
                    aria-controls="advanced-panel">
                Advanced options
            </button>

            <div class="advvancd_options_panel" id="advanced-panel" role="region">
                <div class="advvancd_options_grid">

                    <div class="feildd_group">
                        <label for="max_seqs">Maximum sequences</label>
                        <div class="renge_slidey_wrapper">
                            <input type="range"
                                   id="max_seqs"
                                   name="max_seqs"
                                   min="10"
                                   max="<?= MAX_SEQUENCES ?>"
                                   step="10"
                                   value="50">
                            <span class="renge_slidey_value" id="max_seqs_val">50</span>
                        </div>
                        <span class="feildd_hint">Max: <?= MAX_SEQUENCES ?></span>
                    </div>

                    <div class="feildd_group">
                        <label for="min_length">Min sequence length (aa)</label>
                        <input type="number"
                               id="min_length"
                               name="min_length"
                               min="<?= MIN_SEQ_LENGTH ?>"
                               max="<?= MAX_SEQ_LENGTH ?>"
                               value="<?= MIN_SEQ_LENGTH ?>">
                    </div>

                    <div class="feildd_group">
                        <label for="max_length">Max sequence length (aa)</label>
                        <input type="number"
                               id="max_length"
                               name="max_length"
                               min="<?= MIN_SEQ_LENGTH ?>"
                               max="<?= MAX_SEQ_LENGTH ?>"
                               value="<?= MAX_SEQ_LENGTH ?>">
                    </div>

                    <div class="feildd_group">
                        <label>RefSeq sequences only</label>
                        <div class="refseq_toggle_row">
                            <label class="refseq_toggle_switch" aria-label="RefSeq only">
                                <input type="checkbox" id="refseq_only" name="refseq_only">
                                <span class="refseq_toggle_slider"></span>
                            </label>
                            <span class="feildd_hint" style="margin: 0;">
                                Restricts results to curated RefSeq entries
                            </span>
                        </div>
                    </div>

                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: var(--mid_space);">
                <button class="button button_primary" id="submit-btn" type="button">
                    Retrieve Sequences
                </button>
            </div>

        </div>

    </section>


    <!--
        Job status bar
        Shown while the pipeline is running. The progress bar is mostly for show
        because the actual progress isn't linear, but users seem to prefer seeing
        something moving rather than just a spinner.
    -->
    <div class="job_status_bar" id="job-status-bar" role="status" aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>
        <div class="progress_track">
            <div class="progress_bar" id="progress-bar"></div>
        </div>
        <span id="status-message">Connecting to NCBI&hellip;</span>
    </div>


    <!--
        Results section
        Hidden until the job completes. If returning to a completed job,
        I pre-populate this server-side to avoid the flash of the form.
    -->
    <section class="rezults_section" id="results-section">

        <h2 style="margin-bottom: var(--mid_space);">
            Sequences retrieved
            <span id="results-taxon"
                  style="font-style: italic;
                         font-weight: 400;
                         color: var(--grey_text_colour);
                         font-size: 1rem;">
            </span>
        </h2>

        <div class="summ_stats_cards">
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-total">—</div>
                <div class="summ_stats_label">Sequences</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-species">—</div>
                <div class="summ_stats_label">Species</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-min-len">—</div>
                <div class="summ_stats_label">Min length (aa)</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-max-len">—</div>
                <div class="summ_stats_label">Max length (aa)</div>
            </div>
            <div class="summ_stats_card">
                <div class="summ_stats_value" id="stat-mean-len">—</div>
                <div class="summ_stats_label">Mean length (aa)</div>
            </div>
        </div>

        <div class="chart_container">
            <p class="chart_title">
                Sequence length distribution
            </p>
            <canvas id="length-chart" height="120"></canvas>
        </div>

        <div class="tablee_toolbar">
            <div style="display: flex; gap: var(--mid_space); align-items: center; flex-wrap: wrap;">
                <input type="text"
                       class="tablee_search"
                       id="table-search"
                       placeholder="Filter by organism or accession&hellip;"
                       aria-label="Filter sequences">
                <span class="selection_countz" id="selection-count">
                    <strong id="included-count">0</strong> sequences selected for analysis
                </span>
            </div>
            <div style="display: flex; gap: var(--smol_space);">
                <button class="button button_outline" id="select-all-btn" type="button" style="font-size: 0.85rem; padding: 0.4rem 0.9rem;">
                    Select all
                </button>
                <button class="button button_outline" id="deselect-all-btn" type="button" style="font-size: 0.85rem; padding: 0.4rem 0.9rem;">
                    Deselect all
                </button>
            </div>
        </div>

        <div class="sequence_tablee_wrapper">
            <table class="sequence_table" id="seq-table" aria-label="Retrieved sequences">
                <thead>
                    <tr>
                        <th style="width: 36px;">
                            <input type="checkbox"
                                   id="check-all"
                                   title="Select / deselect all"
                                   aria-label="Select all">
                        </th>
                        <th data-col="accession">Accession</th>
                        <th data-col="organism">Organism</th>
                        <th data-col="order_name">Order</th>
                        <th data-col="length">Length (aa)</th>
                        <th data-col="description">Description</th>
                    </tr>
                </thead>
                <tbody id="seq-table-body">
                    <!-- main.js populates this dynamically from the JSON -->
                </tbody>
            </table>
        </div>

        <p style="font-size: 0.8rem; color: var(--grey_text_colour); margin-bottom: var(--mid_space);">
            Uncheck any sequences you wish to exclude from downstream analyses.
            Excluded sequences remain stored but will not be aligned or scanned.
        </p>

        <div class="proceed_row">
            <button class="button button_primary" id="confirm-selection-btn" type="button">
                Confirm selection &amp; proceed to analysis
            </button>
            <button class="button button_outline" id="new-search-btn" type="button">
                Start a new search
            </button>
            <span id="confirm-feedback"
                  style="font-size: 0.85rem;
                         color: var(--success_colour);
                         display: none;">
                Selection saved.
            </span>
        </div>

    </section>

    <!--
        Hidden field holds the active job_id so main.js can reference it
        without parsing the URL every time. I originally did URL parsing
        but that got messy when the hash changed for tabs.
    -->
    <input type="hidden"
           id="active-job-id"
           value="<?= htmlspecialchars((string)($returnJobId ?? '')) ?>">

</main>


<!--
    Footer
    Same footer structure as the rest of the site -> keeps things consistent.
-->
<footer class="site_footer">
    <div class="container footer_inner">
        <p class="footer_brand"><strong><?= SITE_NAME ?></strong> | A Little intelligent Homology Searcher</p>
        <nav class="footer_navi" aria-label="Footer navigation">
            <a href="../index.php">Home</a>
            <a href="fetch.php">New Analysis</a>
            <a href="example.php">Example</a>
            <a href="revisit.php">My Sessions</a>
            <a href="help.php">Help</a>
            <a href="about.php">About</a>
            <a href="credits.php">Credits</a>
            <a href="<?= GITHUB_URL ?>" target="_blank" rel="noopener noreferrer">GitHub</a>
        </nav>
    </div>
</footer>


<!--
    Javascript
    main.js contains the shared modules for job polling, the sequence table,
    and the length histogram. The inline script below handles the
    fetch-page-specific interactions: form validation, live query preview,
    advanced panel toggle, and wiring up the submit button.
    I tried putting all of this in main.js initially but it got too
    specialised, so the page-specific glue lives here.
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /* Element references -> grab everything up front so I don't have to keep querying */
    const proteinInput = document.getElementById('protein_family');
    const taxonInput = document.getElementById('taxon');
    const queryPreview = document.getElementById('query-preview');
    const advToggle = document.getElementById('advanced-toggle');
    const advPanel = document.getElementById('advanced-panel');
    const maxSeqsSlider = document.getElementById('max_seqs');
    const maxSeqsVal = document.getElementById('max_seqs_val');
    const refseqCheck = document.getElementById('refseq_only');
    const minLenInput = document.getElementById('min_length');
    const maxLenInput = document.getElementById('max_length');
    const submitBtn = document.getElementById('submit-btn');
    const formSection = document.getElementById('fetch-form-section');
    const statusBar = document.getElementById('job-status-bar');
    const progressBar = document.getElementById('progress-bar');
    const statusMsg = document.getElementById('status-message');
    const resultsSection = document.getElementById('results-section');
    const errorBanner = document.getElementById('error-banner');
    const errorMsg = document.getElementById('error-message');
    const newSearchBtn = document.getElementById('new-search-btn');
    const confirmBtn = document.getElementById('confirm-selection-btn');
    const confirmFeedback = document.getElementById('confirm-feedback');
    const activeJobInput = document.getElementById('active-job-id');

    /* Advanced panel toggle -> simple class toggling, aria attributes for accessibility */
    advToggle.addEventListener('click', function () {
        const isOpen = advPanel.classList.toggle('open');
        this.setAttribute('aria-expanded', String(isOpen));
    });

    /* Max sequences slider -> update the displayed value as it moves */
    maxSeqsSlider.addEventListener('input', function () {
        maxSeqsVal.textContent = this.value;
        updatePreview();
    });

    /*
     * Live query preview -> builds the Entrez query from the current form values.
     * I wrote this after realising that most users don't know what the actual
     * query string looks like. It helped a lot with debugging weird searches.
     */
    function updatePreview() {
        const family = proteinInput.value.trim();
        const taxon = taxonInput.value.trim();
        const maxSeqs = maxSeqsSlider.value;
        const minLen = minLenInput.value;
        const maxLen = maxLenInput.value;
        const refseq = refseqCheck.checked;

        if (!family && !taxon) {
            queryPreview.textContent = 'Start typing above to see your query\u2026';
            return;
        }

        let parts = [];
        if (family) parts.push(`"${family}"[Protein Name]`);
        if (taxon) parts.push(`"${taxon}"[Organism]`);
        if (refseq) parts.push('refseq[filter]');

        const defaultMin = <?= MIN_SEQ_LENGTH ?>;
        const defaultMax = <?= MAX_SEQ_LENGTH ?>;
        if (parseInt(minLen) > defaultMin || parseInt(maxLen) < defaultMax) {
            parts.push(`${minLen}:${maxLen}[Sequence Length]`);
        }

        queryPreview.textContent = parts.join(' AND ') + `  [retmax=${maxSeqs}]`;
    }

    [proteinInput, taxonInput, minLenInput, maxLenInput].forEach(el => {
        el.addEventListener('input', updatePreview);
    });
    refseqCheck.addEventListener('change', updatePreview);

    /*
     * Form submission -> POST to fetch.php?action=submit
     * I used to do this with XMLHttpRequest but fetch is so much cleaner.
     * The progress bar here is mostly cosmetic -> the actual polling
     * takes over once the job is created.
     */
    submitBtn.addEventListener('click', function () {

        const family = proteinInput.value.trim();
        const taxon = taxonInput.value.trim();

        if (!family || !taxon) {
            showError('Please enter both a protein family and a taxonomic group.');
            return;
        }

        hideError();

        const formData = new FormData();
        formData.append('protein_family', family);
        formData.append('taxon', taxon);
        formData.append('max_seqs', maxSeqsSlider.value);
        formData.append('min_length', minLenInput.value);
        formData.append('max_length', maxLenInput.value);
        if (refseqCheck.checked) formData.append('refseq_only', '1');

        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Submitting\u2026';

        statusBar.classList.add('visible');
        setProgress(10, 'Submitting query to NCBI\u2026');

        fetch('fetch.php?action=submit', {
            method: 'POST',
            body: formData,
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            activeJobInput.value = data.job_id;
            setProgress(30, 'Fetching sequences from NCBI\u2026');
            startPolling(data.job_id);
        })
        .catch(err => {
            showError(err.message || 'Submission failed. Please try again.');
            resetSubmitButton();
            statusBar.classList.remove('visible');
        });
    });

    /**
     * Polling loop -> checks job status every couple of seconds.
     * I originally set this to 1 second but that hammered the server
     * for no real benefit, so 2 seconds feels like a good balance.
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/setInterval
     * 			  https://stackoverflow.com/questions/43039016/how-to-implement-polling-in-javascript
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Promise
     *                    https://javascript.info/promise-chaining
     *			  https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API
     */
    let pollInterval = null;

    function startPolling(jobId) {
        let ticks = 0;
        pollInterval = setInterval(() => {
            ticks++;
            const fakeProgress = Math.min(30 + ticks * 3, 85); /* Code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Template_literals */
            setProgress(fakeProgress, statusMessages[ticks % statusMessages.length]);

            fetch(`fetch.php?action=poll&job_id=${jobId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'done') {
                    clearInterval(pollInterval);
                    setProgress(100, 'Sequences retrieved successfully.');
                    setTimeout(() => {
                        statusBar.classList.remove('visible');
                        loadResults(jobId);
                    }, 600);
                } else if (data.status === 'failed') {
                    clearInterval(pollInterval);
                    showError('Sequence retrieval failed. The NCBI query may have returned no results.');
                    resetSubmitButton();
                    statusBar.classList.remove('visible');
                }
            })
            .catch(() => { /* network hiccup -> just keep polling, it'll sort itself out */ });
        }, 2000);
    }

    const statusMessages = [
        'Querying NCBI Entrez\u2026',
        'Fetching FASTA sequences\u2026',
        'Parsing taxonomy data\u2026',
        'Storing sequences\u2026',
        'Almost there\u2026',
    ];

    function setProgress(pct, msg) {
        progressBar.style.width = pct + '%';
        statusMsg.textContent   = msg;
    }

    /*
     * Load results after the job completes -> fetch the sequence list
     * and dispatch a custom event so main.js knows to render the table and chart.
     * I considered having the PHP render the table directly but that would
     * have made sorting and filtering much harder to implement.
     * 
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/CustomEvent
     * 			  https://developer.mozilla.org/en-US/docs/Web/API/EventTarget/dispatchEvent
     */
    function loadResults(jobId) {
        fetch(`fetch.php?action=get_sequences&job_id=${jobId}`)
        .then(r => r.json())
        .then(data => {
            if (data.error || !data.sequences) {
                showError(data.error || 'Could not load sequence data.');
                return;
            }
            window.__ALiHSJobId    = jobId;
            window.__ALiHSSequences = data.sequences;

            document.dispatchEvent(new CustomEvent('alihs:sequences-loaded', {
                detail: { jobId, sequences: data.sequences }
            }));

            updateStats(data.sequences);

            formSection.style.display = 'none';
            resultsSection.classList.add('visible');
        })
        .catch(() => showError('Could not load sequence data. Please refresh and try again.'));
    }

    /**
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Destructuring_assignment
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Set
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/reduce
     */

    function updateStats(sequences) {
        const lengths = sequences.map(s => parseInt(s.length) || 0);
        const total = sequences.length;
        const species = new Set(sequences.map(s => s.organism)).size;
        const minLen = Math.min(...lengths);
        const maxLen = Math.max(...lengths);
        const meanLen = Math.round(lengths.reduce((a, b) => a + b, 0) / total);

        document.getElementById('stat-total').textContent = total;
        document.getElementById('stat-species').textContent = species;
        document.getElementById('stat-min-len').textContent = minLen;
        document.getElementById('stat-max-len').textContent = maxLen;
        document.getElementById('stat-mean-len').textContent = meanLen;

        const taxon = taxonInput.value.trim();
        document.getElementById('results-taxon').textContent = taxon ? '— ' + taxon : '';

        document.getElementById('included-count').textContent = total;
    }

    /* New search button -> hides results and shows the form again */
    newSearchBtn.addEventListener('click', function () {
        resultsSection.classList.remove('visible');
        formSection.style.display = '';
        activeJobInput.value = '';
        resetSubmitButton();
    });

    /*
     * Confirm selection -> sends excluded seq_ids to the server.
     * I initially tried sending the included ones but it felt more natural
     * to store the ones the user unchecks as excluded, and keep everything
     * else included by default.
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Element/classList
     * 			  https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/dataset
     */
    confirmBtn.addEventListener('click', function () {
        const jobId = parseInt(activeJobInput.value);
        if (!jobId) return;

        const unchecked = Array.from(
            document.querySelectorAll('#seq-table-body input[type="checkbox"]:not(:checked)')
        ).map(cb => parseInt(cb.dataset.seqId));

        fetch('fetch.php?action=exclude', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ job_id: jobId, seq_ids: unchecked }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                confirmFeedback.style.display = 'inline-flex';
                confirmBtn.disabled = true;
                setTimeout(() => {
                    window.location.href = `analysis.php?job_id=${jobId}`;
                }, 800);
            } else {
                showError(data.error || 'Could not save selection.');
            }
        })
        .catch(() => showError('Network error. Please try again.'));
    });

    /*
     * If we're returning to a completed job (job_id in URL), load the results
     * immediately without showing the form. This was a late addition but it
     * made the back-button experience so much better.
     */
    const preloadJobId = parseInt(activeJobInput.value);
    if (preloadJobId > 0) {
        formSection.style.display = 'none';
        loadResults(preloadJobId);
    }

    function showError(msg) {
        errorMsg.textContent = msg;
        errorBanner.classList.add('visible');
    }
    function hideError() {
        errorBanner.classList.remove('visible');
    }
    function resetSubmitButton() {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Retrieve Sequences';
    }

}());
</script>

</body>
</html>
