<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

error_reporting(E_ALL);

#$fontpath = realpath('/usr/share/fonts/truetype/freefont/');
$fontpath = realpath('/usr/share/fonts/truetype/freefont/');
putenv('GDFONTPATH='.$fontpath);

require_once 'processProquest.php';

// Requires a single parameter containing the location of an initialization file.
if (!isset($argv[1])){
    usage();
    exit(1);
}

// Debug is off by default
$debug = false;

// Create the $process object.
$process = new processProquest($argv[1], $debug);

// Initialize FTP connection.
$process->initFTP();

// Get zip files from FTP server, unzip and store locally.
$process->getFiles();

// Connect to Fedora through API.
$process->initFedoraConnection();

// Process each zip file.
$process->processFiles();

// Ingest each processed zip file into Fedora.
$process->ingest();

exit(1);

function usage() {
    echo "Usage: php index.php processProquest.ini\n";
    echo "(See README.md for configuration info)";
}

?>
