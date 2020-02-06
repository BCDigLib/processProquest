<?php

error_reporting(E_ALL);

/**
 * Description of processProquest
 *
 * @author MEUSEB
 * 
 * annotations by Jesse Martinez.
 */

/* 
 * Islandora/Fedora library.
 */
require_once '../tuque/RepositoryConnection.php';
require_once '../tuque/FedoraApi.php';
require_once '../tuque/FedoraApiSerializer.php';
require_once '../tuque/Repository.php';
require_once '../tuque/RepositoryException.php';
require_once '../tuque/FedoraRelationships.php';
require_once '../tuque/Cache.php';
require_once '../tuque/HttpConnection.php';

/**
 * Custom FTP connection handler.
 */
require_once 'proquestFTP.php';

/* 
 * BC Islandora definitions.
 */
define('ISLANDORA_BC_ROOT_PID', 'bc-ir:GraduateThesesCollection');
define('ISLANDORA_BC_ROOT_PID_EMBARGO', 'bc-ir:GraduateThesesCollectionRestricted');
define('ISLANDORA_BC_XACML_POLICY','POLICY');
define('GRADUATE_THESES','bc-ir:GraduateThesesCollection');
define('GRADUATE_THESES_RESTRICTED','bc-ir:GraduateThesesCollectionRestricted');

/**
 * Batch processes Proquest ETDs.
 * 
 * This class allows for the following workflow:
 *  - Initialize FTP server connection.
 *  - Gathers and extracts the ETD zip files from FTP server onto a local directory.
 *  - Generates metadata files from ETD zip file contents.
 *  - Initialize connection to Fedora file repository server.
 *  - Ingests ETD files and metadata into Fedora, and generates various datastreams.
 */
class processProquest {

    public $settings;
    protected $ftp;
    protected $localFiles;      // array
    protected $connection;
    protected $api;
    protected $api_m;
    protected $repository;
    protected $toProcess = 0;   // Number of PIDs for supplementary files. 

    /**
     * Class constructor. 
     * 
     * This builds a local '$this' object that contains various script settings. 
     */
    public function __construct($config){
        $this->settings = parse_ini_file($config, true);
    }

    /**
     * Initializes an FTP connection.
     * 
     * Calls on proquestFTP.php
     */
    function initFTP() {
        // TODO: sanity check that proquestPHP file exists.
        // TODO: error handling.

        echo "Initializing FTP connection...\n";

        $urlFTP = $this->settings['ftp']['server'];
        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        // Create ftp object used for connection.
        $this->ftp = new proquestFTP($urlFTP);

        // Set session time out. Default is 90.
        $this->ftp->ftp_set_option(FTP_TIMEOUT_SEC, 150);

        // Pass login credentials to login method.
        $this->ftp->ftp_login($userFTP, $passwordFTP);
    }


