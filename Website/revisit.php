<?php
/**
 * pages/revisit.php -> revisit previous sessions
 *
 * This is the handler for returning users who want to pick up where they left off.
 * It does a few different things depending on how it's called:
 *
 * GET (no action) -> render the session token form,
 * auto-populated if a cookie is already present in the browser
 *
 * GET ?action=get_jobs -> validate token, query the job summary view,
 * return a JSON array of job cards
 *
 * POST ?action=rename -> update jobs.label via PDO, return JSON
 *
 * POST ?action=delete -> soft-delete a job by setting status to 'deleted',
 * return JSON
 *
 * Example jobs (is_example = 1) and deleted jobs (status = 'deleted')
 * are always excluded from listings.
 *
 * All the ?action= branches return JSON and exit immediately.
 * Only the bare GET actually renders any HTML.
 *
 * Example patterns and code adapted from fetch, analysis, motifs, extras.php:
 * Job cards grid layout
 * Status badges with icons
 * Pipeline progress dots
 * Mini statistics cards
 * Inline editable labels
 */

require_once __DIR__ . '/../config.php';


/**
 * Action: get_jobs -> return job list for a session token
 *
 * Queries the job summary view for all jobs matching the given token.
 * The view joins jobs, sequences, alignments, and motif_hits into one
 * summary row per job -> much cleaner than doing it all inline here.
 * Returns 400 if no token was provided, which realistically shouldn't
 * happen but I'd rather handle it cleanly than let it blow up.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'get_jobs') {

    $token = trim($_GET['token'] ?? '');

    if ($token === '') {
        jsonResponse(['error' => 'No session token provided.'], 400);
    }

    /**
     * Query v_job_summary for this token.
     * The view joins jobs, sequences (count), alignments (avg_identity),
     * and motif_hits (count) into one summary row per job.
     * Fall back to a direct join if the view isn't created yet.
     *
     * Code adapted from: https://www.w3schools.com/sql/sql_coalesce.asp
     */
    $stmt = $pdo->prepare(
        'SELECT j.job_id,
                j.protein_family,
                j.taxonomic_group,
                j.status,
                j.label,
                j.created_at,
                j.completed_at,
                j.num_sequences,
                COALESCE(a.avg_identity, 0) AS avg_identity,
                COALESCE(a.alignment_length, 0) AS alignment_length,
                COALESCE(mh.motif_count, 0) AS motif_count,
                COALESCE(cs.mean_score, 0) AS mean_conservation
         FROM jobs j
         LEFT JOIN (
             SELECT job_id,
                    avg_identity,
                    alignment_length
             FROM alignments
         ) a ON a.job_id = j.job_id
         LEFT JOIN (
             SELECT job_id,
                    COUNT(*) AS motif_count
             FROM motif_hits
             GROUP BY job_id
         ) mh ON mh.job_id = j.job_id
         LEFT JOIN (
             SELECT al.job_id,
                    AVG(cs.conservation_score) AS mean_score
             FROM conservation_scores cs
             JOIN alignments al ON al.alignment_id = cs.alignment_id
             GROUP BY al.job_id
         ) cs ON cs.job_id = j.job_id
         WHERE j.session_token = ?
           AND j.is_example = 0
           AND j.status != "deleted"
         ORDER BY j.created_at DESC'
    );
    $stmt->execute([$token]);
    $jobs = $stmt->fetchAll();

    jsonResponse(['jobs' => $jobs]);
}


