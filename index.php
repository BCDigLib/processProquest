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

/**
 * 
 * Parse script arguments and load the configuration file.
 * 
 */

// Assign the default configuration file
define('PROCESSPROQUEST_INI_FILE', 'processProquest.ini');

// Parse getopt() script options.
$configurationFile = PROCESSPROQUEST_INI_FILE;
$dryrunOption = false;
$short_options = "hc:d";
$long_options = ["help", "configuration-file", "dry-run"];
$options = getopt($short_options, $long_options);

// Display usage message and leave.
if(isset($options["h"]) || isset($options["help"])) {
    usage();
    exit(1);
}

// Load the configuration file.
if(isset($options["c"]) || isset($options["configuration-file"])) {
    $configurationFile = isset($options["c"]) ? $options["c"] : $options["configuration-file"];
}

// Check if this is a dry-run.
if(isset($options["d"]) || isset($options["dry-run"])) {
    $dryrunOption = true;
}

// Load configuration settings.
$configurationSettings = loadConfigurationSettings($configurationFile);

// Exit if configuration file is invalid.
if(is_null($configurationSettings)){
    echo "\nPlease see the README.md file for additional information on how to set a custom configuration file.\n\n";
    exit(1);
}

/**
 * 
 * Determine debugging status.
 * 
 */

// Debug is off by default.
$debugDefault = false;

// Check $configurationSettings[script][debug] for a DEBUG value.
$debugConfiguration = $configurationSettings['script']['debug'] ?? null;

// If this value isn't set then DEBUG is null and we ignore it.
if (isset($debugConfiguration) === false) {
    $debugConfiguration = null;
} else {
    // Check for possible bool and string values.
    if (strtolower($debugConfiguration) == "true" || $debugConfiguration === true) {
        $debugConfiguration = true;
    } elseif (strtolower($debugConfiguration) == "false" || $debugConfiguration === false) {
        $debugConfiguration = false;
    } else {
        // Set to null if anything else.
        $debugConfiguration = null;
    }
}

// Fetch the env var PROCESSPROQUEST_DEBUG value with getenv(). 
// This will return a string, or false if there is not value.
$debugEnvVar = getenv('PROCESSPROQUEST_DEBUG');
if ($debugEnvVar === false) {
    $debugEnvVar = null;
} else {
    // Check for string values.
    if (strtolower($debugEnvVar) === "true") {
        $debugEnvVar = true;
    } elseif (strtolower($debugEnvVar) === "false") {
        $debugEnvVar = false;
    } else {
        // Set to null if anything else.
        $debugEnvVar = null;
    }
}

/*
 * Debug value is set in order of importance and will override those of lesser importance:
 *  1) $debugEnvVar         - PROCESSPROQUEST_DEBUG environmental variable.
 *  2) $debugConfiguration  - [script][debug] from the configuration file.
 *  3) $debugDefault        - default value.
*/