    /**
     * Gather ETD zip files from FTP server.
     * 
     * Create a local directory for each zip file from FTP server and save into directory. 
     * Local directory name is based on file name. 
     * Next, varify that PDF and XML files exist. Also keep track of supplementary files. 
     * Lastly, expand zip file contents into local directory. 
     */
    function getFiles() {

        echo "Fetching files...\n";

        // Look at specific directory on FTP server for ETD files. Ex: /path/to/files
        // TODO: handle OS directory path errors.
        $fetchdirFTP = $this->settings['ftp']['fetchdir'];

        // Define local directory for file processing. Ex: /tmp/processed
        // TODO: handle OS directory path errors.
        $localdirFTP = $this->settings['ftp']['localdir'];

        // Check if the FTP file directory is root.
        // TODO: manage when $fetchdirFTP is not an empty string!
        if ($fetchdirFTP != "")
        {
            $this->ftp->ftp_chdir($fetchdirFTP);
        }

        /**
         * Look for files that begin with a specific string. 
         * In our specific case the file prefix is "etdadmin_upload".
         * Save results into $etdFiles array.
         */
        // TODO: define "etdadmin_upload" string as a constant.
        $etdFiles = $this->ftp->ftp_nlist("etdadmin_upload*");

        // TODO: check if $etdFiles is an empty array and handle some type of error message.
        // TODO: make sure file name (including extension) is more than four chars.

        /**
         * Loop through each match in $etdFiles. 
         * There may be multiple matched files so process each individually.
         */
        foreach ($etdFiles as $filename) {
            /**
             * Set the directory name for each ETD file. 
             * This is based on the file name sans the file extension. 
             * Ex: etd_file_name_1234.zip -> /tmp/processing/etd_file_name_1234
             */
            // TODO: check that file names are more than four chars.
            $etdDir = $localdirFTP . substr($filename,0,strlen($filename)-4);

            echo "Creating temp storage directory: " . $etdDir . "\n";

            // Create the local directory.
            // TODO: error handling.
            mkdir($etdDir, 0755);
            $localFile = $etdDir . "/" .$filename;

            // Be sure to sleep just to avoid parallel methods not completing!
            sleep(2);

            /**
             * Gets the file from the FTP server.
             * Saves it locally to $localFile (Ex: /tmp/processing/file_name_1234).
             * File is saved locally as a binary file.
             */
            // TODO: error handling.
            $this->ftp->ftp_get($localFile, $filename, FTP_BINARY);

            // Store location of local directory if it hasn't been stored yet.
            if(isset($this->localFiles[$etdDir])){
                $this->localFiles[$etdDir];
            }

            // TODO: check that we are in fact processing a zip file.
            $ziplisting = zip_open($localFile);
            $supplement = 0;

            // Go through entire zip file and process contents.
            // TODO: error handling.
            while ($zip_entry = zip_read($ziplisting)) {
                // Get file name.
                $file = zip_entry_name($zip_entry);

                /** 
                 * Match for a specific string in file.
                 * 
                 * Make note of expected files:
                 *  - PDF.
                 *  - XML.
                 *  - all else (AKA supplementary files).
                 * 
                 *  The String "0016" is specific to BC.
                 */
                // TODO: track or log non-BC files?
                if (preg_match('/0016/', $file)) { 
                    // Check if this is a PDF or XML file.
                    // TODO: check that file names are more than four chars.
                    // TODO: handle string case in comparison.
                    if (substr($file,strlen($file)-3) === 'pdf') {
                        $this->localFiles[$etdDir]['ETD'] = $file;
                    } elseif (substr($file,strlen($file)-3) === 'xml') {
                        $this->localFiles[$etdDir]['METADATA'] = $file;
                    } else {
                        /**
                         * Supplementary files - could be permissions or data.
                         * Metadata will contain boolean key for permission in DISS_file_descr element.
                         * [0] element should always be folder.
                         */
                        $this->localFiles[$etdDir]['UNKNOWN'.$supplement] = $file;
                        $supplement++;
                    }
                }

                /**
                 * TODO: sanity check that both:
                 *  - $this->localFiles[$etdDir]['ETD']
                 *  - $this->localFiles[$etdDir]['METADATA'] 
                 * are defined and are nonempty strings.
                 */
            }

            echo "Extracting files...\n";
            $zip = new ZipArchive;

            // Open and extract zip file to local directory.
            // TODO: error handling.
            $zip->open($localFile);
            $zip->extractTo($etdDir);
            $zip->close();
        }
    }

