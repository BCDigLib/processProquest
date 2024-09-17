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

// Load configuration file.
$configurationFile = getValidConfigurationFile($argv);

// Exit if configuration file is invalid.
if(is_null($configurationFile)){
    //echo "Configuration file is invalid and script can not continue.\n";
    usage();
    exit(1);
}

require_once 'processProquest.php';

// Debug is off by default
$debug = false;

// Create the $process object.
$process = new processProquest($configurationFile, $debug);

// Initialize FTP connection.
// Exit when an exception is caught.
try {
    $process->initFTP();
} catch(Exception $e) {
    exit(1);
}

// Get zip files from FTP server, unzip and store locally.
// Exit when an exception is caught.
try {
    $process->getFiles();
} catch(Exception $e) {
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
    exit(1);
}

// Ingest each processed zip file into Fedora.
// Exit when an exception is caught.
try {
    $process->ingest();
} catch(Exception $e) {
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
 * @return string|NULL Return a filename string or NULL on error.
 */
function getValidConfigurationFile($arguments) {
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
    return $configurationFile;
}

/**
 * Validate configuration file.
 * 
 * Check if a configuration file exists, and if it is empty.
 *
 * @param string $configurationFile The configuration file name.
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

    // TODO: check if the file contains usable values.

    return true;
}

exit(1);

?>
