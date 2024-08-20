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

require_once '/var/www/html/drupal/sites/all/libraries/tuque/RepositoryConnection.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/FedoraApi.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/FedoraApiSerializer.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/Repository.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/RepositoryException.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/FedoraRelationships.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/Cache.php';
require_once '/var/www/html/drupal/sites/all/libraries/tuque/HttpConnection.php';


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
define('DEFAULT_LOG_FILE_LOCATION', '/tmp/proquest-log/');
define('DEFAULT_DEBUG_VALUE', false);

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
    public $debug;
    protected $ftp;
    protected $localFiles;      // array
    protected $connection;
    protected $api;
    protected $api_m;
    protected $repository;
    protected $toProcess = 0;   // Number of PIDs for supplementary files.
    protected $logFile = "";
    protected $logError = false;

    /**
     * Class constructor.
     *
     * This builds a local '$this' object that contains various script settings.
     *
     * @param string $config An ini file containing various configurations.
     * @param bool $debug Run script in debug mode, which doesn't ingest ETD into Fedora.
     */
    public function __construct($config, $debug = DEFAULT_DEBUG_VALUE) {
        $this->settings = parse_ini_file($config, true);

        // Verify that $debug is a bool value.
        if ( is_bool($debug) ){
            $this->debug = $debug;
        } else {
            $this->debug = DEFAULT_DEBUG_VALUE;
        }

        $this->writeLog("Starting processProquest script.", "");
        $this->writeLog("Running with DEBUG value: " . ($this->debug ? "TRUE" : "FALSE"), "");
    }

    /**
     * Initialize logging file.
     *
     * @param string $file_name The name to give the log file.
     * @return boolean Log init status.
     */
    private function initLog($file_name = null) {
        // Set log file name.
        if ( is_null($file_name) ) {
            $file_name = "ingest";
        }

        $date = date("Ymd-His", time());

        // Set log location in case DEFAULT_LOG_FILE_LOCATION or $this->settings['log']["location"] isn't set.
        $log_location = "/tmp/processProquest-test/";
        if ( isset($this->settings['log']["location"]) ) {
            $log_location = $this->settings['log']["location"];
        } else if (defined(DEFAULT_LOG_FILE_LOCATION) == TRUE) {
            $log_location = DEFAULT_LOG_FILE_LOCATION;
        } else {
            // DEFAULT_LOG_FILE_LOCATION really should be set in this class.
            //return false;
        }

        // Build final log path and name. Ex: /var/log/processProquest/log-20200216-123456.txt
        $this->logFile = $log_location . $file_name . "-" . $date . ".txt";

        // Create file if it doesn't exist.
        if( !is_file($this->logFile) ) {
            $res = file_put_contents($this->logFile, "");

            // In case of complete file creation error.
            if ($res === false) {
                echo "ERROR: Can't write to log file! " . $res;
                $this->logError = true;
                return false;
            }
        }

        echo "Writing to log file: " . $this->logFile . "\n";

        return true;
    }

    /**
     * Simple logging.
     *
     * @param string $message The message to log.
     * @param string $etd The ETD name.
     * @return boolean Write status.
     */
    private function writeLog($message, $function_name = "", $etd = "") {
        // Check if there is a known issue with log writing.
        if ($this->logError === true){
            // Nothing we can do at this point.
            return false;
        }

        // Check if $this->$logFile is set, and run initLog if not.
        if ( empty($this->logFile) ) {
            $res = $this->initLog();

            // If initLog fails then we can't write to logs.
            if ($res === false) {
                return false;
            }
        }

        // Add some text wrapping to $etd, if set.
        if ( !empty($etd) ) {
            $etd = "[" . $etd . "]";
        }

        // Format the date and time. Ex: 16/Feb/2020:07:45:12
        $time = @date('[d/M/Y:H:i:s]');

        // Append message to the log file.
        if ($fd = @fopen($this->logFile, "a")) {
            //$result = fputcsv($fd, array($time, $message));
            $res = fwrite($fd, "$time ($function_name) $etd $message" . PHP_EOL);

            // Check if fwrite failed.
            if ($res === false) {
                // Only print this error message once.
                if ($this->logError === false) {
                    echo "ERROR: Can't write to log file! " . $res;
                    $this->logError = true;
                }

                return false;
            }

            fclose($fd);
        } else {
            // Only print this error message once.
            if ($this->logError === false) {
                echo "ERROR: Can't open log file! " . $res;
                $this->logError = true;
            }

            return false;
        }

        // Finally, output to stdout
        echo "$time ($function_name) $etd $message\n";

        return true;
    }

    /**
     * Send email notification.
     *
     * @param string $message The email body to send.
     * @return boolean Was the email sent successfully.
     */
    private function sendEmail($message) {
        $fn = "sendEmail";

        $log_location_message = "\n\nA detailed log file for this ingest has been generated on the server at this location: " . $this->logFile . " .";

        $email_to = $this->settings['notify']['email'];
        $email_subject = "Message from processProquest";
        $email_message = $message . $log_location_message;

        // Sanity checks.
        if ( empty($email_to) ) {
            $this->writeLog("ERROR: Email to: field is empty!", $fn);
            return false;
        }

        if ( empty($email_subject) ) {
            $this->writeLog("ERROR: Email subject: field is empty!", $fn);
            return false;
        }

        if ( empty($email_message) ) {
            $this->writeLog("ERROR: Email body: field is empty!", $fn);
            return false;
        }

        $this->writeLog("Attempting to send out the following email:\n\tto:[" . $email_to . "]\n\tbody:[" . $email_message . "]", $fn);

        // DEBUG: don't send email.
        $res = true;
        if ($this->debug === true) {
            $this->writeLog("DEBUG: Not sending email notification.", $fn);
        } else {
            $res = mail($email_to, $email_subject, $email_message);
	    return true;
        }

        // Check mail success.
        if ($res === false) {
            $this->writeLog("ERROR: Email not sent!", $fn);
            return false;
        }

        $this->writeLog("Email sent.", $fn);

        return true;
    }

    /**  
     * Strips out punctuation, spaces, and unicode chars from a string.
     * 
     * @return string A normalized string.
    */
    private function normalizeString($str) {
        # remove trailing spaces
        $str = trim($str);

        # replace spaces with dashes
        $str = str_replace(" ", "-", $str);

        # remove any character that isn't alphanumeric or a dash
        $str = preg_replace("/[^a-z0-9-]+/i", "", $str);

        return $str;
    }

    /**
     * Initializes an FTP connection.
     *
     * Calls on proquestFTP.php
     *
     * @return boolean Success value.
     */
    function initFTP() {
        $fn = "initFTP";

        $this->writeLog("Initializing FTP connection.", $fn);

        $urlFTP = $this->settings['ftp']['server'];
        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        if (empty($urlFTP) || empty($userFTP) || empty($passwordFTP)) {
            $this->writeLog("ERROR: FTP login values missing!", $fn);
            return false;
        }

        // Create ftp object used for connection.
        $this->ftp = new proquestFTP($urlFTP);

        // Set session time out. Default is 90.
        $this->ftp->ftp_set_option(FTP_TIMEOUT_SEC, 150);

        // Pass login credentials to login method.
        if ( $this->ftp->ftp_login($userFTP, $passwordFTP) ) {
            $this->writeLog("FTP connection sucecssful.", $fn);
            return true;
        } else {
            // TODO: get ftp error message
            $this->writeLog("ERROR: FTP connection failed!", $fn);
            return false;
        }
    }

    /**
     * Gather ETD zip files from FTP server.
     *
     * Create a local directory for each zip file from FTP server and save into directory.
     * Local directory name is based on file name.
     * Next, varify that PDF and XML files exist. Also keep track of supplementary files.
     * Lastly, expand zip file contents into local directory.
     *
     * @return boolean Success value.
     */
    function getFiles() {
        $fn = "getFiles";

        $this->writeLog("Fetching ETD files from FTP server.", $fn);

        // Look at specific directory on FTP server for ETD files. Ex: /path/to/files/
        $fetchdirFTP = $this->settings['ftp']['fetchdir'];

        // Define local directory for file processing. Ex: /tmp/processed/
        $localdirFTP = $this->settings['ftp']['localdir'];
        if ( empty($localdirFTP) ) {
            $this->writeLog("ERROR: Local working directory not set!", $fn);
            return false;
        }

        // Change FTP directory if $fetchdirFTP is not empty (aka root directory).
        if ($fetchdirFTP != "") {
            if ( $this->ftp->ftp_chdir($fetchdirFTP) ) {
                $this->writeLog("Changed to FTP directory: " . $fetchdirFTP, $fn);
            } else {
                $this->writeLog("ERROR: Cound not change FTP directory: " . $fetchdirFTP , $fn);
                return false;
            }
        }

        $this->writeLog("Currently in FTP directory: " . $fetchdirFTP, $fn);

        /**
         * Look for files that begin with a specific string.
         * In our specific case the file prefix is "etdadmin_upload".
         * Save results into $etdFiles array.
         */
        $etdFiles = $this->ftp->ftp_nlist("etdadmin_upload*");

        // Sanity check to see if there are any ETD files to process.
        // TODO: Handle some type of error message?
        if ( empty($etdFiles) ) {
            $this->writeLog("Did not find any files to fetch. Quitting.", $fn);
            return true;
        }

        /**
         * Loop through each match in $etdFiles.
         * There may be multiple matched files so process each individually.
         */
        $f = 0;
        foreach ($etdFiles as $filename) {
            $f++;
            /**
             * Set the directory name for each ETD file.
             * This is based on the file name without any file extension.
             * Ex: etd_file_name_1234.zip -> /tmp/processing/etd_file_name_1234
             */

            // Sanity check to see if filename is more than four chars. Continue if string fails.
            if (strlen($filename) <= 4) {
                $this->writeLog("Warning! File name only has " . strlen($filename) . " characters. Skipping this file." , $fn);
                continue;
            }

            // Get the regular file name without file extension.
            $etdname = substr($filename,0,strlen($filename)-4);

            // Set the path of the local working fdrectory. Ex: /tmp/processing/file_name_1234
            $etdDir = $localdirFTP . $etdname;

            // Save the shortname as a local object variable
            $this->localFiles[$etdDir]['ETD_SHORTNAME'] = $etdname;

            $this->writeLog("BEGIN Gathering ETD file #" . $f . " - " . $filename, $fn);

            // Create the local directory if it doesn't already exists.
            $this->writeLog("Now building local working directory...", $fn, $etdname);
            if ( file_exists($etdDir) ) {
                $this->writeLog("Local working directory already exists: " . $etdDir, $fn, $etdname);
            }
            else if ( !mkdir($etdDir, 0755, true) ) {
                $this->writeLog("Failed to create local working directory: " . $etdDir, $fn, $etdname);
                continue;
            } else {
                $this->writeLog("Created ETD local working directory: " . $etdDir, $fn, $etdname);
            }
            $localFile = $etdDir . "/" . $filename;

            // HACK: give loop some time to create directory.
            sleep(2);

            /**
             * Gets the file from the FTP server.
             * Saves it locally to local working directory. Ex: /tmp/processing/file_name_1234
             * File is saved locally as a binary file.
             */
            if ( $this->ftp->ftp_get($localFile, $filename, FTP_BINARY) ) {
                $this->writeLog("Fetched ETD zip file from FTP server.", $fn, $etdname);
            } else {
                $this->writeLog("ERROR: Failed to fetch file from FTP server!" . $localFile, $fn, $etdname);
                continue;
            }

            // Store location of local directory if it hasn't been stored yet.
            if( isset($this->localFiles[$etdDir]) ) {
                $this->localFiles[$etdDir];
            }

            // Unzip ETD zip file.
            $ziplisting = zip_open($localFile);

            // zip_open returns a resource handle on success and an integer on error.
            if (!is_resource($ziplisting)) {
                $this->writeLog("ERROR: Failed to open zip file!", $fn, $etdname);
                continue;
            }

            $supplement = 0;

            // Go through entire zip file and process contents.
            $z = 0;
            while ($zip_entry = zip_read($ziplisting)) {
                $z++;
                $this->writeLog("Now reading zip file #" . $z, $fn, $etdname);

                // Get file name.
                $file = zip_entry_name($zip_entry);
                $this->writeLog("Zip file name: " . $file, $fn, $etdname);

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
                if (preg_match('/0016/', $file)) {
                    // Check if this is a PDF or XML file.
                    // TODO: handle string case in comparison. Ex: "pdf" vs "PDF".
                    if (substr($file,strlen($file)-3) === 'pdf') {
                        $this->localFiles[$etdDir]['ETD'] = $file;
                        $this->writeLog("This is an PDF file.", $fn, $etdname);
                    } elseif (substr($file,strlen($file)-3) === 'xml') {
                        $this->localFiles[$etdDir]['METADATA'] = $file;
                        $this->writeLog("This is an XML metadata file.", $fn, $etdname);
                    } else {
                        /**
                         * Supplementary files - could be permissions or data.
                         * Metadata will contain boolean key for permission in DISS_file_descr element.
                         * [0] element should always be folder.
                         */
                        $this->localFiles[$etdDir]['UNKNOWN'.$supplement] = $file;
                        $supplement++;

                        $this->writeLog("This is a supplementary file.", $fn, $etdname);
                    }
                }
            }

            /**
             * Sanity check that both:
             *  - $this->localFiles[$etdDir]['ETD']
             *  - $this->localFiles[$etdDir]['METADATA']
             * are defined and are nonempty strings.
             */
            $this->writeLog("Running sanity check that ETD PDF and XML file were found...", $fn, $etdname);
            if ( empty($this->localFiles[$etdDir]['ETD']) ) {
                $this->writeLog("Warning! The ETD PDF file was not found or set!", $fn, $etdname);
            }
            $this->writeLog("Great! The ETD PDF file was found.", $fn, $etdname);

            if ( empty($this->localFiles[$etdDir]['METADATA']) ) {
                $this->writeLog("Warning! The ETD XML file was not found or set!", $fn, $etdname);
            }
            $this->writeLog("Great! The ETD XML file was found.", $fn, $etdname);

            $zip = new ZipArchive;

            // Open and extract zip file to local directory.
            $res = $zip->open($localFile);
            if ($res === TRUE) {
                $zip->extractTo($etdDir);
                $zip->close();

                $this->writeLog("Extracting ETD zip file: " . $localFile, $fn, $etdname);
            } else {
                $this->writeLog("ERROR: Failed to extract ETD zip file! " . $res, $fn, $etdname);
                continue;
            }

            $this->writeLog("END Gathering ETD file #" . $f . " - " . $filename, $fn);
        }

        // Completed fetching all ETD zip files.
        $this->writeLog("Completed fetching all ETD zip files from FTP server.", $fn);
    }

    /**
     * Generate metadata from gathered ETD files.
     *
     * This will generate:
     *  - OA permissions.
     *  - Embargo settings.
     *  - MODS metadata.
     *  - PID, title, author values.
     *
     * @return boolean Success value.
     */
    function processFiles() {
        $fn = "processFiles";

        // Sanity check to see if there are any ETD files to process.
        if ( empty($this->localFiles) ) {
            $this->writeLog("Did not find any files to process. Quitting.", $fn);
            return true;
        }

        $this->writeLog("Now processing ETD files.", $fn);

        /**
         * Load Proquest MODS XSLT stylesheet.
         * Ex: /path/to/proquest/crosswalk/Proquest_MODS.xsl
         */
        $xslt = new xsltProcessor;
        $proquestxslt = new DOMDocument();
        $proquestxslt->load($this->settings['xslt']['xslt']);
        if ( $xslt->importStyleSheet($proquestxslt) ) {
            $this->writeLog("Loaded MODS XSLT stylesheet.", $fn);
        } else {
            $this->writeLog("ERROR: Failed to load MODS XSLT stylesheet!", $fn);
            return false;
        }

        /**
         * Load Fedora Label XSLT stylesheet.
         * Ex: /path/to/proquest/xsl/getLabel.xsl
         */
        $label = new xsltProcessor;
        $labelxslt = new DOMDocument();
        $labelxslt->load($this->settings['xslt']['label']);
        if ( $label->importStyleSheet($labelxslt) ) {
            $this->writeLog("Loaded Fedora Label XSLT stylesheet.", $fn);
        } else {
            $this->writeLog("ERROR: Failed to load Fedora Label XSLT stylesheet!", $fn);
            return false;
        }

        /**
         * Given the array of ETD local files, generate additional metadata.
         */
        $s = 0;
        foreach ($this->localFiles as $directory => $submission) {
            $s++;

            // Pull out the ETD shortname that was generated in getFiles()
            $etdname = $this->localFiles[$directory]['ETD_SHORTNAME'];
            if ( empty($etdname) ) {
                $etdname = substr($this->localFiles[$directory]["ETD"],0,strlen($this->localFiles[$directory]["ETD"])-4);
                $this->localFiles[$directory]['ETD_SHORTNAME'] = $etdname;
            }
            $this->writeLog("BEGIN Processing ETD #" . $s . " - " . $etdname, $fn);

            // Create XPath object from the ETD XML file.
            $metadata = new DOMDocument();
            $metadata->load($directory . '//' . $submission['METADATA']);
            $xpath = new DOMXpath($metadata);

            /**
             * Get OA permission.
             * This looks for the existance of an "oa" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_acceptance/text()
             */
            $this->writeLog("Searching for OA agreement...", $fn, $etdname);

            $openaccess = 0;
            $oaElements = $xpath->query($this->settings['xslt']['oa']);
            if ($oaElements->length === 0 ) {
                $this->writeLog("No OA agreement found.", $fn, $etdname);
            } elseif ($oaElements->item(0)->C14N() === '0') {
                $this->writeLog("No OA agreement found.", $fn, $etdname);
            } else {
                $openaccess = $oaElements->item(0)->C14N();
                $this->writeLog("Found an OA agreement.", $fn, $etdname);
            }

            $this->localFiles[$directory]['OA'] = $openaccess;

            /**
             * Get embargo permission/dates.
             * This looks for the existance of an "embargo" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_delayed_release/text()
             */
            $this->writeLog("Searching for embargo information...", $fn, $etdname);

            $embargo = 0;
            $emElements = $xpath->query($this->settings['xslt']['embargo']);
            if ($emElements->item(0) ) {
                // Convert date string into proper PHP date object format.
                $embargo = $emElements->item(0)->C14N();
                $embargo = str_replace(" ","T",$embargo);
                $embargo = $embargo . "Z";
                $this->localFiles[$directory]['EMBARGO'] = $embargo;
                $this->writeLog("Using embargo date of: " . $embargo, $fn, $etdname);
            }

            /**
             * Check to see if the OA and embargo permissions match.
             * If so, set the embargo permission/date to "indefinite".
             */
            // TODO: should this be a corresponding ELSE IF clause to the previous IF clause?
            //       This looks like $embargo would only match $openaccess if they are both 0.
            if ($openaccess === $embargo) {
                $embargo = 'indefinite';
                $this->localFiles[$directory]['EMBARGO'] = $embargo;
                $this->writeLog("Using embargo date of: " . $embargo, $fn, $etdname);
            } else {
                $this->writeLog("No embargo date found.", $fn, $etdname);
            }

            /**
             * Fetch next PID from Fedora.
             * Prepend PID with locally defined Fedora namespace.
             * Ex: "bc-ir:" for BC.
             */
            // DEBUG: make up PID.
            if ($this->debug === true) {
                $pid = "bc-ir:" . rand(50000,100000);
                $this->writeLog("DEBUG: Generating random PID for testing (NOT fetched from Fedora): " . $pid, $fn, $etdname);
            } else {
                $pid = $this->api_m->getNextPid($this->settings['fedora']['namespace'], 1);
                $this->writeLog("Fetched new PID from Fedora: " . $pid, $fn, $etdname);
            }

            $this->localFiles[$directory]['PID'] = $pid;

            $this->writeLog("Fedora PID value for this ETD: " . $pid, $fn, $etdname);

            /**
             * Insert the PID value into the Proquest MODS XSLT stylesheet.
             * The "handle" value should be set the PID.
             */
            $res = $xslt->setParameter('mods', 'handle', $pid);
            if ($res === false) {
                $this->writeLog("ERROR: Could not update XSLT stylesheet with PID value!", $fn, $etdname);
                //$this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Update XSLT stylesheet with PID value.", $fn, $etdname);

            /**
             * Generate MODS file.
             * This file is generated by applying the Proquest MODS XSLT stylesheet to the ETD XML file.
             * Additional metadata will be generated from the MODS file.
             */
            $mods = $xslt->transformToDoc($metadata);
            if ($mods === false) {
                $this->writeLog("ERROR: Could not transform ETD MODS XML file!", $fn, $etdname);
                //$this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Transformed ETD MODS XML file with XSLT stylesheet.", $fn, $etdname);

            /**
             * Generate ETD title/Fedora Label.
             * The title is generated by applying the Fedora Label XSLT stylesheet to the above generated MODS file.
             * This uses mods:titleInfo.
             */
            $fedoraLabel = $label->transformToXml($mods);
            if ($fedoraLabel === false) {
                $this->writeLog("ERROR: Could not generate ETD title using Fedora Label XSLT stylesheet!", $fn, $etdname);
                //$this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->localFiles[$directory]['LABEL'] = $fedoraLabel;

            $this->writeLog("Generated ETD title: " . $fedoraLabel, $fn, $etdname);

            /**
             * Generate ETD author.
             * This looks for the existance of an "author" node in the MODS XPath object.
             * Ex: /mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()
             */
            $xpathAuthor = new DOMXpath($mods);
            $authorElements = $xpathAuthor->query($this->settings['xslt']['creator']);
            $author = $authorElements->item(0)->C14N();
            $this->writeLog("Generated ETD author: [" . $author . "]", $fn, $etdname);

            /**
             * Normalize the ETD author string. This forms the internal file name convention.
             * Ex: Jane Anne O'Foo => Jane-Anne-OFoo
             */
            #$normalizedAuthor = str_replace(array(" ",",","'",".","&apos;",'"',"&quot;"), array("-","","","","","",""), $author);
            $normalizedAuthor = $this->normalizeString($author);

            $this->writeLog("Generated normalized ETD author: [" . $normalizedAuthor . "]", $fn, $etdname);
            $this->writeLog("Now using the normalized ETD author name to update ETD PDF and MODS files.", $fn, $etdname);

            // Create placeholder full-text text file using normalized author's name.
            $this->localFiles[$directory]['FULLTEXT'] = $normalizedAuthor . ".txt";
            //$this->writeLog("Generated placeholder full text file name: " . $this->localFiles[$directory]['FULLTEXT'], $fn, $etdname);

            // Rename Proquest PDF using normalized author's name.
            $res = rename($directory . "/". $submission['ETD'] , $directory . "/" . $normalizedAuthor . ".pdf");
            if ($res === false) {
                $this->writeLog("ERROR: Could not rename ETD PDF file!", $fn, $etdname);
                //$this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Update local file path for ETD PDF file.
            $this->localFiles[$directory]['ETD'] = $normalizedAuthor . ".pdf";
            $this->writeLog("Renamed ETD PDF file from " . $submission['ETD'] . " to " . $this->localFiles[$directory]['ETD'], $fn, $etdname);

            // Save MODS using normalized author's name.
            $res = $mods->save($directory . "/" . $normalizedAuthor . ".xml");
            if ($res === false) {
                $this->writeLog("ERROR: Could not create new ETD MODS file!", $fn, $etdname);
                //$this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Update local file path for MODS file.
            $this->localFiles[$directory]['MODS'] = $normalizedAuthor . ".xml";
            $this->writeLog("Created new ETD MODS file " . $this->localFiles[$directory]['MODS'], $fn, $etdname);


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

            $this->writeLog("Checking for existence supplemental files...", $fn, $etdname);

            // Check if there are zero or more supplemental files.
            if ($suElements->item(0) ) {
                $this->localFiles[$directory]['PROCESS'] = "0";
                $this->writeLog("No supplemental files found.", $fn, $etdname);
            } else {
                $this->localFiles[$directory]['PROCESS'] = "1";
                $this->writeLog("Found a supplemental file(s).", $fn, $etdname);

                // Keep track of how many additional PIDs will need to be generated.
                $this->toProcess++;
            }

            $this->writeLog("END Processing ETD #" . $s . " - " . $etdname, $fn);
        }

        // Completed processing all ETD files.
        $this->writeLog("Completed processing all ETD files.", $fn);
    }

    /**
     * Initializes a connection to a Fedora file repository server.
     */
    function initFedoraConnection() {

        $this->connection = new RepositoryConnection($this->settings['fedora']['url'],
                                                     $this->settings['fedora']['username'],
                                                     $this->settings['fedora']['password']);

        $this->api = new FedoraApi($this->connection);
        $this->repository = new FedoraRepository($this->api, new simpleCache());

        // Fedora Management API.
        $this->api_m = $this->repository->api->m;
    }


    // Set global values for all ingest* functions
    public $pidcount = 0;
    public $successCount = 0;
    public $failureCount = 0;
    // Initialize messages for notification email.
    public $successMessage = "";
    public $failureMessage = "";
    public $processingMessage = "";


    /**
     * Manages the post-process handling of an ETD ingest
     *
     * @param boolean $status The success status of the calling function.
     * @param string $etdname The name of the ETD to print.
     * @param object $etd An object containing the ETD submission metadata.
     * @return boolean Returns true.
     */
    function ingestHandlerPostProcess($status, $etdname, $etd){
        $fn = "ingestHandlerPostProcess";

        global $pidcount, $successCount, $failureCount;
        global $successMessage, $failureMessage, $processingMessage;

        $submission   = $etd["submission"];
        $fnameFTP     = $etd["fnameFTP"];
        $fullfnameFTP = $etd["fullfnameFTP"];

        $pidcount++;

        // Check if ingest was successful, and manage where to put FTP ETD file.
        if ($status) {
            $this->writeLog("Successfully ingested Fedora object.", $fn, $etdname);

            $successCount++;
            $successMessage .= $submission['PID'] . "\t";

            // Set success status for email message.
            if (isset($submission['EMBARGO'])) {
                $successMessage .= "EMBARGO UNTIL: " . $submission['EMBARGO'] . "\t";
            } else {
                $successMessage .= "NO EMBARGO" . "\t";
            }
            $successMessage .= $submission['LABEL'] . "\n";

            // Move processed PDF file to a new directory. Ex: /path/to/files/processed
            $processdirFTP = $this->settings['ftp']['processdir'];
            $fullProcessdirFTP = "~/" . $processdirFTP . "/" . $fnameFTP;

            $this->writeLog("Currently in FTP directory: " . $this->ftp->ftp_pwd(), $fn, $etdname);

            $this->writeLog("Now attempting to move " . $fullfnameFTP . " into " . $fullProcessdirFTP, $fn, $etdname);

            $ftpRes = true;
            if ($this->debug === true) {
                $this->writeLog("DEBUG: Not moving ETD files on FTP.", $fn, $etdname);
            } else {
                $ftpRes = $this->ftp->ftp_rename($fullfnameFTP, $fullProcessdirFTP);
            }

            // Check if there was an error moving the ETD file on the FTP server.
            if ($ftpRes === false) {
                $this->writeLog("ERROR: Could not move ETD file to 'processed' FTP directory!", $fn, $etdname);
            }

            $this->writeLog("Moved ETD file to 'processed' FTP directory.", $fn, $etdname);
        } else {
            //$this->writeLog("ERROR: Ingestion of Fedora object failed.", $fn, $etdname);

            $failureCount++;
            $failureMessage .= $submission['PID'] . "\t";

            // Set failure status for email message.
            if (isset($submission['EMBARGO'])) {
                $failureMessage .= "EMBARGO UNTIL: " . $submission['EMBARGO'] . "\t";
            } else {
                $failureMessage .= "NO EMBARGO" . "\t";
            }
            $failureMessage .= $submission['LABEL'] . "\n";

            // Move processed PDF file to a new directory. Ex: /path/to/files/failed
            $faildirFTP = $this->settings['ftp']['faildir'];
            $fullFaildirFTP = "~/" . $faildirFTP . "/" . $fnameFTP;

            $this->writeLog("Now attempting to move " . $fullfnameFTP . " into " . $fullFaildirFTP, $fn, $etdname);

            $ftpRes = true;
            if ($this->debug === true) {
                $this->writeLog("DEBUG: Not moving ETD files on FTP.", $fn, $etdname);
            } else {
                $ftpRes = $this->ftp->ftp_rename($fullfnameFTP, $fullFaildirFTP);
            }

            // Check if there was an error moving the ETD file on the FTP server.
            if ($ftpRes === false) {
                $this->writeLog("ERROR: Could not move ETD file to 'failed' FTP directory!", $fn, $etdname);
            }

            $this->writeLog("Moved ETD file to 'failed' FTP directory.", $fn, $etdname);
        }

        return true;
    }


    /**
     * Ingest files into Fedora
     *
     * This creates and ingests the following Fedora datastreams:
     * - RELS-EXT       (external relationship)
     * - MODS           (updated MODS fole)
     * - ARCHIVE        (original Proquest MODS)
     * - ARCHIVE-PDF    (original PDF)
     * - PDF            (updated PDF with splashpage)
     * - FULL_TEXT      (full text of PDF)
     * - TN             (thumbnail image of PDF)
     * - PREVIEW        (image of PDF first page)
     * - XACML          (access control policy)
     * - RELS-INT       (internal relationship)
     *
     * Next, it ingests the completed object into Fedora.
     * Then, tidies up ETD files on FTP server.
     * Lastly, send out notification email.
     */
    function ingest() {
        $fn = "ingest";

        // Sanity check to see if there are any ETD files to process.
        if ( empty($this->localFiles) ) {
            $this->writeLog("Did not find any files to ingest. Quitting.", $fn);

            // Shortcut to sending email update.
            $message = "No ETD files to process.";
            $res = $this->sendEmail($message);

            return true;
        }

        $this->writeLog("Now Ingesting ETD files.", $fn);

        global $pidcount, $successCount, $failureCount;
        global $successMessage, $failureMessage, $processingMessage;

        $successMessage = "The following ETDs ingested successfully:\n\n";
        $failureMessage = "\n\nWARNING!! The following ETDs __FAILED__ to ingest:\n\n";
        $processingMessage = "\n\nThe following staging directories were used:\n\n";

        $fop = '/var/www/html/drupal/sites/all/modules/boston_college/data/fop/cfg.xml';

        $executable_fop = '/opt/fop/fop';
        $executable_convert = '/usr/bin/convert';
        $executable_pdftk = '/usr/bin/pdftk';
        $executable_pdftotext = '/usr/bin/pdftotext';

        // TODO: list the file path for script log.

        // Go through each ETD local file bundle.
        $i = 0;
        foreach ($this->localFiles as $directory => $submission) {
            $i++;

            $processingMessage .= $directory . "\n";

            // Pull out the ETD shortname that was generated in getFiles()
            $etdname = $this->localFiles[$directory]['ETD_SHORTNAME'];
            if ( empty($etdname) ) {
                $etdname = substr($this->localFiles[$directory]["ETD"],0,strlen($this->localFiles[$directory]["ETD"])-4);
                $this->localFiles[$directory]['ETD_SHORTNAME'] = $etdname;
            }
            $this->writeLog("BEGIN Ingesting ETD #" . (string)$i . " - " . $etdname, $fn);


            // Reconstruct name of zip file from the local ETD work space directory name.
            // TODO: there must be a better way to do this...
            $directoryArray = explode('/', $directory);
            $fnameFTP = array_values(array_slice($directoryArray, -1))[0] . '.zip';

            // Build full FTP path for ETD file incase $fetchdirFTP is not the root directory.
            $fetchdirFTP = $this->settings['ftp']['fetchdir'];
            $fullfnameFTP = "";
            if ($fetchdirFTP == "") {
                $fullfnameFTP = $fnameFTP;
            } else {
                $fullfnameFTP = "~/" . $fetchdirFTP . "/" . $fnameFTP;
            }
            $this->writeLog("The full path of the ETD file on the FTP server is: " . $fullfnameFTP, $fn, $etdname);

            // collect some values for ingestHandlerPostProcess()
            $this->etd["submission"] = $submission;
            $this->etd["fnameFTP"] = $fnameFTP;
            $this->etd["fullfnameFTP"] = $fullfnameFTP;

            // Check for supplemental files, and create log message.
            if ($this->localFiles[$directory]['PROCESS'] === '1') {
                // Still Load - but notify admin about supp files.
                $this->writeLog("Supplementary files found.", $fn, $etdname);
            }

            // Instantiated a Fedora object and use the generated PID as its ID.
            try {
                $object = $this->repository->constructObject($this->localFiles[$directory]['PID']);
                $this->writeLog("Instantiated a Fedora object with PID: " . $this->localFiles[$directory]['PID'], $fn, $etdname);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Could not instanciate Fedora object: " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Assign the Fedora object label the ETD name/label
            $object->label = $this->localFiles[$directory]['LABEL'];
            $this->writeLog("Assigned a title to Fedora object: " . $this->localFiles[$directory]['LABEL'], $fn, $etdname);

            // All Fedora objects are owned by the same generic account
            $object->owner = 'fedoraAdmin';

            $this->writeLog("Now generating Fedora datastreams.", $fn, $etdname);


            /**
             * Generate RELS-EXT (XACML) datastream.
             *
             *
             */
            $this->writeLog("Generating RELS-EXT (XACML) datastream.", $fn, $etdname);

            // Set the default Parent and Collection policies for the Fedora object.
            try {
                $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID);
                $collection = GRADUATE_THESES;
            } catch (Exception $e) {
                $this->writeLog("ERROR: Could not instanciate Fedora object GRADUATE_THESES: " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Update the Parent and Collection policies if this ETD is embargoed.
            if (isset($this->localFiles[$directory]['EMBARGO'])) {
                $collection = GRADUATE_THESES_RESTRICTED;
                try {
                    $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID_EMBARGO);
                    $this->writeLog("Adding to Graduate Theses (Restricted) collection.", $fn, $etdname);
                } catch (Exception $e) {
                    $this->writeLog("ERROR: Could not instanciate Fedora object GRADUATE_THESES_RESTRICTED: " . $e->getMessage(), $fn, $etdname);
                    $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                    $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                    continue;
                }
            } else {
                $this->writeLog("Adding to Graduate Theses collection.", $fn, $etdname);
            }

            // Update the Fedora object's relationship policies
            $object->models = array('bc-ir:graduateETDCModel');
            $object->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $collection);

            // Set various other Fedora object settings.
            $object->checksumType = 'SHA-256';
            $object->state = 'I';

            // Get Parent XACML policy.
            $policy = $parentObject->getDatastream(ISLANDORA_BC_XACML_POLICY);
            $this->writeLog("Fetching Islandora XACML datastream.", $fn, $etdname);
            $this->writeLog("Deferring RELS-EXT (XACML) datastream ingestion until other datastreams are generated.", $fn, $etdname);


            /**
             * Build MODS Datastream.
             *
             *
             */
            $dsid = 'MODS';
            $this->writeLog("Generating MODS datastream.", $fn, $etdname);

            // Build Fedora object MODS datastream.
            $datastream = $object->constructDatastream($dsid, 'X');

            // Set various MODS datastream values.
            $datastream->label = 'MODS Record';
            // OLD: $datastream->label = $this->localFiles[$directory]['LABEL'];
            $datastream->mimeType = 'application/xml';

            // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/author_name.XML
            $datastream->setContentFromFile($directory . "//" . $this->localFiles[$directory]['MODS']);
            $this->writeLog("Selecting MODS datastream to use: " . $this->localFiles[$directory]['MODS'], $fn, $etdname);

            // Ingest MODS datastream into Fedora object.
            try {
                $object->ingestDatastream($datastream);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting MODS datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested MODS datastream.", $fn, $etdname);


            /**
             * Build ARCHIVE MODS datastream.
             *
             * Original Proquest Metadata will be saved as ARCHIVE.
             * Original filename is used as label for identification.
             */
            $dsid = 'ARCHIVE';
            $this->writeLog("Generating ARCHIVE datastream.", $fn, $etdname);

            // Build Fedora object ARCHIVE MODS datastream from original Proquest XML.
            $datastream = $object->constructDatastream($dsid, 'X');

            // Assign datastream label as original Proquest XML file name without file extension. Ex: etd_original_name
            $datastream->label = substr($this->localFiles[$directory]['METADATA'], 0, strlen($this->localFiles[$directory]['METADATA'])-4);
            //$this->writeLog("Using datastream label: " . $datastream->label, $fn, $etdname);

            // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/etd_original_name.XML
            $datastream->setContentFromFile($directory . "//" . $this->localFiles[$directory]['METADATA']);
            $this->writeLog("Selecting ARCHIVE datastream to use: " . $this->localFiles[$directory]['METADATA'], $fn, $etdname);

            // Set various ARCHIVE MODS datastream values.
            $datastream->mimeType = 'application/xml';
            $datastream->checksumType = 'SHA-256';
            $datastream->state = 'I';

            // Ingest ARCHIVE MODS datastream into Fedora object.
            $object->ingestDatastream($datastream);
            $this->writeLog("Ingested ARCHIVE datastream.", $fn, $etdname);


            /**
             * Build ARCHIVE-PDF datastream.
             *
             * PDF will always be loaded as ARCHIVE-PDF DSID regardless of embargo.
             * Splash paged PDF will be PDF dsid.
             */
            $dsid = 'ARCHIVE-PDF';
            $this->writeLog("Generating ARCHIVE-PDF datastream.", $fn, $etdname);

            // Default Control Group is M.
            // Build Fedora object ARCHIVE PDF datastream from original Proquest PDF.
            $datastream = $object->constructDatastream($dsid);

            // OLD: $datastream->label = $this->localFiles[$directory]['LABEL'];
            $datastream->label = 'ARCHIVE-PDF Datastream';

            // Set various ARCHIVE-PDF datastream values.
            $datastream->mimeType = 'application/pdf';
            $datastream->checksumType = 'SHA-256';
            $datastream->state = 'I';

            // Set datastream content to be ARCHIVE-PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $datastream->setContentFromFile($directory . "//" . $this->localFiles[$directory]['ETD']);
            $this->writeLog("Selecting ARCHIVE-PDF datastream to use: " . $this->localFiles[$directory]['ETD'], $fn, $etdname);

            // Ingest ARCHIVE-PDF datastream into Fedora object.
            try {
                $object->ingestDatastream($datastream);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting ARCHIVE-PDF datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested ARCHIVE-PDF datastream.", $fn, $etdname);


            /**
             * Build PDF datastream.
             *
             * First, build splash page PDF.
             * Then, concatenate splash page onto ETD PDF for final PDF.
             */
            $dsid = "PDF";
            $this->writeLog("Generating PDF datastream.", $fn, $etdname);
            $this->writeLog("First, generate PDF splash page.", $fn, $etdname);

            // Source file is the original Proquest XML file.
            $source = $directory . "/" . $this->localFiles[$directory]['MODS'];

            // Assign PDF splash document to ETD file's directory.
            $splashtemp = $directory . "/splash.pdf";

            // Use the custom XSLT splash stylesheet to build the PDF splash document.
            $splashxslt = $this->settings['xslt']['splash'];

            // Use FOP (Formatting Objects Processor) to build PDF splash page.
            // Execute 'fop' command and check return code.
            $command = "$executable_fop -c $fop -xml $source -xsl $splashxslt -pdf $splashtemp";
            exec($command, $output, $return);
            $this->writeLog("Running 'fop' command to build PDF splash page.", $fn, $etdname);

    		if (!$return) {
                $this->writeLog("PDF splash page created successfully.", $fn, $etdname);
    		} else {
                $this->writeLog("ERROR: PDF splash page creation failed! ". $return, $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
    		    continue;
    		}

            // Update ETD file's object to store splash page's file location and name.
            $this->localFiles[$directory]['SPLASH'] = 'splash.pdf';

            /**
             * Build concatted PDF document.
             *
             * Load splash page PDF to core PDF if under embargo. -- TODO: find out when/how this happens
             */
            $this->writeLog("Next, generate concatenated PDF document.", $fn, $etdname);

            // Assign concatenated PDF document to ETD file's directory.
            $concattemp = $directory . "/concatted.pdf";

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $pdf = $directory . "//" . $this->localFiles[$directory]['ETD'];

            /*
            // Temporarily deactivating the use of pdftk -- binary is no longer supported in RHEL 7

            // Use pdftk (PDF Toolkit) to edit PDF document.
            // Execute 'pdftk' command and check return code.
            $command = "$executable_pdftk $splashtemp $pdf cat output $concattemp";
            exec($command, $output, $return);
            $this->writeLog("Running 'pdftk' command to build concatenated PDF document.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("Concatenated PDF document created successfully.", $fn, $etdname);
            } else {
                $this->writeLog("ERROR: Concatenated PDF document creation failed! " . $return, $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            */

            // Temporarily copying over the $pdf file as the $concattemp version since pdftk is not supported on RHEL7
            $this->writeLog("WARNING: A splashpage will not be appended to the ingested PDF file. Instead, a clone of the original PDF will be used.", $fn, $etdname);

            if (!copy($pdf,$concattemp)) {
                $this->writeLog("ERROR: PDF document cloning failed!", $fn, $etdname);
            } else {
                $this->writeLog("PDF document cloned successfully.", $fn, $etdname);
            }

            // Default Control Group is M
            // Build Fedora object PDF datastream.
            $datastream = $object->constructDatastream($dsid);

            // Set various PDF datastream values.
            $datastream->label = 'PDF Datastream';
            $datastream->mimeType = 'application/pdf';
            $datastream->checksumType = 'SHA-256';

            // Set datastream content to be PDF file. Ex: /tmp/processed/file_name_1234/concatted.PDF
            $datastream->setContentFromFile($concattemp);
            $this->writeLog("Selecting PDF datastream to use: " . $concattemp, $fn, $etdname);

            // Ingest PDF datastream into Fedora object.
            try {
                $object->ingestDatastream($datastream);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting PDF datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested PDF datastream.", $fn, $etdname);


            /**
             * Build FULL_TEXT datastream.
             *
             *
             */
            $dsid = "FULL_TEXT";
            $this->writeLog("Generating FULL_TEXT datastream.", $fn, $etdname);

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $source = $directory . "/" . $this->localFiles[$directory]['ETD'];

            // Assign FULL_TEXT document to ETD file's directory.
            $fttemp = $directory . "/fulltext.txt";

            // Use pdftotext (PDF to Text) to generate FULL_TEXT document.
            // Execute 'pdftotext' command and check return code.
            $command = "$executable_pdftotext $source $fttemp";
            exec($command, $output, $return);
            $this->writeLog("Running 'pdftotext' command to build FULL_TEXT document.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("FULL_TEXT datastream generated successfully.", $fn, $etdname);
            } else {
                $this->writeLog("ERROR: FULL_TEXT document creation failed! " . $return, $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Build Fedora object FULL_TEXT datastream.
            $datastream = $object->constructDatastream($dsid);

            // Set various FULL_TEXT datastream values.
            $datastream->label = 'FULL_TEXT';
            $datastream->mimeType = 'text/plain';

            // Read in the full-text document that was just generated.
            $fulltext = file_get_contents($fttemp);

            // Check if file read failed.
            if ($fulltext === false) {
                $this->writeLog("ERROR: could not read in file: ". $fttemp, $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Strip out junky characters that mess up SOLR.
            $replacement = '';
            $sanitized = preg_replace('/[\x00-\x1f]/', $replacement, $fulltext);

            // In the slim chance preg_replace fails.
            if ($sanitized === null) {
                $this->writeLog("ERROR: preg_replace failed to return valid sanitized FULL_TEXT string!", $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Set FULL_TEXT datastream to be sanitized version of full-text document.
            $datastream->setContentFromString($sanitized);
            $this->writeLog("Selecting FULL_TEXT datastream to use: " . $fttemp, $fn, $etdname);

            // Ingest FULL_TEXT datastream into Fedora object.
            try {
                $object->ingestDatastream($datastream);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting FULL_TEXT datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested FULL_TEXT datastream.", $fn, $etdname);


            /**
             * Build Thumbnail (TN) datastream
             *
             *
             */
            $dsid = "TN";
            $this->writeLog("Generating TN (thumbnail) datastream.", $fn, $etdname);

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            // TODO: figure out what "[0]" means in this context.
            $source = $directory . "/" . $this->localFiles[$directory]['ETD'] . "[0]";

            // Use convert (from ImageMagick tool suite) to generate TN document.
            // Execute 'convert' command and check return code.
            $command = "$executable_convert $source -quality 75 -resize 200x200 -colorspace RGB -flatten " . $directory . "/thumbnail.jpg";
            exec($command, $output, $return);
            $this->writeLog("Running 'convert' command to build TN document.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("TN datastream generated successfully.", $fn, $etdname);
            } else {
                $this->writeLog("ERROR: TN document creation failed! " . $return, $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Build Fedora object TN datastream.
            $datastream = $object->constructDatastream($dsid);

            // Set various TN datastream values.
            $datastream->label = 'TN';
            $datastream->mimeType = 'image/jpeg';

            // Set TN datastream to be the generated thumbnail image.
            $datastream->setContentFromFile($directory . "//thumbnail.jpg");
            $this->writeLog("Selecting TN datastream to use: thumbnail.jpg", $fn, $etdname);

            // Ingest TN datastream into Fedora object.
            try {
                $object->ingestDatastream($datastream);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting TN datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested TN datastream.", $fn, $etdname);


            /**
             * Build PREVIEW datastream.
             *
             *
             */
            $dsid = "PREVIEW";
            $this->writeLog("Generating PREVIEW datastream.", $fn, $etdname);

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            // TODO: figure out what "[0]" means in this context.
            $source = $directory . "/" . $this->localFiles[$directory]['ETD'] . "[0]";

            // Use convert (from ImageMagick tool suite) to generate PREVIEW document.
            // Execute 'convert' command and check return code.
            $command = "$executable_convert $source -quality 75 -resize 500x700 -colorspace RGB -flatten " . $directory . "/preview.jpg";
            exec($command, $output, $return);
            $this->writeLog("Running 'convert' command to build PREVIEW document.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("PREVIEW datastream generated successfully.", $fn, $etdname);
            } else {
                $this->writeLog("ERROR: REVIEW document creation failed! " . $return, $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }

            // Build Fedora object PREVIEW datastream.
            $datastream = $object->constructDatastream($dsid);

            // Set various PREVIEW datastream values.
            $datastream->label = 'PREVIEW';
            $datastream->mimeType = 'image/jpeg';

            // Set PREVIEW datastream to be the generated preview image.
            $datastream->setContentFromFile($directory . "//preview.jpg");
            $this->writeLog("Selecting TN datastream to use: preview.jpg", $fn, $etdname);

            // Ingest PREVIEW datastream into Fedora object.
            try {
                $object->ingestDatastream($datastream);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting PREVIEW datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested PREVIEW datastream.", $fn, $etdname);


            /**
             * Continue RELS-EXT datastream.
             *
             *
             */
            // TODO: understand why this command is down here and not in an earlier POLICY datastream section.
            $this->writeLog("Resuming RELS-EXT datastream ingestion now that other datastreams are generated.", $fn, $etdname);

            try {
                $object->ingestDatastream($policy);
            } catch (Exception $e) {
                $this->writeLog("ERROR: Ingesting RELS-EXT (XACML) datastream failed! " . $e->getMessage(), $fn, $etdname);
                $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                continue;
            }
            $this->writeLog("Ingested RELS-EXT (XACML) datastream.", $fn, $etdname);


            /**
             * Build RELS-INT datastream.
             *
             * This checks if there is an OA policy set for this ETD.
             * If there is, then set Embargo date in the custom XACML policy file.
             */
            $this->writeLog("Generating RELS-INT datastream.", $fn, $etdname);

            $this->writeLog("Reading in custom RELS XSLT file...", $fn, $etdname);

            // $submission['OA'] is either '0' for no OA policy, or some non-zero value.
            $relsint = '';
            $relsFile = "";
            if ($submission['OA'] === 0) {
                // No OA policy.
                $relsFile = "xsl/permRELS-INT.xml";
                $relsint = file_get_contents($relsFile);

                // Check if file read failed.
                if ($relsint === false) {
                    $this->writeLog("ERROR: could not read in file: " . $relsFile, $fn, $etdname);
                    $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                    continue;
                }

                $relsint = str_replace('######', $submission['PID'], $relsint);

                $this->writeLog("No OA policy for ETD: read in: " . $relsFile, $fn, $etdname);
            } else if (isset($submission['EMBARGO'])) {
                // Has an OA policy, and an embargo date.
                $relsFile = "xsl/embargoRELS-INT.xml";
                $relsint = file_get_contents($relsFile);

                // Check if file read failed.
                if ($relsint === false) {
                    $this->writeLog("ERROR: could not read in file: " . $relsFile, $fn, $etdname);
                    $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                    continue;
                }

                $relsint = str_replace('######', $submission['PID'], $relsint);
                $relsint = str_replace('$$$$$$', $submission['EMBARGO'], $relsint);

                $this->writeLog("OA policy found and Embargo date found for ETD: read in: " . $relsFile, $fn, $etdname);
            }

            // TODO: handle case where there is an OA policy and no embargo date?

            // Ingest datastream if we have a XACML policy set.
            if (isset($relsint) && $relsint !== '') {
                $dsid = "RELS-INT";

                // Build Fedora object RELS-INT datastream.
                $datastream = $object->constructDatastream($dsid);

                // Set various RELS-INT datastream values.
                $datastream->label = 'Fedora Relationship Metadata';
                $datastream->mimeType = 'application/rdf+xml';

                // Set RELS-INT datastream to be the custom XACML policy file read in above.
                $datastream->setContentFromString($relsint);
                $this->writeLog("Selecting RELS-INT datastream to use: " . $relsFile, $fn, $etdname);

                // Ingest RELS-INT datastream into Fedora object.
                try {
                    $object->ingestDatastream($datastream);
                } catch (Exception $e) {
                    $this->writeLog("ERROR: Ingesting RELS-INT datastream failed! " . $e->getMessage(), $fn, $etdname);
                    $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                    $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                    continue;
                }

                $this->writeLog("Ingested RELS-INT datastream.", $fn, $etdname);
            }


            /**
             * Ingest full object into Fedora.
             *
             *
             */

            // DEBUG: ignore Fedora ingest.
            $res = true;
            if ($this->debug === true) {
                $this->writeLog("DEBUG: Ignore ingesting object into Fedora.", $fn, $etdname);
                $this->ingestHandlerPostProcess(true, $etdname, $this->etd);
            } else {
                try {
                    $res = $this->repository->ingestObject($object);
                    $this->writeLog("Starting ingestion of Fedora object...", $fn, $etdname);
                    $this->ingestHandlerPostProcess(true, $etdname, $this->etd);
                } catch (Exception $e) {
                    $this->writeLog("ERROR: Could not ingest Fedora object:\n" . $e->getMessage(), $fn, $etdname);
                    $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                    $this->ingestHandlerPostProcess(false, $etdname, $this->etd);
                    continue;
                }
            }

            // Make sure we give every processing loop enough time to complete.
            sleep(2);

            $this->writeLog("END Ingesting ETD #" . (string)$i . " - " . $etdname, $fn);
        }

        /**
         * Send email message on status of all processed ETD files.
         *
         * Do not show failure message in notification if no ETDs failed.
         * (same with success message, but hopefully we won't have that problem!)
         *
         * $res returns a bool value, but nothing else to manage if it returns false at this point.
         */
        $res = true;

        // Simple status report output.
        $this->writeLog("Status report:" .
                        "\tETDs ingested: " . ((string)$successCount ? (string)$successCount : "0") .
                        "\tETDs not ingested: " . ((string)$failureCount ? (string)$failureCount : "0"),
                        $fn);

        /*
        if ($failureMessage == "\n\nThe following ETDs __FAILED__ to ingest:\n\n") {
            mail($this->settings['notify']['email'],"Message from processProquest",$successMessage . $processingMessage);
        } elseif ($successMessage == "The following ETDs successfully ingested:\n\n") {
            mail($this->settings['notify']['email'],"Message from processProquest",$failureMessage . $processingMessage);
        } else {
            mail($this->settings['notify']['email'],"Message from processProquest",$successMessage . $failureMessage . $processingMessage);
        }
        */

        // No Failures: hide failure message.
        if ($failureCount == 0) {
            $res = $this->sendEmail($successMessage . $processingMessage);
            return;
        }

        // No successes, but some failures: hide success message.
        if ($successCount == 0) {
            $res = $this->sendEmail($failureMessage . $processingMessage);
            return;
        }

        // Everything else: send all message types.
        $res = $this->sendEmail($successMessage . $failureMessage . $processingMessage);

        // Completed ingesting all ETD files.
        $this->writeLog("Completed ingesting all ETD files.", $fn);
    }
}
?>
