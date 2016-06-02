<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

error_reporting(E_ALL);

$fontpath = realpath('/usr/share/fonts/truetype/freefont/');
putenv('GDFONTPATH='.$fontpath);

require_once 'processProquest.php';

if (!$argv){
    usage();
    exit(1);
}

$process = new processProquest($argv[1]);

// Initialize ftp connection
$process->initFTP();

// Get zip files, unzip and store locally
$process->getFiles();

//
$process->initFedoraConnection();

// Process each  
$process->processFiles();

// Ingest
$process->ingest();


exit(1);

function usage() {
    echo "Usage: php processProquest.php configFile\n";
}

?>
