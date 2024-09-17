<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

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

echo "configurationFile: {$configurationFile}\n";

// Exit if configuration file is invalid.
if(is_null($configurationSettings)){
    usage();
    exit(1);
}

// Debug is off by default.
$debugDefault = false;

// Check debug value from $configurationSettings
$debugConfiguration = NULL;
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

// echo "debugDefault: " . ($debugDefault ? 'TRUE' : 'FALSE') . "\n";
// echo "debugConfiguration: {$debugConfiguration}\n";
// echo "PROCESSPROQUEST_DEBUG: {$debugEnvVar}\n";
// echo "\nDebug: {$debug}\n";

require_once 'processProquest.php';

// Create the $process object.
$process = new processProquest($configurationArray, $debug);

// Initialize FTP connection.
// Exit when an exception is caught.
try {
    $process->initFTP();
} catch(Exception $e) {
    echo "Exiting.\n";
    exit(1);
}

// Get zip files from FTP server, unzip and store locally.
// Exit when an exception is caught.
try {
    $process->getFiles();
} catch(Exception $e) {
    echo "Exiting.\n";
    exit(1);
}

// Connect to Fedora through API.
if (!$process->initFedoraConnection()) {
    echo "Could not make a connection to the Fedora repository. Exiting.";
    exit(1);
}

// Process each zip file.
// Exit when an exception is caught.
try {
    $process->processFiles();
} catch(Exception $e) {
    echo "Exiting.\n";
    exit(1);
}

// Ingest each processed zip file into Fedora.
// Exit when an exception is caught.
try {
    $process->ingest();
} catch(Exception $e) {
    echo "Exiting.\n";
    exit(1);
}

/**
 * Output usage strings.
 *
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