    /**
     * Generate metadata from gathered ETD files.
     * 
     * 
     */
    function processFiles() {

        // TODO: check if $this->localFiles is a non-empty array.

        /**
         * Load Proquest MODS XSLT stylesheet.
         * Ex: /path/to/proquest/crosswalk/Proquest_MODS.xsl
         */
        $xslt = new xsltProcessor;
        $proquestxslt = new DOMDocument();
        $proquestxslt->load($this->settings['xslt']['xslt']);
        $xslt->importStyleSheet($proquestxslt);

        /** 
         * Load Fedora Label XSLT stylesheet.
         * Ex: /path/to/proquest/xsl/getLabel.xsl
         */
        $label = new xsltProcessor;
        $labelxslt = new DOMDocument();
        $labelxslt->load($this->settings['xslt']['label']);
        $label->importStyleSheet($labelxslt);

        /**
         * Given the array of ETD local files, generate additional metadata.
         * This will generate:
         *  - MODS metadata.
         *  - OA permissions.
         *  - Embargo settings.
         *  - PID, title, author values.
         */
        foreach ($this->localFiles as $directory => $submission) {
            /**
             * Generate MODS Metadata first.
             * Done for every submission regardless of OA permissions.
             */
            echo "Processing " . $directory . "\n";

            // Create XPath object from the ETD XML file. 
            $metadata = new DOMDocument();
            $metadata->load($directory . '//' . $submission['METADATA']);
            $xpath = new DOMXpath($metadata);

            /** 
             * Get OA permission.
             * This looks for the existance of an "oa" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_acceptance/text()
             */
            $openaccess = 0;
            $oaElements = $xpath->query($this->settings['xslt']['oa']);
            if ($oaElements->length === 0 ) {
                //$openaccess = 0;
		        echo "No OA agreement found\n";
            } elseif ($oaElements->item(0)->C14N() === '0') {
                //$openaccess = 0;
		        echo "No OA agreement found\n";
            } else {
                $openaccess = $oaElements->item(0)->C14N();
		        echo "OA agreement found\n";
            }

            $this->localFiles[$directory]['OA'] = $openaccess;

            /**
             * Get embargo permission/dates. 
             * This looks for the existance of an "embargo" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_delayed_release/text()
             */
            $embargo = 0;
            $emElements = $xpath->query($this->settings['xslt']['embargo']);
            if ($emElements->item(0) ) {
                // Convert date string into proper PHP date object format.
                $embargo = $emElements->item(0)->C14N();
                $embargo = str_replace(" ","T",$embargo);
                $embargo = $embargo . "Z";
                $this->localFiles[$directory]['EMBARGO'] = $embargo;
            }

            /**
             * Check to see if the OA and embargo permissions match.
             * If so, set the embargo permission/date to "indefinite".
             */
            // TODO: should this be a corresponding ELSEIF clause to the previous IF clause?
            //       This looks like $embargo would only match $openaccess if they are both 0.
            if ($openaccess === $embargo) {
                $embargo = 'indefinite';
                $this->localFiles[$directory]['EMBARGO'] = $embargo;
		        echo "Embargo date is " . $embargo . "\n";
            }

            /**
             * Fetch next PID from Fedora.
             * Prepend PID with locally defined Fedora namespace.
             * Ex: "bc-ir" for BC.
             */
            $pid = $this->api_m->getNextPid($this->settings['fedora']['namespace'], 1);
            $this->localFiles[$directory]['PID'] = $pid;

	        echo "Record PID is " . $pid . "\n";

            /**
             * Insert the PID value into the Proquest MODS XSLT stylesheet.
             * The "handle" value should be set the PID.
             */
            $xslt->setParameter('mods', 'handle', $pid);

            /**
             * Generate MODS file.
             * This file is generated by applying the Proquest MODS XSLT stylesheet to the ETD XML file.
             * Additional metadata will be generated from the MODS file.
             */
            $mods = $xslt->transformToDoc($metadata);

            /**
             * Generate ETD title/Fedora Label.
             * The title is generated by applying the Fedora Label XSLT stylesheet to the above generated MODS file.
             * This uses mods:titleInfo.
             */
            $fedoraLabel = $label->transformToXml($mods);
            $this->localFiles[$directory]['LABEL'] = $fedoraLabel;

	        echo "Title is " . $fedoraLabel . "\n";

            /**
             * Generate ETD author.
             * This looks for the existance of an "author" node in the MODS XPath object.
             * Ex: /mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()
             */
            $xpathAuthor = new DOMXpath($mods);
            $authorElements = $xpathAuthor->query($this->settings['xslt']['creator']);
            $author = $authorElements->item(0)->C14N();

            /**
             * Normalize the ETD author string. This forms the internal file name convention.
             * Ex: Jane Anne O'Foo => Jane-Anne-OFoo
             */
            // TODO: Need to add unicode replacements.
            $normalizedAuthor = str_replace(array(" ",",","'",".","&apos;"), array("-","","","",""), $author);

            // Create placeholder full-text text file using normalized author's name.
            $this->localFiles[$directory]['FULLTEXT'] = $normalizedAuthor . ".txt";

            // Rename Proquest PDF using normalized author's name.
            // TODO: error handling.
            rename($directory . "/". $submission['ETD'] , $directory . "/" . $normalizedAuthor . ".pdf");

            // Update local file path for ETD PDF file.
            $this->localFiles[$directory]['ETD'] = $normalizedAuthor . ".pdf";

            // Save MODS using normalized author's name.
            // TODO: error handling.
            $mods->save($directory . "/" . $normalizedAuthor . ".xml");

            // Update local file path for MODS file.
            $this->localFiles[$directory]['MODS'] = $normalizedAuthor . ".xml";

            /**
             * Check for supplemental files.
             * This looks for the existance of an "DISS_attachment" node in the ETD XML XPath object.
             * Ex: /DISS_submission/DISS_content/DISS_attachment
             * 
             * Previous comments (possibly outdated):
             *    UNKNOWN0 in lookup should mean there are other files
             *    also, Proquest MD will have DISS_attachment
             *    ($this->localFiles[$directory]['UNKNOWN0']) or
             */
            $suppxpath = new DOMXpath($metadata);
            $suElements = $suppxpath->query($this->settings['xslt']['supplement']);

            // Check if there are zero or more supplemental files. 
            if ($suElements->item(0) ) {
                $this->localFiles[$directory]['PROCESS'] = "0";
            } else {
                $this->localFiles[$directory]['PROCESS'] = "1";

                // Keep track of how many additional PIDs will need to be generated.
                $this->toProcess++;
            }

            echo "\n\n";
        }
    }

