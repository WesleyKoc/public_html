<?php
/**
 * config.php: ALiHS configuration file
 *
 * This is the config file imported by all PHP pages via:
 *   require_once __DIR__ . '/../config.php'; (from pages)
 *   require_once __DIR__ . '/config.php'; (from project root)
 *
 * Everything lives here. Everything: the PDO connection, NCBI API settings,
 * filesystem paths, and app-wide constants. Having it all in one
 * place made things a lot easier to manage.
 *
 * Real credentials obviously shouldn't be committed to version control.
 * In production these should come from environment variables or a secrets
 * manager, not be hardcoded like this. But for this initial version it should be enough
 */
 
 
/**
 * Error reporting
 *
 * Turned on for development so mistakes are visible immediately.
 * Should be switched off (or just commented out) before going live.
 * Ain't nobody wanna to see PHP errors splattered across the page.
 * Ok can't be bothered, I'm paranoid about the page not working at all
 * Code adapted from: https://www.php.net/manual/en/function.error-reporting.php
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Database creds
 *
 * Basically for the MySQL connection details for the bioinfmsc8
 */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 's2238539_IWD2_WEB_DB');
define('DB_USER', 's2238539');
define('DB_PASS', 'Wkoc12028100!');
define('DB_CHARSET', 'utf8mb4');

/**
 * PDO Connection
 *
 * One $pdo object created here and reused everywhere that
 * includes config.php. Can't be bothered reconnecting on every page.
 * All queries must go through this using prepared statements,
 * never raw string interpolation.
 *
 * ERRMODE_EXCEPTION means PDO throws instead of silently failing,
 * which made bugs hell a lot easier to catch. FETCH_ASSOC returns
 * column-name arrays instead of the confusing mixed indexed/named
 * default. Emulated prepares are off so the database handles them
 * natively --> safer
 *
 * If the connection fails, $pdo is set to null rather than killing
 * the whole process. Pages like Credits that don't touch the DB
 * should still be able to load fine.
 * 
 * Code adapted from: https://phpdelusions.net/pdo
 */
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_CHARSET
);

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // throw exceptions on error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // return arrays by column name
    PDO::ATTR_EMULATE_PREPARES => false, // use native prepared statements
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    
    if (str_contains($_SERVER['REQUEST_URI'], 'fetch.php')) {
        jsonResponse(['error' => 'Database unavailable'], 500);
    }
    
    $pdo = null; 
}

/**
 * NCBI/Entrez settings
 *
 * Settings for talking to NCBI's Entrez API
 * API key is needed to get the rate limit raised from 3 to 10 requests per second
 *
 * NCBI also wanted an email and tool name to be sent with every request
 * RETMAX caps how many sequences come back per query
 * 500 felt like a good upper limit without hammering their API.
 *
 * Code taken from NCBI API guide: https://ncbiinsights.ncbi.nlm.nih.gov/2017/11/08/accessing-nih-data-via-api-keys/
 */
define('NCBI_API_KEY', '140a1341dbe2e3f12172130f324a9f250808');
define('NCBI_EMAIL', 'S.C.W.Koc@sms.ed.ac.uk');
define('NCBI_TOOL', 'ALiHS');
define('NCBI_RETMAX', 500); // max sequences fetched per query
define('NCBI_DB', 'protein'); // Entrez database (protein | nr)
define('NCBI_RETTYPE', 'fasta'); // return type for efetch
define('NCBI_RETMODE', 'text'); // return mode for efetch

/**
 * Filesytsem paths
 *
 * I made all paths absolute so there's no ambiguity when PHP shells
 * out to Python or the EMBOSS binaries.
 * BASE_DIR is the project root, Basically the public_html/Website directory
 *
 * For the results directory, I tried a few fallback locations
 * in order of default preference. Cuz write permissions on the bioinfmsc8
 * were a bit funky. If none of them work, it falls
 * back to the system temp directory with a warning in the error log.
 */
 
/* Project root */
define('BASE_DIR', '/localdisk/home/s2238539/public_html/Website');

/* Try multiple locations in order of preference */
$possible_results_dirs = [
    BASE_DIR . '/results',
    '/tmp/alihs_' . get_current_user(),
    sys_get_temp_dir() . '/alihs_' . get_current_user(),
];

$results_dir = null;
foreach ($possible_results_dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        $results_dir = $dir;
        break;
    }
}

if (!$results_dir) {
    $results_dir = sys_get_temp_dir();
    error_log("WARNING: Could not create dedicated results directory. Using: " . $results_dir);
}

/* Where per-job result files end up */
define('RESULTS_DIR',  BASE_DIR . '/results');
 
/* Pipeline scripts squat here */
define('SCRIPTS_DIR',  BASE_DIR . '/scripts');
 
/* Pre-generated g6pase dataset, don't wanna call the same dataset everytime just for example */
define('EXAMPLE_DIR',  RESULTS_DIR . '/example');
 
/**
 * createJobDir
 *
 * Creates a per-job subdirectory under RESULTS_DIR and makes sure
 * it's actually writable before returning the path. Permissions
 * are set to 0777 cuz it didn't work in 0755 on bioinfmsc8
 *
 * Throws a RuntimeException rather than returning false, so the
 * calling code can't accidentally carry on with a missing directory.
 *
 * @param  string $jobId Unique identifier for this job
 * @return string Absolute path to the created directory
 * @throws RuntimeException If the directory can't be created or written to
 *
 * Code adapted from: https://www.php.net/manual/en/function.mkdir.php
 */