if ( isset($debugEnvVar) ) {
    $debug = $debugEnvVar;
} elseif ( isset($debugConfiguration) ) {
    $debug = $debugConfiguration;
} else {
    $debug = $debugDefault;
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
$outputStatus = "";
$outputFlags = Array();
if ($debug || $dryrunOption) {
    if ($debug) {
        array_push($outputFlags, "DEBUG");
    }
    if ($dryrunOption) {
        array_push($outputFlags, "DRYRUN");
    }
    $outputStatus = "!" . implode("|", $outputFlags) . "!";
    $output = "[%datetime%] $outputStatus >%extra% %message% %context%\n";
} else {
    $output = "[%datetime%] >%extra% %message% %context%\n";
}

// Create a log formatter.
$formatter = new LineFormatter(
    $output,            // Format of message.
    $dateFormatLogger,  // Date string format.
    true,               // allowInlineLineBreaks = true; default false.
    true                // ignoreEmptyContextAndExtra = true; default false.
);

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

require_once 'src/ProquestFTP.php';
use \Processproquest\FTP as FTP;
$urlFTP = $configurationSettings['ftp']['server'];
$ftpService = new FTP\FTPServicePHPAdapter($urlFTP);
try {
    $ftpConnection = new FTP\ProquestFTP($ftpService);
} catch (FTP\FTPConnectionException $e) {
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

require_once 'src/Repository.php';
use \Processproquest\Repository as REPO;
$fedoraUrl = $configurationSettings['fedora']['url'];
$fedoraUsername = $configurationSettings['fedora']['username'];
$fedoraPassword = $configurationSettings['fedora']['password'];
$tuqueLibraryLocation = $configurationSettings['packages']['tuque'];

try {
    $repositoryService = new REPO\FedoraRepositoryServiceAdapter($tuqueLibraryLocation, $fedoraUrl, $fedoraUsername, $fedoraPassword);
} catch (Exception | RepositoryServiceException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Exiting.\n";
    // TODO: send email notification.
    exit(1);
}

try {
    $FedoraRepositoryWrapper = new REPO\FedoraRepositoryWrapper($repositoryService);
} catch (Exception | RepositoryWrapperException $e) {
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
 * a FedoraConnection object, and a dry-run flag.
 */

require_once 'src/Processproquest.php';
use \Processproquest as PP;

$process = (new PP\Processproquest($configurationFile, $configurationSettings, $logger))
            ->setFTPConnection($ftpConnection)
            ->setFedoraConnection($FedoraRepositoryWrapper)
            ->setDebug($debug)
            ->setDryrun($dryrunOption);

// Display the initial status of the script.
$process->initialStatus();

/**
 * 
 * Process ETD ingest workflow.
 * 
 */

// Log into the FTP server.
try {
    $process->logIntoFTPServer();
} catch(PP\ProcessingException $e) {
    $logger->error("ERROR: " . $e->getMessage());
    $process->postProcess();
    $logger->info("Exiting. Error code: 1000");
    exit(1);
}

// Scan for ETD files.
try {
    $allETDs = $process->scanForETDFiles();
} catch(PP\ProcessingException $e) {
    $logger->error("ERROR: " . $e->getMessage());
    $process->postProcess();
    $logger->info("Exiting. Error code: 1005");
    exit(1);
}

// Loop through each ETD file found and process it.
foreach ($allETDs as $etdRecord) {
    try {
        // Create FedoraRecordProcessor object and process it.
        $fedoraRecordProcessor = $process->createFedoraRecordProcessorObject($etdRecord);
        $process->processFile($fedoraRecordProcessor);
    } catch(PP\ProcessingException | \Exception $e) {
        $logger->error("ERROR: " . $e->getMessage());
        $logger->error("Error code: 1010");
        $logger->error("Continuing to the next ETD file.");
        continue;
    }
}

// Run postProcess() to clean up and send out email notification.
$process->postProcess();
$logger->info("Script complete. Exiting.");
exit(1);

/**
 * Output usage strings.
 */
function usage() {
    echo "processProquest\n\n";
    echo "This script loads and processes ProQuest ETD files and ingests them into a Fedora/Islandora 7 instance.\n\n";
    echo "Usage:\n";
    echo "  php index.php\n";
    echo "  php index.php [-c|--configuration] [-d|--dry-run]\n";
    echo "  php index.php -h | --help\n\n";
    echo "Options:\n";
    echo "   -c --configuration Custom configuration file. This accepts an INI formatted file.";
    echo " If left blank then the script will scan for a local file by the name of 'processProquest.ini'\n";
    echo "   -r --dry-run       This runs through the script but prevents any Fedora records from being";
    echo " ingested as a final workflow step.\n";
    echo "   -h --help          This usage message.\n\n";
    echo "See README.md for configuration file information.\n";
}

/**
 * Load a valid configuration file.
 *
 * @param string $configurationFile The name/path of the configuration file to load.
 * 
 * @return object|NULL Return the configuration settings or NULL on error.
 */
function loadConfigurationSettings($configurationFile) {
    if (empty($configurationFile) === true) {
        echo "ERROR: No configuration file was received.\n";
        return null;
    }

    // Check if the validity of the configuration file.
    if (!validateConfig($configurationFile)){
        // Configuration file is invalid and script can not continue.
        return NULL;
    }

    // Read in configuration settings.
    $configurationSettings = parse_ini_file($configurationFile, true);

    if (empty($configurationSettings)) {
        echo "ERROR: This file is malformed or did not contain any INI settings.";
        return NULL;
    }

    return $configurationSettings;
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