/**
 * Action: rename -> update job label
 *
 * Expects a JSON POST body with job_id, label, and token.
 * I verify ownership before touching anything -> just setting status to 403
 * if the token doesn't match, which is the right call here since
 * label updates are a write operation.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_GET['action'] ?? '') === 'rename') {

    $body  = json_decode(file_get_contents('php://input'), true);
    $jobId = (int) ($body['job_id'] ?? 0);
    $label = trim($body['label'] ?? '');
    $token = trim($body['token'] ?? '');

    if ($jobId <= 0 || $token === '') {
        jsonResponse(['error' => 'Invalid parameters.'], 400);
    }

    /** 
     * Clamp label length -> 100 chars feels reasonable
     *
     * Code adapted from: https://www.php.net/manual/en/function.mb-substr.php
     */
    $label = mb_substr($label, 0, 100);

    /* Verify the job actually belongs to this session token before updating */
    $checkStmt = $pdo->prepare(
        'SELECT job_id FROM jobs
         WHERE job_id = ? AND session_token = ? AND is_example = 0'
    );
    $checkStmt->execute([$jobId, $token]);
    if (!$checkStmt->fetch()) {
        jsonResponse(['error' => 'Job not found or access denied.'], 403);
    }

    $pdo->prepare(
        'UPDATE jobs SET label = ? WHERE job_id = ?'
    )->execute([$label ?: null, $jobId]);

    jsonResponse(['success' => true, 'job_id' => $jobId, 'label' => $label]);
}


/**
 * Action: delete -> soft-delete a job
 *
 * Expects a JSON POST body with job_id and token.
 * This is a soft delete -> just flips status to 'deleted' rather than
 * actually removing the row. Means I can recover data if something goes
 * wrong, and it keeps the query logic consistent everywhere else.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_GET['action'] ?? '') === 'delete') {

    $body  = json_decode(file_get_contents('php://input'), true);
    $jobId = (int) ($body['job_id'] ?? 0);
    $token = trim($body['token'] ?? '');

    if ($jobId <= 0 || $token === '') {
        jsonResponse(['error' => 'Invalid parameters.'], 400);
    }

    /* Verify ownership before soft-deleting */
    $checkStmt = $pdo->prepare(
        'SELECT job_id FROM jobs
         WHERE job_id = ? AND session_token = ? AND is_example = 0'
    );
    $checkStmt->execute([$jobId, $token]);
    if (!$checkStmt->fetch()) {
        jsonResponse(['error' => 'Job not found or access denied.'], 403);
    }

    $pdo->prepare(
        'UPDATE jobs SET status = "deleted" WHERE job_id = ?'
    )->execute([$jobId]);

    jsonResponse(['success' => true, 'job_id' => $jobId]);
}


/**
 * Html render -> bare GET, no action parameter
 *
 * Check whether a session token cookie exists. If it does,
 * pre-populate the token field and signal JS to auto-load jobs on
 * page load -> saves one click for returning users.
 */
$cookieToken = $_COOKIE[SESSION_COOKIE] ?? '';
$hasToken = $cookieToken !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sessions — <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/revisit.css">
</head>

<body>

<!--
    Navigation
    Standard site header -> same structure as the rest of the pages.
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
            <li><a href="revisit.php" class="navi_link active">My Sessions</a></li>
            <li><a href="help.php" class="navi_link">Help</a></li>
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
    Main content area -> token input, job cards, empty state.