    /**
     * Initializes a connection to a Fedora file repository server.
     */
    function initFedoraConnection() {

        $this->connection = new RepositoryConnection($this->settings['fedora']['url'],
                                                     $this->settings['fedora']['username'],
                                                     $this->settings['fedora']['password']);

        // TODO: error handling.
        $this->api = new FedoraApi($this->connection);
        $this->repository = new FedoraRepository($this->api, new simpleCache());

        // Fedora Management API.
        $this->api_m = $this->repository->api->m; 

    }

    /**
     *
     */
    function ingest() {

        echo "\n\nNow ingesting files...\n\n";

        $pidcount = 0;
        $fop = '../../modules/boston_college/data/fop/cfg.xml';

        // Initialize messages for notification email
        $successMessage = "The following ETDs ingested successfully:\n\n";
        $failureMessage = "\n\nThe following ETDs failed to ingest:\n\n";
        $processingMessage = "\n\nThe following directories were processed in {$this->settings['ftp']['localdir']}:\n\n";

        foreach ($this->localFiles as $directory => $submission) {
            echo "Processing " . $directory . "\n";
            $processingMessage .= $directory . "\n";

            if ($this->localFiles[$directory]['PROCESS'] === '1') {
                // Still Load - but notify admin about supp files
                echo "Supplementary files found\n";
            }

            $object = $this->repository->constructObject($this->localFiles[$directory]['PID']);

            $object->label = $this->localFiles[$directory]['LABEL'];

            $object->owner = 'fedoraAdmin';

	        echo "Fedora object created\n";

            /**
             * Generate RELS-EXT
             */

            // POLICY Get Parent POLICY to add to all ingested records
            $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID);

            $collection = GRADUATE_THESES;
            if (isset($this->localFiles[$directory]['EMBARGO'])) {
	            echo "Adding to Graduate Theses (Restricted) collection\n";
                $collection = GRADUATE_THESES_RESTRICTED;
                $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID_EMBARGO);
            } else {
                echo "Adding to Graduate Theses Collection\n";
            }
            $object->models = array('bc-ir:graduateETDCModel');
            $object->relationships->add(FEDORA_RELS_EXT_URI,
                                        'isMemberOfCollection',
                                        $collection);

            $object->checksumType = 'SHA-256';

            $object->state = 'I';

            echo "Adding XACML policy\n";
            $policy = $parentObject->getDatastream(ISLANDORA_BC_XACML_POLICY);

            /**
             * MODS Datastream
             */
            $dsid = 'MODS';

            $datastream = $object->constructDatastream($dsid, 'X');

            $datastream->label = 'MODS Record'; //$this->localFiles[$directory]['LABEL'];
            $datastream->mimeType = 'application/xml';
            $datastream->setContentFromFile($directory . "//" . $this->localFiles[$directory]['MODS']);

            $object->ingestDatastream($datastream);
            echo "Ingested MODS datastream\n";

            /**
             * Original Proquest Metadata will be saved as ARCHIVE
             * Original filename is used as label for identification
             */
            $dsid = 'ARCHIVE';

            $datastream = $object->constructDatastream($dsid, 'X');

            $datastream->label = substr($this->localFiles[$directory]['METADATA'], 0, strlen($this->localFiles[$directory]['METADATA'])-4);

            $datastream->mimeType = 'application/xml';
            $datastream->setContentFromFile($directory . "//" . $this->localFiles[$directory]['METADATA']);