function createJobDir($jobId): string
{
    $dir = RESULTS_DIR . '/' . $jobId;
    
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            if (!@mkdir($dir, 0777, true)) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to create results directory: $dir - " . 
                    ($error['message'] ?? 'Permission denied')
                );
            }
        }
        @chmod($dir, 0777);
    }
    
    if (!is_writable($dir)) {
        throw new RuntimeException(
            "Results directory exists but is not writable: $dir"
        );
    }
    
    return $dir;
}

/**
 * 6. BINARY / EXECUTABLE PATHS
 *
 * Here are all the absolute paths to all the external tools the pipeline depends on.
 * Most of them live in /usr/bin on bioinfmsc8 anyway
 * but it was easier to define them explicitly rather than relying on PATH.
 * Sometimes failed when using using PATH dunno why
 */
define('PYTHON_BIN', '/usr/bin/python3');
define('CLUSTALO_BIN', '/usr/bin/clustalo');
define('EMBOSS_BIN_DIR', '/usr/bin');
define('PLOTCON_BIN', EMBOSS_BIN_DIR . '/plotcon');
define('PATMATMOTIFS_BIN', EMBOSS_BIN_DIR . '/patmatmotifs');
define('BLASTP_BIN', '/localdisk/home/ubuntu-software/blast217/ncbi-blast-2.17.0+-src/c++/ReleaseMT/bin/blastp');
define('MAKEBLASTDB_BIN', '/localdisk/home/ubuntu-software/blast217/ncbi-blast-2.17.0+-src/c++/ReleaseMT/bin/makeblastdb');

/**
 * Local BLAST nr database.
 * It's pre-installed on bioinfmsc8 at this path.
 * Will need updating if plan to move to different server
 */
define('BLAST_NR_DB',
    '/localdisk/home/ubuntu-software/blast217/' .
    'ncbi-blast-2.17.0+-src/c++/ReleaseMT/ncbidb/nr'
);

/* Full path to pipeline.py dispatcher */
define('PIPELINE_SCRIPT', SCRIPTS_DIR . '/pipeline.py');


/**
 * Application constants
 *
 * Site-wide settings that get referenced across multiple pages.
 * Can't be bothered to name them explicitly every time just to change some small detail
 */
 
/* Site name shown in page titles and headers */
define('SITE_NAME', 'ALiHS');
 
/* Current version */
define('SITE_VERSION', '1.0.0');
 
/* GitHub repo URL */
define('GITHUB_URL', 'https://github.com/WesleyKoc/public_html');
 
/* Session cookie name */
define('SESSION_COOKIE', 'alihs_session');
 
/* How long the session cookie sticks around for (seconds): 90 days */
define('SESSION_COOKIE_TTL', 90 * 24 * 60 * 60);
 
/* Max sequences per job --> matches NCBI_RETMAX */
define('MAX_SEQUENCES', 500);
 
/* Sequence length filter bounds (amino acids) */
define('MIN_SEQ_LENGTH', 10);
define('MAX_SEQ_LENGTH', 10000);
 
/* Default ClustalOmega output format */
define('DEFAULT_ALN_FORMAT', 'clustal');
 
/* Default plotcon window size. I tried a few values, 4 worked best */
define('DEFAULT_PLOTCON_WINDOW', 4);
 
/* Minimum sequences needed before alignment is even attempted */
define('MIN_SEQUENCES_FOR_ALIGNMENT', 2);

/**
 * Helper commands
 *
 * Wrapper around shell_exec that also captures stderr and checks
 * the exit code. Without this, silent failures from external
 * tools were really hard to debug. The command would just return
 * nothing and it wasn't obvious why.
 *
 * stderr goes to a temp file so it doesn't bleed into stdout.
 * exec() is used alongside shell_exec purely to get the exit code,
 * since shell_exec can't expose it all by its onesies, savvy?
 *
 * Throws a RuntimeException on non-zero exit so every caller gets
 * consistent error handling rather than having to check return
 * values manually.
 *
 * @param string $command The full shell command to run
 * @return string Stdout output from the command
 * @throws RuntimeException If the command exits with a non-zero code
 *
 * Code adapted from: https://www.php.net/manual/en/function.shell-exec.php
 */

function runCommand(string $command): string
{
    $stderrFile = tempnam(sys_get_temp_dir(), 'alihs_err_');
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
 * Helper command for jsonResponse
 *
 * Sets the Content-Type header, encodes the payload as JSON,
 * prints it, and exits. Every AJAX-handling branch uses this
 * instead of echo + exit directly, just to keep the response
 * format consistent across all pages.
 *
 * JSON_UNESCAPED_UNICODE and JSON_UNESCAPED_SLASHES keep the
 * output readable. I don't want escaped forward slashes or
 * \uxxxx sequences when the charset is already utf8mb4.
 *
 * @param mixed $data Data to encode (array, object, etc.)
 * @param int $httpCode HTTP status code (default 200)
 * @return never
 *
 * Code adapted from: https://www.php.net/manual/en/json.constants.php
 */
function jsonResponse(mixed $data, int $httpCode = 200): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