-->
<main class="container"
      style="padding-top: var(--giant_space);
             padding-bottom: var(--giant_space);">

    <h1 style="margin-bottom: var(--smol_space);">My Sessions</h1>
    <p style="color: var(--grey_text_colour);
               margin-bottom: var(--big_space);
               max-width: 60ch;">
        Retrieve and re-explore the analyses previously run.
        The session token is stored in the browser -> enter it below
        to view saved jobs.
    </p>


    <!-- Message banners -> error and info, shown/hidden by JS as needed -->
    <div class="message_banner error" id="error-banner" role="alert">
        <span id="error-message">An error occurred.</span>
    </div>
    <div class="message_banner info" id="info-banner" role="status">
        <span id="info-message"></span>
    </div>


    <!--
        Token input section
        If a cookie token was found server-side, the input gets pre-populated
        and JS will auto-trigger loadJobs on page load -> no manual click needed.
    -->
    <section class="token_input_section">
        <div class="token_input_card">

            <h2>Enter your session token</h2>
            <p style="font-size:0.875rem;
                       color:var(--grey_text_colour);
                       margin-bottom:0;">
                The token is set automatically on first use and stored in a
                browser cookie. If the field below is already filled, it was
                found automatically.
            </p>

            <div class="token_input_row">
                <input type="text"
                       class="token_input"
                       id="token-input"
                       placeholder="e.g. a3f8c2e1d9b7…"
                       autocomplete="off"
                       spellcheck="false"
                       aria-label="Session token"
                       value="<?= htmlspecialchars($cookieToken) ?>">
                <button class="button button_primary"
                        id="load-btn"
                        type="button">
                    Load my jobs
                </button>
            </div>

            <!-- Token helper row -> shows whether a cookie token was found -->
            <div class="token-reveal_helper_row">
                <?php if ($hasToken): ?>
                <span class="token_hint">
                    Session token found in the browser.
                </span>
                <?php else: ?>
                <span class="token_hint">
                    No session token found in this browser.
                    Start a new analysis to create one.
                </span>
                <?php endif; ?>

                <button class="copy_button" id="copy-token-btn"
                        type="button" title="Copy token to clipboard"
                        <?= !$hasToken ? 'style="display:none;"' : '' ?>>
                    Copy token
                </button>
            </div>

            <!-- Warning about losing the token -> worth being upfront about this -->
            <div class="session_token_info_box">
                <span>
                    The token is stored only in the browser cookie.
                    Clearing cookies or switching browsers means entering it manually.
                    Save it somewhere safe to keep long-term access to these sessions.
                </span>
            </div>

        </div>
    </section><!-- /.token_input_section -->


    <!-- Loading indicator -> visible while the get_jobs fetch is in flight -->
    <div class="still_loading_stuff" id="loading-row" role="status"
         aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>
        <span>Loading your sessions&hellip;</span>
    </div>


    <!--
        Jobs section -> rendered entirely by JS after get_jobs returns.
        The toolbar and grid are both in here; cards get injected into job-grid.
    -->
    <section class="jobs_section" id="jobs-section">

        <!-- Toolbar -> filter, sort, status filter controls -->
        <div class="jobs_toolbar">
            <p class="how_many_jobs" id="jobs-count">
                Loading&hellip;
            </p>
            <div class="jobs_controls">
                <input type="text"
                       class="controls_input"
                       id="filter-input"
                       placeholder="Filter by protein or taxon&hellip;"
                       aria-label="Filter jobs">
                <select class="controls_input"
                        id="sort-select"
                        aria-label="Sort jobs">
                    <option value="date_desc">Newest first</option>
                    <option value="date_asc">Oldest first</option>
                    <option value="protein_az">Protein A–Z</option>
                    <option value="taxon_az">Taxon A–Z</option>
                    <option value="seqs_desc">Most sequences</option>
                </select>
                <select class="controls_input"
                        id="status-filter"
                        aria-label="Filter by status">
                    <option value="all">All statuses</option>
                    <option value="done">Done</option>
                    <option value="running">Running</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
        </div>

        <!-- Job cards grid -> populated by JS after renderJobs runs -->
        <div class="job_cards_grid" id="job-grid" role="list">
            <!-- Cards injected here -->
        </div>

    </section><!-- /#jobs-section -->


    <!--
        Empty state -> shown when the token is valid but has no jobs under it.
        Kept separate from the jobs section so the two don't fight each other
        over visibility.
    -->
    <section id="empty-section" style="display:none; text-align:center;
                                        padding:var(--giant_space) 0;">
        <h2 style="color:var(--grey_text_colour);
                    margin-bottom:var(--smol_space);">
            No sessions found
        </h2>
        <p style="color:var(--grey_text_colour);
                   max-width:40ch;
                   margin:0 auto var(--big_space);">
            No analyses have been run under this session token yet.
        </p>
        <a href="fetch.php" class="button button_primary">
            Start your first analysis
        </a>
    </section>

    <!-- Store token for JS use -> read on load to decide whether to auto-fire loadJobs -->
    <input type="hidden" id="stored-token"
           value="<?= htmlspecialchars($cookieToken) ?>">

</main>


