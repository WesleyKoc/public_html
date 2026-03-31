<?php
/**
 * pages/extras.php -> supplementary analyses
 *
 * Handles all the extra bells and whistles for the final step of the pipeline.
 * Depending on how it's called, it does a few different things:
 *
 * GET (no action) -> render the tabbed extras interface, pre-populating anything
 * we already have from the DB so it doesn't look empty.
 * POST ?action=run_tab -> dispatch to pipeline.py for whichever tab the user
 * clicked on. Inserts results into extra_analyses or external_links via PDO and
 * returns JSON for that tab.
 * GET ?action=poll -> return job/tab status as JSON.
 *
 * All ?action= branches return JSON and exit immediately. Only the bare GET
 * actually renders any HTML.
 *
 * Seven analysis tabs:
 *   pdb -> 3D Structure cross-reference (PDB + AlphaFold)
 *   uniprot -> UniProt functional annotations
 *   blast -> BLAST contextual similarity (all-vs-all + against nr)
 *   links -> External resource links (KEGG, Reactome, etc.) -> static, no pipeline.
 * I originally tried to do more with the 'links' tab, like scraping, but it got
 * messy fast so I just kept it as static outbound links. Works fine.
 *
 * Example patterns adapted from fetch, analysis, and motifs.php:
 * - JSON response function
 * - Action routing pattern
 * - JSON embedding in script tag
 * - DOMContentLoaded wrapper
 * - Page reload with hash
 * - Lazy loading with conditional content check
 */

require_once __DIR__ . '/../config.php';

/**
 * Valid tab names and their pipeline.py task mappings.
 * Tab G (links) requires no computation -> handled entirely in PHP/HTML from the
 * job's protein_family and taxon fields. Took me a while to settle on this
 * structure but keeping it simple here made the rest of the file much cleaner.
 */
const TAB_TASKS = [
    'pdb' => 'pdb',
    'uniprot' => 'uniprot',
    'blast' => 'blast',
    /* 'links' has no pipeline task */
];