            $datastream->checksumType = 'SHA-256';

            $datastream->state = 'I';

            $object->ingestDatastream($datastream);
            echo "Ingested ARCHIVE datastream\n";

            /**
             * PDF will always be loaded as ARCHIVE-PDF DSID
             * regardless of embargo - splash paged PDF will
             * be PDF dsid
             */
            $dsid = 'ARCHIVE-PDF';
            $datastream = $object->constructDatastream($dsid); // Default Control Group is M

            $datastream->label = 'ARCHIVE-PDF Datastream'; //$this->localFiles[$directory]['LABEL'];
            $datastream->mimeType = 'application/pdf';
            $datastream->setContentFromFile($directory . "//" . $this->localFiles[$directory]['ETD']);

            $datastream->checksumType = 'SHA-256';

            $datastream->state = 'I';

            $object->ingestDatastream($datastream);
	        echo "Ingested ARCHIVE-PDF datastream\n";

            /**
             * PDF with splash page
             */
            $dsid = "PDF";
            $datastream = $object->constructDatastream($dsid); // Default Control Group is M

            $source = $directory . "/" . $this->localFiles[$directory]['MODS'];

            $executable = "/usr/bin/fop -c $fop";
            $splashtemp = $directory . "/splash.pdf";
            $splashxslt = $this->settings['xslt']['splash'];

            $command = "$executable -xml $source -xsl $splashxslt -pdf $splashtemp";
            exec($command, $output, $return);

    		if (!$return) {
    		    echo "PDF splash page created successfully\n";
    		} else {
    		    echo "PDF splash page creation unsuccessful. Exiting...\n";
    		    break;
    		}

            $this->localFiles[$directory]['SPLASH'] = 'splash.pdf';

            /**
             * Load Splash to PDF if under embargo
             */
            $executable = '/usr/bin/pdftk';

            $concattemp = $directory . "/concatted.pdf";
            $pdf = $directory . "//" . $this->localFiles[$directory]['ETD'];

            $command = "$executable $splashtemp $pdf cat output $concattemp";
            exec($command, $output, $return);

            if (!$return) {
                echo "Splash page concatenated successfully\n";
            } else {
                echo "Splash page concatenation unsuccessful. Exiting...\n";
                break;
            }

            $datastream->label = 'PDF Datastream';
            $datastream->mimeType = 'application/pdf';
            $datastream->setContentFromFile($concattemp);

            $datastream->checksumType = 'SHA-256';

            $object->ingestDatastream($datastream);
            echo "Ingested PDF with splash page\n";

            /**
             * FULL_TEXT
             */
            $dsid = "FULL_TEXT";

            $source = $directory . "/" . $this->localFiles[$directory]['ETD'];

            $executable = '/usr/bin/pdftotext';
            $fttemp = $directory . "/fulltext.txt";

            $command = "$executable $source $fttemp";

            exec($command, $output, $return);

            if (!$return) {
                echo "FULL TEXT datastream generated successfully\n";
            } else {
                echo "FULL TEXT generation unsuccessful. Exiting...\n";
                break;
            }

            $datastream = $object->constructDatastream($dsid);

            $datastream->label = 'FULL_TEXT';
            $datastream->mimeType = 'text/plain';

            // Read in FT and strip junky characters that mess up SOLR
            $fulltext = file_get_contents($fttemp);

            $replacement = '';
            $sanitized = preg_replace('/[\x00-\x1f]/', $replacement, $fulltext);

            $datastream->setContentFromString($sanitized);

            $object->ingestDatastream($datastream);

            echo "Ingested FULL TEXT datastream\n";

            /**
             * TN
             */
            $dsid = "TN";

            $source = $directory . "/" . $this->localFiles[$directory]['ETD'] . "[0]";

            $executable = '/usr/bin/convert';

            $command = "$executable $source -quality 75 -resize 200x200 -colorspace RGB -flatten " . $directory . "/thumbnail.jpg";

            exec($command, $output, $return);

            if (!$return) {
                echo "TN datastream generated successfully\n";
            } else {
                echo "TN generation unsuccessful. Exiting...\n";
                break;
            }

            $datastream = $object->constructDatastream($dsid);

            $datastream->label = 'TN';
            $datastream->mimeType = 'image/jpeg';
            $datastream->setContentFromFile($directory . "//thumbnail.jpg");

            $object->ingestDatastream($datastream);

