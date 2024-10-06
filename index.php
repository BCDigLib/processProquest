<?php declare(strict_types=1);
date_default_timezone_set('America/New_York');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require __DIR__."/vendor/autoload.php"; // This tells PHP where to find the autoload file so that PHP can load the installed packages

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

$fontpath = realpath('/usr/share/fonts/freesans-font/');
putenv('GDFONTPATH='.$fontpath);

// Assign the default configuration file
define('PROCESSPROQUEST_INI_FILE', 'processProquest.ini');

// Only one optional argument is permitted.
if (count($argv) > 2) {
    usage();
    exit(1);
}

// Load configuration settings.
$configurationArray = getConfigurationSettings($argv);
$configurationFile = $configurationArray['file'];
$configurationSettings = $configurationArray['settings'];

// Exit if configuration file is invalid.
if(is_null($configurationSettings)){
    usage();
    exit(1);
}

/**
 * 
 * Determine debugging status.
 * 
 */

// Debug is off by default.
$debugDefault = false;

// Check debug value from $configurationSettings
$debugConfiguration = null;
if (isset($configurationSettings['script']['debug'])) {
    $debugConfiguration = $configurationSettings['script']['debug'];
}

// Fetch the env var PROCESSPROQUEST_DEBUG value if it exists
$debugEnvVar = getenv('PROCESSPROQUEST_DEBUG');

/*
 * Debug value is set in descending order:
 *  1) $debugEnvVar - PROCESSPROQUEST_DEBUG env var
 *  2) $debugConfiguration - [script] debug in configuration file
 *  3) $debugDefault
*/
if ($debugEnvVar) {
    $debug = boolval($debugEnvVar);
} elseif ($debugConfiguration) {
    $debug = boolval($debugConfiguration);
} else {
    $debug = boolval($debugDefault);
}

/**
 * 
 * Set up the logging object.
 * 
 */

// Set up log file location and name.
$dateFormatLogFile = date("Ymd-His", time());
$logLocation = $configurationSettings['log']['location'];
$logFileName = "ingest-" . $dateFormatLogFile . ".txt";

// New Logger instance. Create a new channel called "processProquest".
$logger = new Logger("processProquest");

// Check if the logger object was created properly.
if ( is_object($logger) === false ) {
    $errorMessage = "An empty logger object was passed. Please check that the Monolog logger was configured correctly.";
    echo "ERROR: {$errorMessage}\n";
    echo "Exiting.";
    exit(1);
}

// Default date format is "Y-m-d\TH:i:sP"
$dateFormatLogger = "Y-m-d H:i:s";

// Default: "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n"
if ($debug) {
    $output = "[%datetime%] [DEBUG] %message% %context% %extra%\n";
} else {
    $output = "[%datetime%] > %message% %context% %extra%\n";
}

/**
 * 
 * Create a log formatter.
 * 
 * Passing these arguments:
 *   ouput string format
 *   date string format
 *   allowInlineLineBreaks = true
 *   ignoreEmptyContextAndExtra = true
 */
$formatter = new LineFormatter($output, $dateFormatLogger, true, true);

// Log to file.
$fileOutput = new StreamHandler("{$logLocation}{$logFileName}", Level::Debug);
$fileOutput->setFormatter($formatter);
$logger->pushHandler($fileOutput);

// Log to console.
$consoleOutput = new StreamHandler('php://stdout', Level::Debug);
$consoleOutput->setFormatter($formatter);
$logger->pushHandler($consoleOutput);

/**
 * 
 * Create FTP connection object.
 * 
 */

require_once 'ProquestFTP.php';
use \Processproquest\FTP as FTP;
$urlFTP = $configurationSettings['ftp']['server'];
try {
    $ftpConnection = new FTP\ProquestFTP($urlFTP);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Exiting.";
    // TODO: send email notification.
    exit(1);
}

/**
 * 
 * Create Fedora repository connection object.
 * 
 */

require_once 'FedoraRepository.php';
use \Processproquest\Repository as REPO;
$fedoraUrl = $configurationSettings['fedora']['url'];
$fedoraUsername = $configurationSettings['fedora']['username'];
$fedoraPassword = $configurationSettings['fedora']['password'];
$tuqueLibraryLocation = $configurationSettings['packages']['tuque'];
try {
    $fedoraRepository = new REPO\FedoraRepository($tuqueLibraryLocation, $fedoraUrl, $fedoraUsername, $fedoraPassword);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Exiting.\n";
    // TODO: send email notification.
    exit(1);
}

/**
 * 
 * Create Processproquest object.
 * 
 * Requires an array with configuration settings, a logger object, and an optional debug value.
 * Can chain together additional setter functions to assign an FTP connection object,
 * and a FedoraConnection object.
 */

require_once 'Processproquest.php';
use \Processproquest as PP;
try {
    $process = (new PP\Processproquest($configurationArray, $logger, $debug))
                ->setFTPConnection($ftpConnection)
                ->setFedoraConnection($fedoraRepository);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Exiting.\n";
    // TODO: send email notification.
    exit(1);
}

/**
 * 
 * Process ETD ingest workflow.
 * 
 */

// Log into the FTP server.
// Exit when an exception is caught.
try {
    $process->LogIntoFTPServer();
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    // TODO: 
    $process->postProcess();
    $logger->info("Exiting.");
    exit(1);
}

// Process ETD files.
try {
    $process->processAllFiles();
} catch(Exception $e) {
    $process->postProcess();
} finally {
    // Run postProcess() to move ETD files on the FTP server and send out email notification.
    $process->postProcess();
    $logger->info("Exiting.");
    exit(1);
}


/**
 * Output usage strings.
 */
function usage() {
    echo "Usage: php index.php [options]\n";
    echo "  options:\n";
    echo "    INI formatted configuration file. Default file name is 'processProquest.ini'\n";
    echo "Example: php index.php my_custom_settings.ini\n";
    echo "(See README.md for configuration file information)\n";
}

/**
 * Get a valid configuration file.
 * 
 * Load configuration file passed as an argument, or
 * load from a default filename if there isn't an argument found.
 * Also check if the file is valid.
 *
 * @param string $arguments The $argv array.
 * 
 * @return object|NULL Return the configuration settings or NULL on error.
 */
function getConfigurationSettings($arguments) {
    // Requires a single parameter containing the location of an initialization file.
    if (isset($arguments[1])){
        // Use the argument provided.
        $configurationFile = $arguments[1];
    } else {
        // No optional second argument was found so use the default configuration file.
        $configurationFile = PROCESSPROQUEST_INI_FILE;
    }

    // Check if the validity of the configuration file.
    if (!validateConfig($configurationFile)){
        // Configuration file is invalid and script can not continue.
        return NULL;
    }

    // Read in configuration settings.
    $configurationSettings = parse_ini_file($configurationFile, true);

    if (empty($configurationSettings)) {
        return NULL;
    }

    $configurationArray = array(
        "file" => $configurationFile,
        "settings" => $configurationSettings
    );

    return $configurationArray;
}

/**
 * Validate configuration file.
 * 
 * Check if a configuration file exists, and if it is empty.
 *
 * @param string $configurationFile The configuration file name.
 * 
 * @return bool Is the configuration file valid.
 */
function validateConfig($configurationFile) {
    // Check if config ini file exits
    if(!file_exists($configurationFile)) {
        echo "ERROR: Could not find a configuration file with that name. Please check your settings and try again.\n";
        return false;
    }

    // Check if this is an empty file
    if ($configurationFile == False) {
        echo "ERROR: This configuration file is empty or misformed. Please check your settings and try again.\n";
        return false;
    }

    return true;
}

exit(1);

?>
