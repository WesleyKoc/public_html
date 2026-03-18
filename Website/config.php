<?php
/**
 * Error reporting
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Database credentials
 */
define('DB_HOST', 'bioinfmsc8.bio.ed.ac.uk');
define('DB_PORT', '3306');
define('DB_NAME', 'iwd_ica');
define('DB_USER', 's2238539');      
define('DB_PASS', 'Wkoc12028100!');
define('DB_CHARSET', 'utf8mb4');

/**
 * NCBI and Entrez settings
 */
define('NCBI_API_KEY', '140a1341dbe2e3f12172130f324a9f250808'); 
define('NCBI_EMAIL', 'S.C.W.Koc@sms.ed.ac.uk');
define('NCBI_TOOL', 'iwd_ica_s2238539');
define('NCBI_RETMAX', 500);
define('NCBI_DB', 'protein');
define('NCBI_RETTYPE', 'fasta');
define('NCBI_RETMODE', 'text');

/**
 * Filesystem paths
 */
define('BASE_DIR', realpath(__DIR__));
define('RESULTS_DIR', BASE_DIR . '/results');
define('SCRIPTS_DIR', BASE_DIR . '/scripts');
define('EXAMPLE_DIR', RESULTS_DIR . '/example');

/**
 * Path to tools
 */
define('PYTHON_BIN', '/usr/bin/python3');
define('CLUSTALO_BIN', '/usr/bin/clustalo');
define('EMBOSS_BIN_DIR', '/usr/bin');

/**
 * Emboss tools paths
 */
define('PLOTCON_BIN', EMBOSS_BIN_DIR . '/plotcon');
define('PATMATMOTIFS_BIN', EMBOSS_BIN_DIR . '/patmatmotifs');
define('PEPSTATS_BIN', EMBOSS_BIN_DIR . '/pepstats');
define('GARNIER_BIN', EMBOSS_BIN_DIR . '/garnier');
define('PEPWINDOW_BIN', EMBOSS_BIN_DIR . '/pepwindow');

/**
 * Blast tools paths
 */
define('BLASTP_BIN', '/usr/bin/blastp');
define('MAKEBLASTDB_BIN', '/usr/bin/makeblastdb');

/**
 * Blast nr database path
 */
define(
    'BLAST_NR_DB',
    '/localdisk/home/ubuntu-software/blast217/' .
    'ncbi-blast-2.17.0+-src/c++/ReleaseMT/ncbidb/nr'
    );

/**
 * Pipeline path
 */
define('PIPELINE_SCRIPT', SCRIPTS_DIR . '/pipeline.py');

/**
 * Constants
 */
define('SITE_NAME', 'IWD2_ICA');
define('SITE_VERSION', '1.0.0');
define('GITHUB_URL', 'https://github.com/WesleyKoc/public_html.git');
define('SESSION_COOKIE', 'IWD2_ICA_session');
define('SESSION_COOKIE_TTL', 90 * 24 * 60 * 60);
define('MAX_SEQUENCES', 500);
define('MIN_SEQ_LENGTH', 10);
define('MAX_SEQ_LENGTH', 10000);
define('DEFAULT_ALN_FORMAT', 'clustal');
define('DEFAULT_PLOTCON_WINDOW', 4);
define('MIN_SEQUENCES_FOR_ALIGNMENT', 2);

/**
 * PHP Database Objects establishment and connection
 */

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=> false,
    ];
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Database connection failed.',
        'detail' => $e->getMessage() /** Remove this line after publishing for security */
    ]));
}

/**
 * Helper function to create workspace
 */
function createJobDir($jobId): string {
    $dir = RESULTS_DIR . '/' . $jobId;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new RuntimeException(
                "Failed to create results directory: $dir"
            );
        }
    }
    return $dir;
}

/**
 * Helper function to run terminal commands safely
 */
function runCommand(string $command): string {

    $stderrFile = tempnam(sys_get_temp_dir(), 'phyloseq_err_');
    $fullCommand = $command . ' 2>' . escapeshellarg($stderrFile);

    $stdout   = shell_exec($fullCommand);
    $exitCode = 0;

    exec($fullCommand, $outputLines, $exitCode);

    $stderr = file_exists($stderrFile)
        ? trim(file_get_contents($stderrFile))
        : '';
    @unlink($stderrFile);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Command failed (exit $exitCode): $command\nStderr: $stderr"
        );
    }

    return $stdout ?? '';
}

/**
 * Helper function to send data back to browser in JSON
 */
function jsonResponse(mixed $data, int $httpCode = 200): never {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}