            echo "Ingested TN datastream\n";

            /**
             * PREVIEW
             */
            $dsid = "PREVIEW";

            $source = $directory . "/" . $this->localFiles[$directory]['ETD'] . "[0]";

            $executable = '/usr/bin/convert';

            $command = "$executable $source -quality 75 -resize 500x700 -colorspace RGB -flatten " . $directory . "/preview.jpg";

            exec($command, $output, $return);

            if (!$return) {
                echo "PREVIEW datastream generated successfully\n";
            } else {
                echo "PREVIEW generation unsuccessful. Exiting...\n";
                break;
            }

            $datastream = $object->constructDatastream($dsid);

            $datastream->label = 'PREVIEW';
            $datastream->mimeType = 'image/jpeg';
            $datastream->setContentFromFile($directory . "//preview.jpg");

            $object->ingestDatastream($datastream);
            echo "Ingested PREVIEW datastream\n";

            // POLICY
            $object->ingestDatastream($policy);
            echo "Ingested XACML datastream\n";

            /**
             * Check if OA
             * Set Embargo is there is one
             * Permanent?
             */

            //Initialize $relsint or the script will fail
            $relsint = '';
            if ($submission['OA'] === 0) {
                $relsint =  file_get_contents('xsl/permRELS-INT.xml');
                $relsint = str_replace('######', $submission['PID'], $relsint);
            } else if (isset($submission['EMBARGO'])) {
                $relsint =  file_get_contents('xsl/embargoRELS-INT.xml');
                $relsint = str_replace('######', $submission['PID'], $relsint);
                $relsint = str_replace('$$$$$$', $submission['EMBARGO'], $relsint);
            }

            if (isset($relsint) && $relsint !== '') {
                $dsid = "RELS-INT";

                $datastream = $object->constructDatastream($dsid);
                $datastream->label = 'Fedora Relationship Metadata';
                $datastream->mimeType = 'application/rdf+xml';
                $datastream->setContentFromString($relsint);

                $object->ingestDatastream($datastream);
                echo "Ingested RELS-INT datastream\n";
            }

            // Get the zip filename on the FTP server of the ETD being processed.
            // We'll use this in the conditional below to move the ETD on the
            // remove server accordingly.
            $directoryArray = explode('/', $directory);
            $fnameFTP = array_values(array_slice($directoryArray, -1))[0] . '.zip';

            if ($this->repository->ingestObject($object)) {
                echo "Object ingested successfully\n";

                $pidcount++;
                $successMessage .= $submission['PID'] . "\t";

                if (isset($submission['EMBARGO'])) {
                    $successMessage .= "EMBARGO UNTIL: " . $submission['EMBARGO'] . "\t";
                } else {
                    $successMessage .= "NO EMBARGO" . "\t";
                }
                $successMessage .= $submission['LABEL'] . "\n";

                $processdirFTP = $this->settings['ftp']['processdir'];
                $this->ftp->ftp_rename($fnameFTP, $processdirFTP . '/' . $fnameFTP);
            } else {
                echo "Object failed to ingest\n";

                $pidcount++;
                $failureMessage .= $submission['PID'] . "\t";

                if (isset($submission['EMBARGO'])) {
                    $failureMessage .= "EMBARGO UNTIL: " . $submission['EMBARGO'] . "\t";
                } else {
                    $failureMessage .= "NO EMBARGO" . "\t";
                }
                $failureMessage .= $submission['LABEL'] . "\n";

                $faildirFTP = $this->settings['ftp']['faildir'];
                $this->ftp->ftp_rename($fnameFTP, $faildirFTP . '/' . $fnameFTP);
            }

            // JJM
            sleep(2);
            echo "\n\n\n\n";
        }

        // Do not show failure message in notification if no ETDs failed
        // (same with success message, but hopefully we won't have that problem!)
        if ($failureMessage == "\n\nThe following ETDs failed to ingest:\n\n") {
            mail($this->settings['notify']['email'],"Message from processProquest",$successMessage . $processingMessage);
        } elseif ($successMessage == "The following ETDs successfully ingested:\n\n") {
            mail($this->settings['notify']['email'],"Message from processProquest",$failureMessage . $processingMessage);
        } else {
            mail($this->settings['notify']['email'],"Message from processProquest",$successMessage . $failureMessage . $processingMessage);
        }

    }
}
?>