/**
 * Action: poll -> return tab computation status
 *
 * Simple status check -> look for an existing extra_analyses row for this job + tab.
 * Returns 404 if the job_id doesn't exist, which shouldn't happen in normal use
 * but I'd rather handle it than let it explode.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll') {

    $jobId = (int) ($_GET['job_id'] ?? 0);
    $tabName = trim($_GET['tab'] ?? '');

    if ($jobId <= 0) jsonResponse(['error' => 'Invalid job_id.'], 400);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM extra_analyses
         WHERE job_id = ? AND analysis_type = ?'
    );
    $stmt->execute([$jobId, $tabName]);
    $exists = (int) $stmt->fetchColumn() > 0;

    jsonResponse([
        'tab' => $tabName,
        'done' => $exists,
        'job_id' => $jobId,
    ]);
}

/**
 * Action: run_tab -> run a single extras tab via pipeline.py
 *
 * Expects POST fields: job_id, tab.
 * This dispatches to pipeline.py for the requested tab, then persists the results
 * to the database via PDO. Spent a fair bit of time getting the BLAST temp
 * directory cleanup right -> had to make sure it gets wiped even if something
 * fails midway, otherwise /tmp fills up with junk.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'run_tab') {

    $jobId = (int) ($_POST['job_id'] ?? 0);
    $tabName = trim($_POST['tab'] ?? '');

    if ($jobId <= 0)
        jsonResponse(['error' => 'Invalid job_id.'], 400);

    if (!array_key_exists($tabName, TAB_TASKS))
        jsonResponse(['error' => "Unknown tab: $tabName"], 400);

    $jobStmt = $pdo->prepare(
        'SELECT j.session_dir, j.protein_family, j.taxonomic_group,
                COUNT(s.seq_id) AS seq_count
         FROM jobs j
         LEFT JOIN sequences s
               ON s.job_id = j.job_id AND s.excluded = 0
         WHERE j.job_id = ?
         GROUP BY j.job_id'
    );
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job)
        jsonResponse(['error' => 'Job not found.'], 404);

    $jobDir = $job['session_dir'];
    $task = TAB_TASKS[$tabName];

    /* For BLAST, use a temporary directory in /tmp -> need to clean this up later */
    $blastTempDir = null;
    if ($task === 'blast') {
        $blastTempDir = sys_get_temp_dir() . '/alihs_blast_' . $jobId . '_' . time() . '_' . mt_rand(1000, 9999);

        if (!mkdir($blastTempDir, 0777, true)) {
            jsonResponse(['error' => "Failed to create temporary BLAST directory: $blastTempDir"], 500);
        }

        chmod($blastTempDir, 0777);
        error_log("Created BLAST temp directory: $blastTempDir");
    }

    /** 
     * Build the command -> took a few tries to get all the arguments in the right order 
     *
     * Code adapted from: https://stackoverflow.com/questions/3352941/constructing-a-command-in-php
     *			  https://www.php.net/manual/en/function.escapeshellarg.php
     */
    $cmd = sprintf(
        '%s %s --task %s --job_id %d --results_dir %s',
        escapeshellarg(PYTHON_BIN),
        escapeshellarg(PIPELINE_SCRIPT),
        escapeshellarg($task),
        $jobId,
        escapeshellarg($jobDir)
    );

    /* Always pass NCBI credentials -> used by fetch, uniprot, pdb */
    $cmd .= ' --api_key ' . escapeshellarg(NCBI_API_KEY)
          . ' --email ' . escapeshellarg(NCBI_EMAIL);

    /* Add BLAST paths and temp directory if this is a BLAST task */
    if ($task === 'blast') {
        $cmd .= ' --blast_makeblastdb ' . escapeshellarg(MAKEBLASTDB_BIN)
              . ' --blast_blastp ' . escapeshellarg(BLASTP_BIN)
              . ' --blast_temp_dir ' . escapeshellarg($blastTempDir);
    }

    try {
        error_log("Running command: " . $cmd);

        $stdout = runCommand($cmd);

        error_log("Pipeline stdout for task $task: " . substr($stdout, 0, 500));
	
	/**
	 * I'm quite paranoid about the JSON parsing turning out errors, 
	 * so I decided to add some error checking in the parsing
	 *
	 * Code adapted from: https://www.php.net/manual/en/function.json-last-error.php
	 *		      https://www.php.net/manual/en/function.json-last-error-msg.php
	 */
        $result = json_decode($stdout, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            error_log("Raw output: " . $stdout);
            jsonResponse([
                'error' => "Invalid JSON from pipeline: " . json_last_error_msg()
            ], 500);
        }

        /** 
	 * Clean up the temp directory -> recursively delete everything 
	 * 
	 * Code adapted from: https://www.php.net/manual/en/class.recursivedirectoryiterator.php
	 * 		      https://www.php.net/manual/en/class.recursiveiteratoriterator.php
	 *		      https://www.php.net/manual/en/recursiveiteratoriterator.constants.php
	 */
        if ($blastTempDir && is_dir($blastTempDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($blastTempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    rmdir($fileinfo->getRealPath());
                } else {
                    unlink($fileinfo->getRealPath());
                }
            }
            rmdir($blastTempDir);
            error_log("Cleaned up BLAST temp directory: $blastTempDir");
        }

    } catch (RuntimeException $e) {
        error_log("Pipeline error: " . $e->getMessage());

        /* Clean up on error too -> learned this the hard way after a few failed runs */
        if ($blastTempDir && is_dir($blastTempDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($blastTempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
            @rmdir($blastTempDir);
        }

        jsonResponse([
            'error' => "Pipeline task '$task' failed: " . $e->getMessage()
        ], 500);
    }

    if (!$result || isset($result['error'])) {
        jsonResponse([
            'error' => $result['error'] ?? 'Pipeline returned invalid output.'
        ], 500);
    }

    /* Persist results to DB via PDO -> each tab needs slightly different handling */
    switch ($tabName) {

        /* UniProt: external_links + extra_analyses */
        case 'uniprot':
            if (!empty($result['annotations'])) {
                $seqMap = [];
                $seqStmt = $pdo->prepare(
                    'SELECT seq_id, accession FROM sequences WHERE job_id = ? AND excluded = 0'
                );
                $seqStmt->execute([$jobId]);
                while ($row = $seqStmt->fetch()) {
                    $seqMap[$row['accession']] = $row['seq_id'];
                }

                $insLink = $pdo->prepare(
                    'INSERT INTO external_links
                        (seq_id, database_name, external_id,
                         url, annotation_summary)
                     VALUES (?, "UniProt", ?, ?, ?) /* Code adapted from: https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html */
                     ON DUPLICATE KEY UPDATE
                        annotation_summary = VALUES(annotation_summary)'
                );
                $insExtra = $pdo->prepare(
                    'INSERT INTO extra_analyses
                        (job_id, seq_id, analysis_type, result_summary)
                     VALUES (?, ?, "uniprot", ?)
                     ON DUPLICATE KEY UPDATE
                        result_summary = VALUES(result_summary)'
                );

                $pdo->beginTransaction();
                foreach ($result['annotations'] as $annot) {
                    $accession = $annot['accession'];
                    $seq_id = $seqMap[$accession] ?? null;

                    if ($seq_id) {
                        $insLink->execute([
                            $seq_id,
                            $annot['uniprot_id'] ?? '',
                            $annot['url'] ?? '',
                            $annot['summary'] ?? '',
                        ]);
                        $insExtra->execute([
                            $jobId,
                            $seq_id,
                            json_encode($annot),
                        ]);
                    } else {
                        error_log("Could not find seq_id for accession: $accession");
                    }
                }
                $pdo->commit();
            }
            break;

        /* PDB / AlphaFold: external_links */
        case 'pdb':
            if (!empty($result['structures'])) {
                $seqMap = [];
                $seqStmt = $pdo->prepare(
                    'SELECT seq_id, accession FROM sequences WHERE job_id = ? AND excluded = 0'
                );
                $seqStmt->execute([$jobId]);
                while ($row = $seqStmt->fetch()) {
                    $seqMap[$row['accession']] = $row['seq_id'];
                }

                $ins = $pdo->prepare(
                    'INSERT INTO external_links
                        (seq_id, database_name, external_id,
                         url, annotation_summary)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        annotation_summary = VALUES(annotation_summary)'
                );

                $pdo->beginTransaction();
                foreach ($result['structures'] as $str) {
                    $accession = $str['accession'];
                    $seq_id = $seqMap[$accession] ?? null;

                    if ($seq_id) {
                        $ins->execute([
                            $seq_id,
                            $str['database'] ?? 'PDB',
                            $str['structure_id'] ?? '',
                            $str['url'] ?? '',
                            $str['summary'] ?? '',
                        ]);
                    } else {
                        error_log("Could not find seq_id for accession: $accession");
                    }
                }
                $pdo->commit();
            }
            break;

        /* BLAST: blast_results table */
        case 'blast':
            if (!empty($result['hits'])) {
                $pdo->prepare('DELETE FROM blast_results WHERE job_id = ?')
                    ->execute([$jobId]);

                $ins = $pdo->prepare(
                    'INSERT INTO blast_results
                        (job_id, query_accession, hit_accession,
                         hit_description, hit_organism,
                         pct_identity, evalue, bitscore)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $pdo->beginTransaction();
                $inserted = 0;
                foreach ($result['hits'] as $hit) {
                    $ins->execute([
                        $jobId,
                        $hit['query_accession'] ?? '',
                        $hit['hit_accession'] ?? '',
                        $hit['hit_description'] ?? '',
                        $hit['hit_organism'] ?? '',
                        $hit['pct_identity'] ?? 0,
                        $hit['evalue'] ?? 1,
                        $hit['bitscore'] ?? 0,
                    ]);
                    $inserted++;
                }
                $pdo->commit();
                error_log("Inserted $inserted BLAST hits for job $jobId");
            }
            break;
    }

    jsonResponse(['success' => true, 'tab' => $tabName, 'data' => $result]);
}

/**
 * Html render -> bare GET, no action parameter
 *
 * Requires job_id in the URL: extras.php?job_id=N
 * Pre-loads any previously computed tab results from the DB so the page doesn't
 * look empty when someone comes back to it. I also added hash-based tab
 * restoration so the page remembers which tab you were on after a reload.
 */
$jobId = (int) ($_GET['job_id'] ?? 0);

if ($jobId <= 0) {
    header('Location: fetch.php');
    exit;
}

$jobStmt = $pdo->prepare(
    'SELECT j.*,
            COUNT(s.seq_id) AS included_seqs
     FROM jobs j
     LEFT JOIN sequences s
           ON s.seq_id = j.job_id AND s.excluded = 0
     WHERE j.job_id = ?
     GROUP BY j.job_id'
);
$jobStmt->execute([$jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    header('Location: fetch.php');
    exit;
}

/* Pre-load all existing extra_analyses rows for this job */
$extrasStmt = $pdo->prepare(
    'SELECT e.analysis_type,
            e.seq_id,
            e.result_summary,
            e.output_file,
            s.accession,
            s.organism,
            s.length AS seq_length
     FROM extra_analyses e
     LEFT JOIN sequences s ON s.seq_id = e.seq_id
     WHERE e.job_id = ?
     ORDER BY e.analysis_type, s.organism'
);
$extrasStmt->execute([$jobId]);
$allExtras = $extrasStmt->fetchAll();

$extrasByTab = [];
foreach ($allExtras as $row) {
    $extrasByTab[$row['analysis_type']][] = $row;
}

/* Pre-load external links (UniProt, PDB, AlphaFold) */
$linksStmt = $pdo->prepare(
    'SELECT el.database_name,
            el.external_id,
            el.url,
            el.annotation_summary,
            s.accession,
            s.organism
     FROM external_links el
     JOIN sequences s ON s.seq_id = el.seq_id
     WHERE s.job_id = ?
     ORDER BY el.database_name, s.organism'
);
$linksStmt->execute([$jobId]);
$allLinks = $linksStmt->fetchAll();

$linksByDb = [];
foreach ($allLinks as $link) {
    $linksByDb[$link['database_name']][] = $link;
}

/* Pre-load BLAST results */
$blastStmt = $pdo->prepare(
    'SELECT query_accession, hit_accession, hit_description,
            hit_organism, pct_identity, evalue, bitscore
     FROM blast_results
     WHERE job_id = ?
     ORDER BY query_accession, evalue ASC'
);
$blastStmt->execute([$jobId]);
$blastResults = $blastStmt->fetchAll();

$blastByQuery = [];
foreach ($blastResults as $hit) {
    $q = $hit['query_accession'];
    if (!isset($blastByQuery[$q])) $blastByQuery[$q] = [];
    if (count($blastByQuery[$q]) < 5) $blastByQuery[$q][] = $hit;
}

/* Helper: check if a tab already has results */
function tabDone(array $byTab, string $name): bool {
    return !empty($byTab[$name]);
}

$webRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$jobWeb = $webRoot . '/results/' . $jobId;
$jobDir = $job['session_dir'] ?? (RESULTS_DIR . '/' . $jobId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Analyses — <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/extras.css">
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
                    New Analysis <i class="fa-solid fa-chevron-down fa-xs"></i>
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
        <button class="navi_hamburger" aria-label="Toggle navigation"
                aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </nav>
</header>

<!--
    Page body
    Main content area -> step breadcrumb, job context, tabbed interface.
-->
<main class="container"
      style="padding-top: var(--bigger_space);
             padding-bottom: var(--bigger_space);">

    <!-- Step breadcrumb -> shows where we are in the pipeline -->
    <nav class="step_breaddcrum" aria-label="Pipeline steps">
        <span class="step_breaddcrum_item done">
            <a href="fetch.php?job_id=<?= $jobId ?>"
               style="color:inherit;text-decoration:none;">
                1. Fetch Sequences
            </a>
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item done">
            <a href="analysis.php?job_id=<?= $jobId ?>"
               style="color:inherit;text-decoration:none;">
                2. Conservation
            </a>
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item done">
            <a href="motifs.php?job_id=<?= $jobId ?>"
               style="color:inherit;text-decoration:none;">
                3. Motif Scanning
            </a>
        </span>
        <span class="step_breaddcrum_sep">›</span>
        <span class="step_breaddcrum_item active">
            4. Extra Analyses
        </span>
    </nav>

    <h1 style="margin-bottom: var(--smol_space);">Extra Analyses</h1>
    <p style="color: var(--grey_text_colour);
               margin-bottom: var(--big_space);
               max-width: 65ch;">
        Supplementary analyses to add biological depth to your results.
        Each tab runs independently -> activate only the ones you need.
    </p>

    <!-- Job context bar -> a quick summary of what job we're working with -->
    <div class="jo_-contexxt">
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
    <div class="errror_banner" id="global-error-banner" role="alert">
        <span id="global-error-message">An error occurred.</span>
    </div>

    <!--
        Two-column layout: sidebar tabs + content area
        I tried a few different layouts for this page before settling on this.
        The tabbed interface keeps things organized without overwhelming the user.
    -->
    <div class="xtrass_layout">

        <!-- Tab sidebar -->
        <aside class="xtras_tab_sidebar">
            <p class="xtras_tab_sidebar_title">Analysis modules</p>
            <ul class="xtras_tab_navi_list" role="tablist">

                <li>
                    <button class="xtras_tab_nabi_button"
                            data-tab="pdb"
                            role="tab"
                            aria-selected="false">
                        <span class="xtras_tab-label">3D Structures</span>
                        <span class="u_done_yet <?= !empty($linksByDb['PDB']) || !empty($linksByDb['AlphaFold']) ? 'done' : 'pending' ?>">
                            <?= !empty($linksByDb['PDB']) || !empty($linksByDb['AlphaFold']) ? '✓' : '–' ?>
                        </span>
                    </button>
                </li>

                <li>
                    <button class="xtras_tab_nabi_button"
                            data-tab="uniprot"
                            role="tab"
                            aria-selected="false">
                        <span class="xtras_tab-label">UniProt</span>
                        <span class="u_done_yet <?= !empty($linksByDb['UniProt']) ? 'done' : 'pending' ?>">
                            <?= !empty($linksByDb['UniProt']) ? '✓' : '–' ?>
                        </span>
                    </button>
                </li>

                <li>
                    <button class="xtras_tab_nabi_button"
                            data-tab="blast"
                            role="tab"
                            aria-selected="false">
                        <span class="xtras_tab-label">BLAST Context</span>
                        <span class="u_done_yet <?= !empty($blastByQuery) ? 'done' : 'pending' ?>">
                            <?= !empty($blastByQuery) ? '✓' : '–' ?>
                        </span>
                    </button>
                </li>

                <li>
                    <button class="xtras_tab_nabi_button"
                            data-tab="links"
                            role="tab"
                            aria-selected="false">
                        <span class="xtras_tab-label">Ext. Resources</span>
                        <span class="u_done_yet static">⇗</span>
                    </button>
                </li>

            </ul>
        </aside>

        <!-- Tab content area -->
        <div class="tab_content_area">

            <!-- 3D structures (PDB / AlphaFold) -->
            <div class="tab_content_panel" id="tab-pdb">

                <div class="tab_setion_title">
                    3D Structure Cross-references
                </div>
                <p style="color:var(--grey_text_colour);
                           font-size:0.875rem;
                           margin-bottom:var(--mid_space);">
                    Available experimental structures (PDB) and predicted
                    structures (AlphaFold) for sequences in this dataset.
                </p>

                <p>
                    <strong>Biological relevance:</strong>
                    Comparing available structures across your taxon
                    allows you to assess whether conserved regions
                    identified in the conservation analysis correspond
                    to structurally important positions such as buried
                    core residues, active-site loops, or
                    protein-protein interaction interfaces.
                    AlphaFold models are available for most reviewed
                    UniProt entries and provide high-confidence
                    structural context even where experimental data
                    is absent.
                </p>

                <?php
                $pdbLinks = $linksByDb['PDB'] ?? [];
                $afLinks = $linksByDb['AlphaFold'] ?? [];
                $hasStructures = !empty($pdbLinks) || !empty($afLinks);
                ?>

                <?php if (!$hasStructures): ?>
                <div class="run_buttton_row">
                    <button class="button button_primary run-tab-btn"
                            data-tab="pdb" type="button">
                        Fetch PDB &amp; AlphaFold links
                    </button>
                    <div class="pertab_inlinspiner" id="spinner-pdb"></div>
                    <span class="run-status" id="status-pdb"
                          style="font-size:0.85rem;
                                 color:var(--grey_text_colour);">
                    </span>
                </div>
                <?php else: ?>

                <div class="mini_stats">
                    <div class="mini_stat">
                        <div class="mini_stat_value"><?= count($pdbLinks) ?></div>
                        <div class="mini_stat_label">PDB entries</div>
                    </div>
                    <div class="mini_stat">
                        <div class="mini_stat_value"><?= count($afLinks) ?></div>
                        <div class="mini_stat_label">AlphaFold models</div>
                    </div>
                </div>

                <div class="rezultss_table_wrapper">
                    <table class="rezultss_table">
                        <thead>
                            <tr>
                                <th>Organism</th>
                                <th>Accession</th>
                                <th>Database</th>
                                <th>Structure ID</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_merge($pdbLinks, $afLinks) as $lnk): ?>
                            72<tr>
                                <td><em><?= htmlspecialchars($lnk['organism']) ?></em></td>
                                <td style="font-family:var(--font_code);
                                           font-size:0.78rem;
                                           color:var(--grey_text_colour);">
                                    <?= htmlspecialchars($lnk['accession']) ?>
                                  </td>
                                <td>
                                    <?php if ($lnk['database_name'] === 'PDB'): ?>
                                        <span class="exxterna_link_pill exxterna_link_pill_pdb">
                                            PDB
                                        </span>
                                    <?php else: ?>
                                        <span class="exxterna_link_pill exxterna_link_pill_alphafold">
                                            AlphaFold
                                        </span>
                                    <?php endif; ?>
                                  </td>
                                <td>
                                    <a class="exxterna_link_pill <?= $lnk['database_name'] === 'PDB' ? 'exxterna_link_pill_pdb' : 'exxterna_link_pill_alphafold' ?>"
                                       href="<?= htmlspecialchars($lnk['url']) ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars($lnk['external_id']) ?>
                                    </a>
                                  </td>
                                <td style="font-size:0.78rem;
                                           color:var(--grey_text_colour);
                                           max-width:220px;">
                                    <?= htmlspecialchars($lnk['annotation_summary'] ?? '') ?>
                                  </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            </div><!-- /#tab-pdb -->

            <!-- UniProt annotations -->
            <div class="tab_content_panel" id="tab-uniprot">

                <div class="tab_setion_title">
                    UniProt Functional Annotations
                </div>
                <p style="color:var(--grey_text_colour);
                           font-size:0.875rem;
                           margin-bottom:var(--mid_space);">
                    Curated function, catalytic activity, pathway
                    membership, and GO terms from UniProt.
                </p>

                <p>
                    <strong>Biological relevance:</strong>
                    UniProt's curated entries link each protein to its
                    established molecular function, biological process,
                    and cellular component via Gene Ontology (GO) terms.
                    Comparing annotations across your taxon identifies
                    whether all retrieved sequences share the same
                    primary function or whether some diverge -> which
                    may indicate the retrieval of paralogues or
                    functionally specialised isoforms.
                </p>

                <?php $upLinks = $linksByDb['UniProt'] ?? []; ?>

                <?php if (empty($upLinks)): ?>
                <div class="run_buttton_row">
                    <button class="button button_primary run-tab-btn"
                            data-tab="uniprot" type="button">
                        Fetch UniProt annotations
                    </button>
                    <div class="pertab_inlinspiner" id="spinner-uniprot"></div>
                    <span class="run-status" id="status-uniprot"
                          style="font-size:0.85rem;
                                 color:var(--grey_text_colour);">
                    </span>
                </div>
                <?php else: ?>

                <div class="rezultss_table_wrapper">
                    <table class="rezultss_table">
                        <thead>
                            <tr>
                                <th>Organism</th>
                                <th>NCBI accession</th>
                                <th>UniProt entry</th>
                                <th>Annotation summary</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($upLinks as $up): ?>
                            <tr>
                                <td><em><?= htmlspecialchars($up['organism']) ?></em></td>
                                <td style="font-family:var(--font_code);
                                           font-size:0.78rem;
                                           color:var(--grey_text_colour);">
                                    <?= htmlspecialchars($up['accession']) ?>
                                  </td>
                                <td>
                                    <a class="exxterna_link_pill exxterna_link_pill_uniprot"
                                       href="<?= htmlspecialchars($up['url']) ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars($up['external_id']) ?>
                                    </a>
                                  </td>
                                <td style="font-size:0.82rem;
                                           color:var(--grey_text_colour);
                                           max-width:300px;
                                           line-height:1.55;">
                                    <?= htmlspecialchars($up['annotation_summary'] ?? '—') ?>
                                  </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            </div><!-- /#tab-uniprot -->

            <!-- BLAST contextual similarity -->
            <div class="tab_content_panel" id="tab-blast">

                <div class="tab_setion_title">
                    BLAST Contextual Similarity
                </div>
                <p style="color:var(--grey_text_colour);
                           font-size:0.875rem;
                           margin-bottom:var(--mid_space);">
                    All-vs-all <code>blastp</code> within this sequence set
                    plus top hits against NCBI <code>nr</code> outside your
                    taxonomic group.
                </p>

                <p>
                    <strong>Biological relevance:</strong>
                    The all-vs-all pairwise BLAST search provides an
                    alignment-free confirmation of the identity heatmap
                    from the conservation step. Top hits outside the
                    query taxon (against <code>nr</code>) reveal the
                    nearest relatives elsewhere in the tree of life,
                    useful for identifying horizontal gene transfer
                    candidates or cases of convergent evolution.
                    Very low E-values to sequences from very different
                    phyla may also flag contaminants in the retrieved set.
                </p>

                <?php if (empty($blastByQuery)): ?>
                <div class="run_buttton_row">
                    <button class="button button_primary run-tab-btn"
                            data-tab="blast" type="button">
                        Run BLAST analysis
                    </button>
                    <div class="pertab_inlinspiner" id="spinner-blast"></div>
                    <span class="run-status" id="status-blast"
                          style="font-size:0.85rem;
                                 color:var(--grey_text_colour);">
                    </span>
                </div>
                <?php else: ?>

                <div class="mini_stats">
                    <div class="mini_stat">
                        <div class="mini_stat_value"><?= count($blastByQuery) ?></div>
                        <div class="mini_stat_label">Queries searched</div>
                    </div>
                    <div class="mini_stat">
                        <div class="mini_stat_value">
                            <?= array_sum(array_map('count', $blastByQuery)) ?>
                        </div>
                        <div class="mini_stat_label">Total hits shown</div>
                    </div>
                </div>

                <!--
                    Accordion: one entry per query sequence
                    I made this collapsible because showing all hits for every query
                    at once would make the page ridiculously long.
                -->
                <div class="bllast_accordion" id="blast-accordion">
                <?php foreach ($blastByQuery as $query => $hits): ?>
                    <div class="bllast_accordion_header"
                         data-acc="<?= htmlspecialchars($query) ?>">
                        <span>
                            <span style="font-family:var(--font_code);
                                         font-size:0.85rem;">
                                <?= htmlspecialchars($query) ?>
                            </span>
                        </span>
                        <span style="font-size:0.78rem;
                                     color:var(--grey_text_colour);">
                            <?= count($hits) ?> hit<?= count($hits) !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <div class="bllast_accordion_body">
                        <table class="rezultss_table">
                            <thead>
                                <th>Hit accession</th>
                                    <th>Organism</th>
                                    <th>% Identity</th>
                                    <th>E-value</th>
                                    <th>Bitscore</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($hits as $hit):
                                $ev = (float) $hit['evalue'];
                                $evClass = $ev < 1e-50
                                         ? 'bllast_evalue_good'
                                         : ($ev < 1e-10 ? 'bllast_evalue_ok' : 'bllast_evalue_weak');
                                $evStr = $ev > 0
                                       ? sprintf('%.2e', $ev) /* sprintf code adapted from: https://www.php.net/manual/en/function.sprintf.php */
                                       : '0.0';
                            ?>
                                <tr>
                                    <td style="font-family:var(--font_code);
                                               font-size:0.78rem;">
                                        <a href="https://www.ncbi.nlm.nih.gov/protein/<?= htmlspecialchars($hit['hit_accession']) ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           style="color:var(--primary_colour);">
                                            <?= htmlspecialchars($hit['hit_accession']) ?>
                                        </a>
                                    </td>
                                    <td><em style="font-size:0.82rem;">
                                        <?= htmlspecialchars($hit['hit_organism']) ?>
                                    </em></td>
                                    <td><?= round((float)$hit['pct_identity'], 1) ?>%</td>
                                    <td class="<?= $evClass ?>"><?= $evStr ?></td>
                                    <td><?= round((float)$hit['bitscore'], 0) ?></td>
                                    <td style="font-size:0.78rem;
                                               color:var(--grey_text_colour);
                                               max-width:200px;">
                                        <?= htmlspecialchars($hit['hit_description']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                </div><!-- /.bllast_accordion -->

                <div class="download_button_row">
                    <a class="download_button"
                       href="<?= $jobWeb ?>/blast_results.txt" download>
                        blast_results.txt
                    </a>
                </div>
                <?php endif; ?>

            </div><!-- /#tab-blast -->

            <!--
                External resources (static links)
                These are generated on the fly from the job's protein family and
                taxonomic group. No pipeline needed -> just HTML.
                I originally tried to scrape these sites for more detailed info
                but that felt like overkill and also a bit rude to their servers.
		
		URL encoding and construction code adapted from: https://www.php.net/manual/en/function.urlencode.php
								 https://stackoverflow.com/questions/3185484/urlencode-vs-rawurlencode
            -->
            <div class="tab_content_panel" id="tab-links">

                <div class="tab_setion_title">
                    External Resources
                </div>
                <p style="color:var(--grey_text_colour);
                           font-size:0.875rem;
                           margin-bottom:var(--big_space);">
                    Contextual links to external databases generated from
                    your protein family and taxonomic group.
                    No computation required -> links open in a new tab.
                </p>

                <?php
                $pfEnc = urlencode($job['protein_family']);
                $taxEnc = urlencode($job['taxonomic_group']);
                $combined = urlencode($job['protein_family'] . ' ' . $job['taxonomic_group']);

                $resources = [
                    [
                        'label' => 'UniProt',
                        'pill' => 'exxterna_link_pill_uniprot',
                        'icon' => 'fa-database',
                        'url' => "https://www.uniprot.org/uniprot/?query={$pfEnc}+taxonomy%3A{$taxEnc}",
                        'desc' => "Search UniProt for {$job['protein_family']} in {$job['taxonomic_group']}",
                    ],
                    [
                        'label' => 'KEGG Pathway',
                        'pill' => 'exxterna_link_pill_kegg',
                        'icon' => 'fa-diagram-project',
                        'url' => "https://www.kegg.jp/kegg-bin/search_pathway_text?q={$pfEnc}",
                        'desc' => "Find pathways involving {$job['protein_family']} in KEGG",
                    ],
                    [
                        'label' => 'Reactome',
                        'pill' => 'exxterna_link_pill_reactome',
                        'icon' => 'fa-circle-nodes',
                        'url' => "https://reactome.org/content/query?q={$pfEnc}",
                        'desc' => "Reactome pathway browser for {$job['protein_family']}",
                    ],
                    [
                        'label' => 'PubMed literature',
                        'pill' => 'exxterna_link_pill_pubmed',
                        'icon' => 'fa-book-open',
                        'url' => "https://pubmed.ncbi.nlm.nih.gov/?term={$combined}",
                        'desc' => "Recent publications on {$job['protein_family']} in {$job['taxonomic_group']}",
                    ],
                    [
                        'label' => 'STRING interactions',
                        'pill' => 'exxterna_link_pill_string',
                        'icon' => 'fa-network-wired',
                        'url' => "https://string-db.org/cgi/network?identifiers={$pfEnc}",
                        'desc' => "Protein interaction network for {$job['protein_family']}",
                    ],
                    [
                        'label' => 'NCBI Gene',
                        'pill' => 'exxterna_link_pill_pubmed',
                        'icon' => 'fa-dna',
                        'url' => "https://www.ncbi.nlm.nih.gov/gene/?term={$pfEnc}+{$taxEnc}",
                        'desc' => "NCBI Gene entries for {$job['protein_family']} in {$job['taxonomic_group']}",
                    ],
                    [
                        'label' => 'InterPro domains',
                        'pill' => 'exxterna_link_pill_string',
                        'icon' => 'fa-puzzle-piece',
                        'url' => "https://www.ebi.ac.uk/interpro/search/text/{$pfEnc}/",
                        'desc' => "Domain and family annotation for {$job['protein_family']} in InterPro",
                    ],
                ];
                ?>

                <div style="display:grid;
                             grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                             gap:var(--mid_space);">
                    <?php foreach ($resources as $res): ?>
                    <a href="<?= htmlspecialchars($res['url']) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       style="display:flex;
                              align-items:flex-start;
                              gap:var(--mid_space);
                              padding:var(--mid_space) var(--big_space);
                              background:var(--card_background_colour);
                              border:1px solid var(--border_colour);
                              border-radius:var(--bigger_corner_rounding);
                              text-decoration:none;
                              color:var(--base_text_colour);
                              transition:box-shadow var(--transition),
                                         border-color var(--transition);"
                       onmouseover="this.style.boxShadow='var(--shadow_mid)';
                                    this.style.borderColor='var(--primary_colour)';"
                       onmouseout="this.style.boxShadow='';
                                   this.style.borderColor='var(--border_colour)';">
                        <span class="exxterna_link_pill <?= $res['pill'] ?>"
                              style="margin-top:2px; flex-shrink:0;">
                            <?= htmlspecialchars($res['label']) ?>
                        </span>
                        <span style="font-size:0.82rem;
                                      color:var(--grey_text_colour);
                                      line-height:1.5;">
                            <?= htmlspecialchars($res['desc']) ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>

            </div><!-- /#tab-links -->

            <!-- Proceed row -->
            <div class="proceed_row">
                <a href="fetch.php" class="button button_primary">
                    Start new analysis
                </a>
                <a href="revisit.php" class="button button_outline">
                    My sessions
                </a>
                <a href="analysis.php?job_id=<?= $jobId ?>"
                   class="button button_outline">
                    Back to conservation
                </a>
                <?php if (file_exists($jobDir . '/report.pdf')): ?>
                <a href="<?= $jobWeb ?>/report.pdf"
                   class="button button_secondary"
                   target="_blank" rel="noopener noreferrer">
                    Download full report
                </a>
                <?php endif; ?>
            </div>

        </div><!-- /.tab_content_area -->

    </div><!-- /.xtrass_layout -->

    <input type="hidden" id="active-job-id" value="<?= $jobId ?>">

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
    main.js handles the nav hamburger and other global stuff -> kept it separate
    so this page doesn't get too bloated. The inline script below handles the
    extras-page-specific bits: tab switching, per-tab run buttons, BLAST accordion,
    and restoring the active tab from the URL hash. Took a few tries to get the
    hash restoration working right.
-->
<script src="../assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    const jobId = parseInt(document.getElementById('active-job-id').value);

    /**
     * Tab switching
     * Pretty straightforward -> click a tab button, show the matching panel.
     * Had to remember to update aria-selected for accessibility, otherwise
     * screen readers get confused about which tab is active.
     */
    const tabBtns = document.querySelectorAll('.xtras_tab_nabi_button');
    const tabPanels = document.querySelectorAll('.tab_content_panel');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            tabBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            tabPanels.forEach(p => p.classList.remove('active'));

            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');

            const panel = document.getElementById('tab-' + this.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });

    /*
     * Per-tab run buttons
     * Each tab has its own "Run" button -> POST to the server, show a spinner,
     * then reload the page with a hash so the same tab stays open.
     * I originally tried updating the DOM dynamically without a reload but
     * keeping everything in sync with the server-rendered state got messy.
     */
    document.querySelectorAll('.run-tab-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const tab = this.dataset.tab;

            this.disabled = true;
            this.innerHTML = 'Running…';

            const spinner = document.getElementById('spinner-' + tab);
            const status = document.getElementById('status-' + tab);
            if (spinner) spinner.classList.add('visible');
            if (status) status.textContent = 'Submitting request…';

            hideGlobalError();

            const formData = new FormData();
            formData.append('job_id', jobId);
            formData.append('tab', tab);

            fetch('extras.php?action=run_tab', {
                method: 'POST',
                body: formData,
            })
            .then(r => r.json())
            .then(data => {
                if (spinner) spinner.classList.remove('visible');

                if (data.error) {
                    showGlobalError(data.error);
                    resetRunBtn(btn, tab);
                    return;
                }

                if (status) status.textContent = 'Complete. Reloading…';

                /*
                 * Reload the page so the server-rendered result HTML appears.
                 * The hash preserves which tab was active -> learned this trick
                 * after getting annoyed that the page kept resetting to the first tab.
		 * 
		 * Code adapted from: https://stackoverflow.com/questions/503093/how-can-i-make-a-redirect-in-javascript-while-preserving-the-hash
                 */
                window.location.href = 'extras.php?job_id=' + jobId + '#tab-' + tab;
            })
            .catch(err => {
                if (spinner) spinner.classList.remove('visible');
                showGlobalError(err.message || 'Request failed.');
                resetRunBtn(btn, tab);
            });
        });
    });

    /*
     * Restore active tab from URL hash on load
     * This makes the page remember where you left off after a reload.
     * Took a few tries to get the selector right -> had to strip 'tab-' from
     * the hash value to match the data-tab attribute.
     */
    const hash = window.location.hash.replace('#', '');
    if (hash && document.getElementById(hash)) {
        tabBtns.forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        tabPanels.forEach(p => p.classList.remove('active'));

        const matchingBtn = document.querySelector(
            '.xtras_tab_nabi_button[data-tab="' + hash.replace('tab-', '') + '"]'
        );
        if (matchingBtn) {
            matchingBtn.classList.add('active');
            matchingBtn.setAttribute('aria-selected', 'true');
        }
        document.getElementById(hash).classList.add('active');
    }

    /*
     * BLAST accordion -> clicking a header expands that section
     * Made this collapsible because showing all hits for every query at once
     * made the page ridiculously long. The first version expanded multiple
     * sections at once, which was confusing -> fixed that by closing everything
     * before opening the clicked one.
     *
     * Code adapted from: https://www.w3.org/WAI/ARIA/apg/patterns/accordion/
     *			  https://developer.mozilla.org/en-US/docs/Web/HTML/Element/details
     */
    document.querySelectorAll('.bllast_accordion_header').forEach(header => {
        header.addEventListener('click', function () {
            const body = this.nextElementSibling;
            const isOpen = body && body.classList.contains('open');

            document.querySelectorAll('.bllast_accordion_body').forEach(b =>
                b.classList.remove('open')
            );
            document.querySelectorAll('.bllast_accordion_header').forEach(h => {
                h.classList.remove('open');
            });

            if (!isOpen && body) {
                body.classList.add('open');
                this.classList.add('open');
            }
        });
    });

    /**
     * Error banner helpers
     * Nothing fancy here -> just shows and hides the global error banner.
     * The scrollIntoView call makes sure the error is visible even if
     * the user is scrolled way down the page.
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Element/scrollIntoView
     *			  https://developer.mozilla.org/en-US/docs/Web/API/ScrollIntoViewOptions
     * 
     */
    function showGlobalError(msg) {
        const banner = document.getElementById('global-error-banner');
        const msgEl = document.getElementById('global-error-message');
        if (banner && msgEl) {
            msgEl.textContent = msg;
            banner.classList.add('visible');
            banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function hideGlobalError() {
        const banner = document.getElementById('global-error-banner');
        if (banner) banner.classList.remove('visible');
    }

    /**
     * Reset run button after failure
     * Restores the original button text so the user can try again.
     * I hardcoded the labels here because the tab names are stable.
     *
     * Code adapted from: https://developer.mozilla.org/en-US/docs/Web/API/Element/innerHTML
     * 			  https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Object_initializer
     */
    function resetRunBtn(btn, tab) {
        btn.disabled = false;
        const labels = {
            pdb: 'Fetch PDB &amp; AlphaFold links',
            uniprot: 'Fetch UniProt annotations',
            blast: 'Run BLAST analysis',
        };
        btn.innerHTML = (labels[tab] || 'Run analysis');
    }

}());
</script>

</body>
</html>