<!--
    Footer
    Same footer as the rest of the site -> kept consistent so nothing feels out of place.
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
    Javascript
    main.js handles the nav hamburger. The inline script below handles everything
    specific to this page -> load button, job card rendering, filter/sort/status controls,
    inline label renaming via AJAX, soft delete with confirmation overlay, copy token
    to clipboard, and auto-load when a cookie token is present. I tried moving some of
    this into main.js at one point but it got messy fast, so page-specific stuff stays here.
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /* Element references -> grab everything up front so nothing blows up later */
    const tokenInput = document.getElementById('token-input');
    const loadBtn = document.getElementById('load-btn');
    const loadingRow = document.getElementById('loading-row');
    const jobsSection = document.getElementById('jobs-section');
    const emptySection = document.getElementById('empty-section');
    const jobGrid = document.getElementById('job-grid');
    const jobsCount = document.getElementById('jobs-count');
    const filterInput = document.getElementById('filter-input');
    const sortSelect = document.getElementById('sort-select');
    const statusFilter = document.getElementById('status-filter');
    const errorBanner = document.getElementById('error-banner');
    const errorMsg = document.getElementById('error-message');
    const infoBanner = document.getElementById('info-banner');
    const infoMsg = document.getElementById('info-message');
    const copyBtn = document.getElementById('copy-token-btn');
    const storedToken = document.getElementById('stored-token').value;

    /* All loaded jobs (unfiltered) -> kept in memory so filter/sort don't need to re-fetch */
    let allJobs = [];

    /** 
     * Copy token to clipboard -> falls back to a manual-copy message if the API isn't available
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Clipboard_API
     * 			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Promise/catch
     */
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const val = tokenInput.value.trim();
            if (!val) return;
            navigator.clipboard.writeText(val)
                .then(() => showInfo('Token copied to clipboard.'))
                .catch(() => showInfo('Could not copy — please select and copy manually.'));
        });
    }

    /* Load button click -> fire loadJobs */
    loadBtn.addEventListener('click', loadJobs);

    /* Also submit on Enter in the token input -> small quality of life thing */
    tokenInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') loadJobs();
    });

    /** 
     * Auto-load if a cookie token was found server-side -> no point making the user click
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Statements/if...else
     */
    if (storedToken.trim() !== '') {
        loadJobs();
    }


    /* Load jobs from server -> GET ?action=get_jobs with the current token */
    function loadJobs() {
        const token = tokenInput.value.trim();
        if (!token) {
            showError('Please enter a session token.');
            return;
        }

        hideError();
        hideInfo();
        loadingRow.classList.add('visible');
        jobsSection.classList.remove('visible');
        emptySection.style.display = 'none';

	/**
	 * Just for encoding the URL safely, code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/encodeURIComponent
	 */
        fetch(`revisit.php?action=get_jobs&token=${encodeURIComponent(token)}`)
        .then(r => r.json())
        .then(data => {
            loadingRow.classList.remove('visible');

            if (data.error) {
                showError(data.error);
                return;
            }

            allJobs = data.jobs || [];

            if (allJobs.length === 0) {
                emptySection.style.display = 'block';
                jobsSection.classList.remove('visible');
                return;
            }

            renderJobs(allJobs);
            jobsSection.classList.add('visible');
        })
        .catch(() => {
            loadingRow.classList.remove('visible');
            showError('Failed to load sessions. Please try again.');
        });
    }


    /* Render job cards -> applies current filter, sort, and status filter then injects HTML */
    function renderJobs(jobs) {
        const query = filterInput.value.trim().toLowerCase();
        const sort = sortSelect.value;
        const statusF = statusFilter.value;

        /* Filter */
        let filtered = jobs.filter(j => {
            if (statusF !== 'all' && j.status !== statusF) return false;
            if (query) {
                const haystack = [
                    j.protein_family, j.taxonomic_group, j.label
                ].join(' ').toLowerCase();
                if (!haystack.includes(query)) return false;
            }
            return true;
        });

        /* Sort */
        filtered.sort((a, b) => {
            switch (sort) {
                case 'date_asc':
                    return new Date(a.created_at) - new Date(b.created_at);
                case 'protein_az':
                    return a.protein_family.localeCompare(b.protein_family);
                case 'taxon_az':
                    return a.taxonomic_group.localeCompare(b.taxonomic_group);
                case 'seqs_desc':
                    return (parseInt(b.num_sequences) || 0)
                         - (parseInt(a.num_sequences) || 0);
                default: /* date_desc */
                    return new Date(b.created_at) - new Date(a.created_at);
            }
        });

        /* Update count label */
        const total = jobs.length;
        const shown = filtered.length;
        jobsCount.innerHTML =
            `Showing <strong>${shown}</strong> of ` +
            `<strong>${total}</strong> session${total !== 1 ? 's' : ''}`;

        /* Render cards or show a no-match message */
        if (filtered.length === 0) {
            jobGrid.innerHTML =
                '<div class="empty_state">' +
                '<h3>No matching sessions</h3>' +
                '<p>Try adjusting your filter or status selection.</p>' +
                '</div>';
            return;
        }

        jobGrid.innerHTML = filtered.map(j => buildCard(j)).join('');

        /* Attach card event listeners after rendering -> has to happen after innerHTML is set */
        attachCardListeners();
    }


    /* Build a single job card HTML string -> called by renderJobs for each job in the filtered list */
    function buildCard(job) {
        const jobId = parseInt(job.job_id);
        const status = job.status || 'unknown';
        const label = escHtml(job.label || '');
        const protein = escHtml(job.protein_family || '—');
        const taxon = escHtml(job.taxonomic_group || '—');
        const seqs = parseInt(job.num_sequences) || 0;
        const identity = parseFloat(job.avg_identity) || 0;
        const motifs = parseInt(job.motif_count) || 0;
        const createdAt = formatDate(job.created_at);
        const token = escHtml(tokenInput.value.trim());

        /* Status badge -> maps status string to CSS class and icon */
        const badgeClass = {
            done: 'status_done',
            running: 'status_running',
            failed: 'status_failed',
            queued: 'status_queued',
        }[status] || 'status_queued';

        const badgeIcon = {
            done: 'fa-circle-check',
            running: 'fa-spinner fa-spin',
            failed: 'fa-circle-xmark',
            queued: 'fa-clock',
        }[status] || 'fa-circle';

        /* Pipeline completion dots -> one per step, filled if that step has results */
        const hasSeqs = seqs > 0;
        const hasAlign = identity > 0;
        const hasMotifs = motifs > 0;
        const hasExtras = parseInt(job.mean_conservation || 0) > 0;

        const dot = (done, label) =>
            `<span class="pipeline_dot ${done ? 'complete' : ''}"
                   title="${label}"></span>`;
        const sep = '<span class="pipeline_separate"></span>';

        const pipelineDots =
            `<div class="pipeline_dots" aria-label="Pipeline progress">
                ${dot(hasSeqs, 'Sequences fetched')}
                ${sep}
                ${dot(hasAlign, 'Alignment done')}
                ${sep}
                ${dot(hasMotifs, 'Motifs scanned')}
                ${sep}
                ${dot(hasExtras, 'Extras run')}
                <span style="margin-left:4px;font-size:0.72rem;">
                    Pipeline steps complete
                </span>
            </div>`;

        /** 
	 * View results URL -> go to the furthest completed pipeline step.
         * Took a few tries to get this logic right; the default fallback
         * for fully-done jobs pointing to analysis.php felt most useful.
	 *
	* Code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Statements/if...else
	 */
        let viewUrl = `fetch.php?job_id=${jobId}`;
        if (hasSeqs && !hasAlign) viewUrl = `fetch.php?job_id=${jobId}`;
        if (hasAlign && !hasMotifs) viewUrl = `analysis.php?job_id=${jobId}`;
        if (hasMotifs) viewUrl = `motifs.php?job_id=${jobId}`;
        if (hasExtras) viewUrl = `extras.php?job_id=${jobId}`;
        /* Default for fully-done jobs */
        if (status === 'done') viewUrl = `analysis.php?job_id=${jobId}`;

        return `
        <article class="job_card" role="listitem"
                 id="card-${jobId}"
                 data-job-id="${jobId}"
                 data-protein="${protein.toLowerCase()}"
                 data-taxon="${taxon.toLowerCase()}"
                 data-status="${status}">

            <!-- Delete confirmation overlay -> shown when the delete button is clicked -->
            <div class="are_you_sure_delete" id="del-confirm-${jobId}">
                <p>Delete job <strong>#${jobId}</strong>?
                   This cannot be undone.</p>
                <div class="are_you_sure_delete_button">
                    <button class="primary_button_card confirm-delete-btn"
                            style="background:var(--danger_colour);
                                   border-color:var(--danger_colour);"
                            data-job-id="${jobId}">
                        Yes, delete
                    </button>
                    <button class="ghosty_button_card cancel-delete-btn"
                            data-job-id="${jobId}">
                        Cancel
                    </button>
                </div>
            </div>

            <!-- Card header -> protein family, taxon, and status badge -->
            <div class="card_header">
                <div style="flex:1; min-width:0;">
                    <p class="card_title">${protein}</p>
                    <p class="card_taxonomy">${taxon}</p>
                </div>
                <span class="status_badge ${badgeClass}">
                    ${status}
                </span>
            </div>

            <!-- Inline label -> editable in-place without a modal -->
            <div class="label_display" id="label-display-${jobId}">
                <span class="label_text" id="label-text-${jobId}">
                    ${label
                        ? label
                        : '<span style="opacity:0.45;">No label</span>'}
                </span>
                <button class="edit_button_label"
                        title="Edit label"
                        data-job-id="${jobId}">
                </button>
            </div>
            <div class="input_row_label" id="label-input-row-${jobId}">
                <input type="text"
                       class="input_label"
                       id="label-input-${jobId}"
                       maxlength="100"
                       value="${label}"
                       placeholder="Add a label&hellip;">
                <button class="save_button_label" data-job-id="${jobId}">Save</button>
                <button class="cancel_button_label" data-job-id="${jobId}">Cancel</button>
            </div>

            <!-- Metadata -> job ID and creation date -->
            <div class="card_metadata">
                <span class="card_metadata-item">
                    #${jobId}
                </span>
                <span class="card_metadata-item">
                    ${createdAt}
                </span>
            </div>

            <!-- Mini stats -> sequences, mean identity, motif hits -->
            <div class="mini_stats">
                <div class="mini_stat">
                    <div class="mini_stat_value">
                        ${seqs > 0 ? seqs.toLocaleString() : '—'}
                    </div>
                    <div class="mini_stat_label">Sequences</div>
                </div>
                <div class="mini_stat">
                    <div class="mini_stat_value">
                        ${identity > 0 ? identity.toFixed(1) + '%' : '—'}
                    </div>
                    <div class="mini_stat_label">Mean identity</div>
                </div>
                <div class="mini_stat">
                    <div class="mini_stat_value">
                        ${motifs > 0 ? motifs : '—'}
                    </div>
                    <div class="mini_stat_label">Motif hits</div>
                </div>
            </div>

            <!-- Pipeline progress -->
            ${pipelineDots}

            <!-- Actions -->
            <div class="card_actions">
                <a href="${viewUrl}"
                   class="primary_button_card">
                    View results
                </a>
                <button class="ghosty_button_card danger_button_card delete-btn"
                        data-job-id="${jobId}"
                        title="Delete this job">
                    Delete
                </button>
            </div>

        </article>`;
    }


    /* Attach event listeners to freshly rendered cards -> must run after every renderJobs call */
    function attachCardListeners() {

        /** 
	 * Delete button -> show confirmation overlay rather than deleting immediately 
	 *
	 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Element/classList
	 */
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.jobId;
                document.getElementById('del-confirm-' + id)
                        ?.classList.add('visible');
            });
        });

        /* Cancel delete -> dismiss the overlay without doing anything */
        document.querySelectorAll('.cancel-delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.jobId;
                document.getElementById('del-confirm-' + id)
                        ?.classList.remove('visible');
            });
        });

        /* Confirm delete -> POST to ?action=delete, then remove from allJobs and re-render */
        document.querySelectorAll('.confirm-delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.dataset.jobId);
                const token = tokenInput.value.trim();

                fetch('revisit.php?action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ job_id: id, token }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        /* Remove from allJobs and re-render -> cleaner than a full reload */
                        allJobs = allJobs.filter(
                            j => parseInt(j.job_id) !== id
                        );
                        renderJobs(allJobs);
                        showInfo('Job #' + id + ' has been deleted.');
                    } else {
                        showError(data.error || 'Delete failed.');
                    }
                })
                .catch(() => showError('Delete request failed.'));
            });
        });

        /** 
	 * Label edit button -> swap display span for the input row 
	 * 
	 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/input_event
	 * 		      https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/focus
	 */
        document.querySelectorAll('.edit_button_label').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.jobId;
                document.getElementById('label-display-' + id)
                        ?.style.setProperty('display', 'none');
                document.getElementById('label-input-row-' + id)
                        ?.classList.add('editing');
                document.getElementById('label-input-' + id)?.focus();
            });
        });

        /* Label cancel -> hide input row and restore display span */
        document.querySelectorAll('.cancel_button_label').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.jobId;
                document.getElementById('label-input-row-' + id)
                        ?.classList.remove('editing');
                document.getElementById('label-display-' + id)
                        ?.style.removeProperty('display');
            });
        });

        /* Label save -> POST to ?action=rename, then update the display span in-place
           without triggering a full re-render, which would be overkill for a label change */
        document.querySelectorAll('.save_button_label').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.dataset.jobId);
                const token = tokenInput.value.trim();
                const label = document.getElementById('label-input-' + id)
                                       ?.value.trim() || '';

                fetch('revisit.php?action=rename', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ job_id: id, label, token }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        /* Update allJobs in memory so the label survives a re-render */
                        const job = allJobs.find(j => parseInt(j.job_id) === id);
                        if (job) job.label = label;

                        /* Update the displayed label text without a full re-render */
                        const labelText = document.getElementById(
                            'label-text-' + id
                        );
                        if (labelText) {
                            labelText.innerHTML = label ||
                                '<span style="opacity:0.45;">No label</span>';
                        }

                        document.getElementById('label-input-row-' + id)
                                ?.classList.remove('editing');
                        document.getElementById('label-display-' + id)
                                ?.style.removeProperty('display');

                        showInfo('Label updated.');
                    } else {
                        showError(data.error || 'Rename failed.');
                    }
                })
                .catch(() => showError('Rename request failed.'));
            });
        });

        /** 
	 * Also save label on Enter key -> and cancel on Escape, same as most inline editors
	 *
	 * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/KeyboardEvent/key
	 */
        document.querySelectorAll('.input_label').forEach(input => {
            input.addEventListener('keydown', function (e) {
                const id = this.id.replace('label-input-', '');
                if (e.key === 'Enter') {
                    document.querySelector(
                        `.save_button_label[data-job-id="${id}"]`
                    )?.click();
                }
                if (e.key === 'Escape') {
                    document.querySelector(
                        `.cancel_button_label[data-job-id="${id}"]`
                    )?.click();
                }
            });
        });
    }


    /* Filter and sort controls -> any change re-runs renderJobs on the full allJobs array */
    [filterInput, sortSelect, statusFilter].forEach(el => {
        if (el) {
            el.addEventListener('input', () => renderJobs(allJobs));
            el.addEventListener('change', () => renderJobs(allJobs));
        }
    });


    /**
     * Helpers -> small utility functions, nothing fancy
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleDateString
     *			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toLocaleTimeString
     */
    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric'
        }) + ' ' + d.toLocaleTimeString('en-GB', {
            hour: '2-digit', minute: '2-digit'
        });
    }

    function showError(msg) {
        errorMsg.textContent = msg;
        errorBanner.classList.add('visible');
        infoBanner.classList.remove('visible');
    }
    function hideError() {
        errorBanner.classList.remove('visible');
    }
    function showInfo(msg) {
        infoMsg.textContent = msg;
        infoBanner.classList.add('visible');
        errorBanner.classList.remove('visible');
        setTimeout(() => infoBanner.classList.remove('visible'), 3000);
    }
    function hideInfo() {
        infoBanner.classList.remove('visible');
    }
    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}());
</script>

</body>
</html>
