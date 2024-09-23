<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/**
 * Description of processProquest
 *
 * @author MEUSEB
 *
 * annotations by Jesse Martinez.
 */

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

date_default_timezone_set("America/New_York");

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

    public $settings;                       // Object to store script settings
    public $debug;                          // Debug bool
    protected $ftp;                         // FTP connection object
    protected $localFiles = [];             // Object to store all ETD metadata
    protected $connection;                  // Repository connection object
    protected $api;                         // Fedora API connection object
    protected $api_m;                       // Fedora API iterator object
    protected $repository;                  // Repository connection object
    protected $toProcess = 0;               // Number of PIDs for supplemental files; remove
    protected $logFile = "";                // Log file name
    protected $logError = false;            // Track if there was an error; remove
    protected $processingErrors = [];       // Keep track of processing errors
    protected $allFoundETDs = [];           // List of all found ETD zip files
    protected $allSupplementalETDs = [];    // List of all ETDs with supplemental files
    protected $allRegularETDs = [];         // List of all ETDs without supplemental files
    protected $allIngestedETDs = [];        // List of all ETDs that were successfully ingested
    protected $allFailedETDs = [];          // List of all ETDs that failed to ingest
    protected $allInvalidETDs = [];         // List of all ETDs that have invalid zip files

    protected $countTotalETDs = 0;          // Total ETDs count
    protected $countTotalValidETDs = 0;     // Total ETDs that are valid files
    protected $countTotalInvalidETDs = 0;   // Total ETDS that are invalid files
    protected $countSupplementalETDs = 0;   // Total ETDs with supplemental files
    protected $countProcessedETDs = 0;      // Total ETDs successfully processed
    protected $countFailedETDs = 0;         // Total ETDs failed to process

    // Set global values for all ingest* functions
    protected $pidcount = 0;        // remove
    protected $successCount = 0;    // remove
    protected $failureCount = 0;    // remove

    // Initialize messages for notification email.
    protected $successMessage = "";     // remove
    protected $failureMessage = "";     // remove
    protected $processingMessage = "";  // remove

    /**
     * Class constructor.
     *
     * This builds a local '$this' object that contains various script settings.
     *
     * @param array $configurationArray An array containing the configuration file and values.
     * @param bool $debug Run script in debug mode, which doesn't ingest ETD into Fedora.
     * @param object $logger The logger object.
     * 
     * @return bool Return status.
     */
    public function __construct($configurationArray, $debug = DEFAULT_DEBUG_VALUE, $logger) {
        $this->configurationFile = $configurationArray["file"];
        $this->settings = $configurationArray["settings"];
        $this->debug = boolval($debug);
        $this->root_url = $this->settings["islandora"]["root_url"];
        $this->path = $this->settings["islandora"]["path"];
        $this->record_path = "{$this->root_url}{$this->path}";
        $this->logFile = $this->settings["log"]["location"];
        $this->ftpRoot = $this->settings["ftp"]["fetchdir"];

        if (!is_object($logger)) {
            // An empty logger object was passed.
            return false;
        }

        $this->logger = $logger;

        // Pull out logfile location from logger object.
        $logHandlers = $logger->getHandlers();
        foreach ($logHandlers as $handler) {
            $url = $handler->getUrl();
            if (str_contains($url, "php://")) {
                // Ignore the stdout/console handler.
                continue;
            }
            $this->logFileLocation = $url;
        }

        $this->writeLog("STATUS: Starting processProquest script.", "");
        $this->writeLog("STATUS: Running with DEBUG value: " . ($this->debug ? 'TRUE' : 'FALSE'), "");
        $this->writeLog("STATUS: Using configuration file: {$this->configurationFile}", "");

        // Load Islandora/Fedora Tuque library.
        $tuqueLocation = $this->settings['packages']['tuque'];
        require_once "{$tuqueLocation}/RepositoryConnection.php";
        require_once "{$tuqueLocation}/FedoraApi.php";
        require_once "{$tuqueLocation}/FedoraApiSerializer.php";
        require_once "{$tuqueLocation}/Repository.php";
        require_once "{$tuqueLocation}/RepositoryException.php";
        require_once "{$tuqueLocation}/FedoraRelationships.php";
        require_once "{$tuqueLocation}/Cache.php";
        require_once "{$tuqueLocation}/HttpConnection.php";

        return true;
    }

    /**
     * Output messages to log file and to console.
     *
     * @param string $message The message to log.
     * @param string $functionName Optional. The name of the function calling this function. Is wrapped in ().
     * @param string $prefix Optional. The prefix to include before the message. Is wrapped in [].
     */
    private function writeLog($message, $functionName = "", $prefix = "") {
        $completeMessage = "";
        if ( !empty($functionName) ) {
            $completeMessage .= "({$functionName}) ";
        }

        if ( !empty($prefix) ) {
            $completeMessage .= "[{$prefix}] ";
        } 

        $completeMessage .= "{$message}";

        // Write out message.
        $this->logger->info($completeMessage);
    }

    /**
     * Send email notification.
     *
     * @param string $message The email body to send.
     * @return boolean Was the email sent successfully.
     */
    private function sendEmail($message) {
        $fn = "sendEmail";

        $log_location_message = "\n\nA detailed log file for this ingest has been generated on the server at this location:\n • " . $this->logFile;

        $email_to = $this->settings['notify']['email'];
        $email_subject = "Message from processProquest";
        $email_message = $message . $log_location_message;

        // Check for empty email values.
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
            return true;
        } else {
            $res = mail($email_to, $email_subject, $email_message);
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
     * 
     * @throws Exception if the FTP connection failed.
     */
    function initFTP() {
        $fn = "initFTP";

        $this->writeLog("Initializing FTP connection.", $fn);

        $urlFTP = $this->settings['ftp']['server'];
        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        if (empty($urlFTP) || empty($userFTP) || empty($passwordFTP)) {
            $errorMessage = "FTP login values are missing. Please check your settings.";
            $this->writeLog("ERROR: {$errorMessage}", $fn);

            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
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
            $errorMessage = "FTP connection failed.";
            $this->writeLog("ERROR: {$errorMessage}", $fn);

            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }
    }

    /**
     * Prepares Fedora datastreams for ingestion.
     *
     * @param $fedoraObj A Fedora connection object.
     * @param $datastreamObj A datastream object, usually a file.
     * @param $datastreamName The name of the datastream.
     * @param $etdName The name of the ETD file being processed.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    private function prepareIngestDatastream($fedoraObj, $datastreamObj, $datastreamName, $etdName) {
        if ($this->debug === true) {
            array_push($this->localFiles[$etdName]['DATASTREAMS_CREATED'], $datastreamName);
            $this->writeLog("[{$datastreamName}] DEBUG: Did not ingest datastream.", "ingest" , $etdName);
            return true;
        }

        // Ingest datastream into Fedora object.
        try {
            $fedoraObj->ingestDatastream($datastreamObj);
        } catch (Exception $e) {
            $errorMessage = "{$datastreamName} datastream ingest failed: " . $e->getMessage();
            array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
            $this->writeLog("ERROR: {$errorMessage}", $fn, $etdName);
            $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdName);

            // TODO: call postProcess()?
            array_push($this->processingErrors, $errorMessage);
            $this->countFailedETDs++;
            throw new Exception($errorMessage);
        }

        array_push($this->localFiles[$etdName]['DATASTREAMS_CREATED'], $datastreamName);
        $this->writeLog("[{$datastreamName}] Ingested datastream.", "ingest" , $etdName);
        return true;
    }

    /**
     * Moves files on FTP server at the end of the process.
     * 
     * @return boolean Success value.
     */
    private function moveFTPFiles(){
        $fn = "moveFTPFiles";
        $processdirFTP = $this->settings['ftp']['processdir'];
        $faildirFTP = $this->settings['ftp']['faildir'];

        $this->writeLog("########################", $fn);
        $this->writeLog("BEGIN Moving processed ETDs into respective post-processing directories.", $fn);
        $this->writeLog("Currently in FTP directory: {$this->fetchdirFTP}", $fn);

        foreach($this->localFiles as $local) {
            $ingested = $local["INGESTED"];
            $fileName = $local["ZIP_FILENAME"];
            $ftpPathForETD = $local["FTP_PATH_FOR_ETD"];
            $etdname = $local["ETD_SHORTNAME"];

            if ($ingested) {
                $moveFTPDir = $processdirFTP . $fileName;
            } else {
                $moveFTPDir = $faildirFTP . $fileName;
            }

            $this->writeLog("------------------------------", $fn);
            $this->writeLog("Now attempting to move:", $fn, $etdname);
            $this->writeLog("   from: {$ftpPathForETD}", $fn, $etdname);
            $this->writeLog("   into: {$moveFTPDir}", $fn, $etdname);

            if ($this->debug === true) {
                $this->writeLog("DEBUG: Not moving ETD files on FTP.", $fn, $etdname);
                $this->writeLog("------------------------------", $fn);
                continue;
            }

            $ftpRes = $this->ftp->ftp_rename($ftpPathForETD, $moveFTPDir);
            
            // Check if there was an error moving the ETD file on the FTP server.
            if ($ftpRes === false) {
                $this->writeLog("ERROR: Could not move ETD file to 'processed' FTP directory!", $fn, $etdname);
                $this->writeLog("------------------------------", $fn);
                return false;
            }
            $this->writeLog("Move was successful.", $fn, $etdname);
            $this->writeLog("------------------------------", $fn);
        }

        $this->writeLog("END Moving processed ETDs into respective post-processing directories.", $fn);

        return true;
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
     * 
     * @throws Exception if the working directory isn't reachable.
     */
    function getFiles() {
        $fn = "getFiles";

        $this->writeLog("########################", $fn);
        $this->writeLog("Fetching ETD files from FTP server.", $fn);

        // Look at specific directory on FTP server for ETD files. Ex: /path/to/files/
        $this->fetchdirFTP = $this->settings['ftp']['fetchdir'];
        if (empty($this->fetchdirFTP)) {
            $this->fetchdirFTP = "~/";
        }

        // Define local directory for file processing. Ex: /tmp/processed/
        $localdirFTP = $this->settings['ftp']['localdir'];
        if ( empty($localdirFTP) ) {
            $errorMessage = "Local working directory not set.";
            $this->writeLog("ERROR: {$errorMessage}", $fn);
            
            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        // Change FTP directory if $fetchdirFTP is not empty (aka root directory).
        if ($this->fetchdirFTP != "") {
            if ( $this->ftp->ftp_chdir($this->fetchdirFTP) ) {
                $this->writeLog("Changed to local FTP directory: {$this->fetchdirFTP}", $fn);
            } else {
                $errorMessage = "Cound not change FTP directory: {$this->fetchdirFTP}";
                $this->writeLog("ERROR: {$errorMessage}", $fn);

                // TODO: call postProcess()
                array_push($this->processingErrors, $errorMessage);
                throw new Exception($errorMessage);
            }
        }

        $this->writeLog("Currently in FTP directory: {$this->fetchdirFTP}", $fn);

        /**
         * Look for files that begin with a specific string.
         * In our specific case the file prefix is "etdadmin_upload_*".
         * Save results into $etdFiles array.
         */
        $file_regex = $this->settings['ftp']['file_regex'];
        $etdFiles = $this->ftp->ftp_nlist($file_regex);

        $this->allFoundETDs = $etdFiles;
        $this->countTotalETDs = count($etdFiles);

        // Throw exception if there are no ETD files to process.
        if ( empty($etdFiles) ) {
            $errorMessage = "Did not find any ETD files on the FTP server.";
            $this->writeLog("WARNING: {$errorMessage}", $fn);

            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        $this->writeLog("Found {$this->countTotalETDs} ETD file(s).", $fn);

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

            // Get the regular file name without file extension.
            $etdname = substr($filename,0,strlen($filename)-4);

            // Set the path of the local working directory. Ex: /tmp/processing/file_name_1234
            $etdDir = $localdirFTP . $etdname;

            $this->writeLog("------------------------------", $fn);
            $this->writeLog("BEGIN Gathering ETD file [{$f} of {$this->countTotalETDs}]", $fn, $etdname);

            // Check to see if filename is more than four chars. Continue if string fails.
            if (strlen($filename) <= 4) {
                $this->writeLog("Warning! File name only has " . strlen($filename) . " characters. Moving to the next ETD." , $fn, $etdname);
                $this->countTotalInvalidETDs++;
                array_push($this->allInvalidETDs, $filename);
                continue;
            }
            $this->writeLog("Is file valid... true.", $fn, $etdname);

            // Increment number of valid ETDs.
            $this->countTotalValidETDs++;

            $this->localFiles[$etdname]['ETD_SHORTNAME'] = $etdname;
            $this->localFiles[$etdname]['WORKING_DIR'] = $etdDir;
            $this->localFiles[$etdname]['SUPPLEMENTS'] = [];
            $this->localFiles[$etdname]['HAS_SUPPLEMENTS'] = false;
            $this->localFiles[$etdname]['ETD'] = "";
            $this->localFiles[$etdname]['FILE_ETD'] = "";
            $this->localFiles[$etdname]['METADATA'] = "";
            $this->localFiles[$etdname]['FILE_METADATA'] = "";
            $this->localFiles[$etdname]['ZIP_FILENAME'] = $filename;
            $this->localFiles[$etdname]['ZIP_CONTENTS'] = [];
            $this->localFiles[$etdname]['ZIP_CONTENTS_DIRS'] = [];
            $this->localFiles[$etdname]['FTP_PATH_FOR_ETD'] = "{$this->fetchdirFTP}{$filename}";

            // Set status to 'processing'.
            $this->localFiles[$etdname]['STATUS'] = "unprocessed";

            // Create the local directory if it doesn't already exists.
            if ( file_exists($etdDir) ) {
                $this->writeLog("Using the existing local working directory:", $fn, $etdname);
                $this->writeLog("   {$etdDir}", $fn, $etdname);
            }
            else if ( !mkdir($etdDir, 0755, true) ) {
                $errorMessage = "Failed to create local working directory: {$etdDir}. Moving to the next ETD.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                continue;
            } else {
                $this->writeLog("Created local working directory: {$etdDir}", $fn, $etdname);
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
                $errorMessage = "Failed to fetch file from FTP server: {$localFile}. Moving to the next ETD.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                continue;
            }

            // Store location of local directory if it hasn't been stored yet.
            if( isset($this->localFiles[$etdname]) ) {
                $this->localFiles[$etdname];
            }

            // Unzip ETD zip file.
            $ziplisting = zip_open($localFile);

            // zip_open returns a resource handle on success and an integer on error.
            if (!is_resource($ziplisting)) {
                $errorMessage = "Failed to open zip file. Moving to the next ETD.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                continue;
            }

            $supplement = 0;

            // Go through entire zip file and process contents.
            $z = 0;
            $this->writeLog("Expanded the zip file and found the following files:", $fn, $etdname);
            
            // TODO: replace zip_read() with ZipArchive::statIndex
            while ($zip_entry = zip_read($ziplisting)) {
                $z++;

                // Get file name.
                $file = zip_entry_name($zip_entry);
                $this->writeLog("  [{$z}] File name: {$file}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['ZIP_CONTENTS'], $file);

                /**
                 * Match for a specific string in file.
                 *
                 * Make note of expected files:
                 *  - PDF
                 *  - XML
                 *  - all else are supplementary files
                 *
                 *  The String "0016" is specific to BC.
                 */
                if (preg_match('/0016/', $file)) {
                    $fileName = strtolower(substr($file,strlen($file)-3));

                    // Check if this is a PDF file.
                    if ($fileName === 'pdf' && empty($this->localFiles[$etdname]['ETD'])) {
                        $this->localFiles[$etdname]['ETD'] = $file;
                        $this->localFiles[$etdname]['FILE_ETD'] = $file;
                        $this->writeLog("      File type: PDF.", $fn, $etdname);
                        continue;
                    }

                    // Check if this is an XML file.
                    if ($fileName === 'xml' && empty($this->localFiles[$etdname]['METADATA'])) {
                        $this->localFiles[$etdname]['METADATA'] = $file;
                        $this->localFiles[$etdname]['FILE_METADATA'] = $file;
                        $this->writeLog("      File type: XML.", $fn, $etdname);
                        continue;
                    }

                    /**
                     * Supplementary files - could be permissions or data.
                     * Metadata will contain boolean key for permission in DISS_file_descr element.
                     * [0] element should always be folder.
                     */

                    // Ignore directories
                    try {
                        if (is_dir($etdDir . "/" . $file)) {
                            $this->writeLog("      This is a directory. Skipping.", $fn, $etdname);
                            array_push($this->localFiles[$etdname]['ZIP_CONTENTS_DIRS'], $file);
                            continue;
                        }
                    } catch (Exception $e) {
                        $errorMessage = "Couldn't check if file is a directory: " . $e->getMessage();
                        $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                        $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                        // array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                        continue;
                    }

                    // Check if any directory name is in the file name.
                    // TODO: refactor
                    foreach ($this->localFiles[$etdname]['ZIP_CONTENTS_DIRS'] as $dir) {
                        if (str_contains($file, $dir)) {
                            // This file is flagged as a supplemental file.
                            ;
                        } else {
                            // Something is wrong since there are multiple files 
                            // in the root of the zip file.
                            $this->writeLog("      WARNING: potential supplementary file found in root of the zip file.", $fn, $etdname);
                        }
                    }
                    
                    // TODO: remove this
                    $this->localFiles[$etdname]['UNKNOWN'.$supplement] = $file;
                    $supplement++;

                    array_push($this->localFiles[$etdname]['SUPPLEMENTS'], $file);
                    $this->localFiles[$etdname]['HAS_SUPPLEMENTS'] = true;
                    $this->countSupplementalETDs++;
                    array_push($this->allSupplementalETDs, $filename);
                    $this->writeLog("      This is a supplementary file.", $fn, $etdname);
                }
            }

            if ($this->localFiles[$etdname]['HAS_SUPPLEMENTS']){
                // At this point we can leave this function if the ETD has supplemental files.
                $this->writeLog("This ETD has supplementary files. No further processing is required. Moving to the next ETD.", $fn, $etdname);
                $this->writeLog("END Gathering ETD file [{$f} of {$this->countTotalETDs}]", $fn, $etdname);
                $this->localFiles[$etdname]['STATUS'] = "skipped";
                continue;
            } else {
                array_push($this->allRegularETDs, $filename);
            }

            /**
             * Check that both:
             *  - $this->localFiles[$etdname]['ETD']
             *  - $this->localFiles[$etdname]['METADATA']
             * are defined and are nonempty strings.
             */
            $this->writeLog("Checking that PDF and XML files were found in this zip file...", $fn, $etdname);
            if ( empty($this->localFiles[$etdname]['ETD']) ) {
                $errorMessage = "The ETD PDF file was not found or set.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localFiles[$etdname]['STATUS'] = "failure";
                continue;
            }
            $this->writeLog("✓ The ETD PDF file was found.", $fn, $etdname);

            if ( empty($this->localFiles[$etdname]['METADATA']) ) {
                $errorMessage = "The ETD XML file was not found or set.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localFiles[$etdname]['STATUS'] = "failure";
                continue;
            }
            $this->writeLog("✓ The ETD XML file was found.", $fn, $etdname);

            $zip = new ZipArchive;

            // Open and extract zip file to local directory.
            $res = $zip->open($localFile);
            if ($res === TRUE) {
                $zip->extractTo($etdDir);
                $zip->close();

                $this->writeLog("Extracting ETD zip file to local working directory.", $fn, $etdname);
                // $this->writeLog("   {$etdDir}", $fn, $etdname);
            } else {
                $errorMessage = "Failed to extract ETD zip file: " . $res;
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                continue;
            }

            $this->writeLog("END Gathering ETD file [{$f} of {$this->countTotalValidETDs}]", $fn);
            $this->localFiles[$etdname]['STATUS'] = "success";
        }

        // Completed fetching all ETD zip files.
        $this->writeLog("------------------------------", $fn);
        $this->writeLog("Completed fetching all ETD zip files from FTP server.", $fn);

        return true;
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
     * 
     * @throws Exception if XSLT files can't be found.
     */
    function processFiles() {
        $fn = "processFiles";

        // Return false if there are no ETD files to process.
        if ( empty($this->localFiles) ) {
            $errorMessage = "Did not find any ETD files to process.";
            $this->writeLog($errorMessage, $fn);
            
            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        $this->writeLog("########################", $fn);
        $this->writeLog("Now processing {$this->countTotalValidETDs} ETD file(s).", $fn);

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
            $errorMessage = "Failed to load MODS XSLT stylesheet.";
            $this->writeLog("ERROR: {$errorMessage}", $fn);

            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
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
            $errorMessage = "Failed to load Fedora Label XSLT stylesheet.";
            $this->writeLog("ERROR: {$errorMessage}", $fn);

            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        /**
         * Given the array of ETD local files, generate additional metadata.
         */
        $s = 0;
        foreach ($this->localFiles as $file => $submission) {
            $s++;

            // TODO: check for:
            //  * $this->localFiles[$etdname]['STATUS']
            //  * $this->localFiles[$etdname]['HAS_SUPPLEMENTS']

            // Pull out the ETD shortname that was generated in getFiles()
            $etdname = $this->localFiles[$file]['ETD_SHORTNAME'];
            $etdWorkingDir = $this->localFiles[$file]['WORKING_DIR'];

            if ( empty($etdname) ) {
                $etdname = substr($this->localFiles[$file]["ETD"],0,strlen($this->localFiles[$file]["ETD"])-4);
                $this->localFiles[$file]['ETD_SHORTNAME'] = $etdname;
            }
            $this->writeLog("------------------------------", $fn);
            $this->writeLog("BEGIN Processing ETD file [{$s} of {$this->countTotalETDs}]", $fn, $etdname);

            // No need to process ETDs that have supplemental files.
            if ($this->localFiles[$file]["HAS_SUPPLEMENTS"]) {
                $this->writeLog("SKIP Processing ETD since it contains supplemental files.", $fn, $etdname);
                $this->writeLog("END Processing ETD file [{$s} of {$this->countTotalValidETDs}]", $fn, $etdname);
                continue;
            }

            // Create XPath object from the ETD XML file.
            $metadata = new DOMDocument();
            $metadata->load($etdWorkingDir . '//' . $submission['METADATA']);
            $xpath = new DOMXpath($metadata);

            /**
             * Get OA permission.
             * This looks for the existance of an "oa" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_acceptance/text()
             */
            $this->writeLog("Searching for OA agreement...", $fn, $etdname);

            $openaccess = 0;
            $openaccess_available = false;
            $oaElements = $xpath->query($this->settings['xslt']['oa']);
            if ($oaElements->length === 0 ) {
                $this->writeLog("No OA agreement found.", $fn, $etdname);
            } elseif ($oaElements->item(0)->C14N() === '0') {
                $this->writeLog("No OA agreement found.", $fn, $etdname);
            } else {
                // This value is '1' if available for Open Access.
                $openaccess = $oaElements->item(0)->C14N();
                $openaccess_available = true;
                $this->writeLog("Found an OA agreement.", $fn, $etdname);
            }

            $this->localFiles[$file]['OA'] = $openaccess;
            $this->localFiles[$file]['OA_AVAILABLE'] = $openaccess_available;

            /**
             * Get embargo permission/dates.
             * This looks for the existance of an "embargo" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_delayed_release/text()
             */
            $this->writeLog("Searching for embargo information...", $fn, $etdname);

            $embargo = 0;
            $has_embargo = false;
            $this->localFiles[$file]['HAS_EMBARGO'] = false;
            $emElements = $xpath->query($this->settings['xslt']['embargo']);
            if ($emElements->item(0) ) {
                $has_embargo = true;
                // Convert date string into proper PHP date object format.
                $embargo = $emElements->item(0)->C14N();
                $this->writeLog("Unformatted embargo date: {$embargo}", $fn, $etdname);
                $embargo = str_replace(" ","T",$embargo);
                $embargo = $embargo . "Z";
                $this->writeLog("Using embargo date of: {$embargo}", $fn, $etdname);
            } else {
                $this->writeLog("There is no embargo on this record.", $fn, $etdname);
            }

            /**
             * Check to see if there is no OA policy, and there is no embargo.
             * If so, set the embargo permission/date to "indefinite".
             */
            if ($openaccess === $embargo) {
                $embargo = 'indefinite';
                $has_embargo = true;
                $this->writeLog("Changing embargo date to 'indefinite'", $fn, $etdname);
                $this->writeLog("Using embargo date of: {$embargo}", $fn, $etdname);
            }

            $this->localFiles[$file]['HAS_EMBARGO'] = $has_embargo;
            $this->localFiles[$file]['EMBARGO'] = $embargo;
            $this->localFiles[$file]['EMBARGO_DATE'] = $embargo;

            /**
             * Fetch next PID from Fedora.
             * Prepend PID with locally defined Fedora namespace.
             * Ex: "bc-ir:" for BC.
             */
            // DEBUG: generate random PID.
            if ($this->debug === true) {
                $pid = "bc-ir:" . rand(50000,100000);
                $this->writeLog("DEBUG: Generating random PID for testing (NOT fetched from Fedora): {$pid}", $fn, $etdname);
            } else {
                $pid = $this->api_m->getNextPid($this->settings['fedora']['namespace'], 1);
                $this->writeLog("Fetched new PID from Fedora: {$pid}", $fn, $etdname);
            }

            $this->localFiles[$file]['PID'] = $pid;

            $this->writeLog("Fedora PID value for this ETD: {$pid}", $fn, $etdname);

            /**
             * Insert the PID value into the Proquest MODS XSLT stylesheet.
             * The "handle" value should be set the PID.
             */
            $res = $xslt->setParameter('mods', 'handle', $pid);
            if ($res === false) {
                $errorMessage = "Could not update XSLT stylesheet with PID value.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localFiles[$file]["STATUS"] = "failed";
                array_push($this->allFailedETDs, $filename);
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
                $errorMessage = "Could not transform ETD MODS XML file.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localfiles[$file]["STATUS"] = "failed";
                array_push($this->allFailedETDs, $filename);
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
                $errorMessage = "Could not generate ETD title using Fedora Label XSLT stylesheet.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localfiles[$file]["STATUS"] = "failed";
                array_push($this->allFailedETDs, $filename);
                continue;
            }
            $this->localFiles[$file]['LABEL'] = $fedoraLabel;

            $this->writeLog("Generated ETD title: " . $fedoraLabel, $fn, $etdname);

            /**
             * Generate ETD author.
             * This looks for the existance of an "author" node in the MODS XPath object.
             * Ex: /mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()
             */
            $xpathAuthor = new DOMXpath($mods);
            $authorElements = $xpathAuthor->query($this->settings['xslt']['creator']);
            $author = $authorElements->item(0)->C14N();
            $this->writeLog("Generated ETD author: [{$author}]", $fn, $etdname);

            /**
             * Normalize the ETD author string. This forms the internal file name convention.
             * Ex: Jane Anne O'Foo => Jane-Anne-OFoo
             */
            #$normalizedAuthor = str_replace(array(" ",",","'",".","&apos;",'"',"&quot;"), array("-","","","","","",""), $author);
            $normalizedAuthor = $this->normalizeString($author);
            $this->localFiles[$file]['AUTHOR'] = $author;
            $this->localFiles[$file]['AUTHOR_NORMALIZED'] = $normalizedAuthor;

            $this->writeLog("Generated normalized ETD author: [{$normalizedAuthor}]", $fn, $etdname);
            $this->writeLog("Now using the normalized ETD author name to update ETD PDF and MODS files.", $fn, $etdname);

            // Create placeholder full-text text file using normalized author's name.
            $this->localFiles[$file]['FULLTEXT'] = $normalizedAuthor . ".txt";
            //$this->writeLog("Generated placeholder full text file name: " . $this->localFiles[$file]['FULLTEXT'], $fn, $etdname);

            // Rename Proquest PDF using normalized author's name.
            $res = rename($etdWorkingDir . "/". $submission['ETD'] , $etdWorkingDir . "/" . $normalizedAuthor . ".pdf");
            if ($res === false) {
                $errorMessage = "Could not rename ETD PDF file.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localfiles[$file]["STATUS"] = "failed";
                array_push($this->allFailedETDs, $filename);
                continue;
            }

            // Update local file path for ETD PDF file.
            $this->localFiles[$file]['ETD'] = $normalizedAuthor . ".pdf";
            $this->writeLog("Renamed ETD PDF file from {$submission['ETD']} to {$this->localFiles[$file]['ETD']}", $fn, $etdname);

            // Save MODS using normalized author's name.
            $res = $mods->save($etdWorkingDir . "/" . $normalizedAuthor . ".xml");
            if ($res === false) {
                $errorMessage = "Could not create new ETD MODS file.";
                $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                array_push($this->localFiles[$etdname]['INGEST_ERRORS'], $errorMessage);
                $this->localfiles[$file]["STATUS"] = "failed";
                array_push($this->allFailedETDs, $filename);
                continue;
            }

            // Update local file path for MODS file.
            $this->localFiles[$file]['MODS'] = $normalizedAuthor . ".xml";
            $this->writeLog("Created new ETD MODS file {$this->localFiles[$file]['MODS']}", $fn, $etdname);


            /**
             * Check for supplemental files.
             * This looks for the existance of an "DISS_attachment" node in the ETD XML XPath object.
             * Ex: /DISS_submission/DISS_content/DISS_attachment
             *
             * Previous comments (possibly outdated):
             *    UNKNOWN0 in lookup should mean there are other files
             *    also, Proquest MD will have DISS_attachment
             *    ($this->localFiles[$file]['UNKNOWN0']) or
             */
            $suppxpath = new DOMXpath($metadata);
            $suElements = $suppxpath->query($this->settings['xslt']['supplement']);

            // $this->writeLog("Checking for existence supplemental files...", $fn, $etdname);

            // // Check if there are zero or more supplemental files.
            // if ($suElements->item(0) ) {
            //     $this->localFiles[$file]['PROCESS'] = "0";
            //     $this->writeLog("No supplemental files found.", $fn, $etdname);
            // } else {
            //     $this->localFiles[$file]['PROCESS'] = "1";
            //     $this->writeLog("Found a supplemental file(s).", $fn, $etdname);

            //     // Keep track of how many additional PIDs will need to be generated.
            //     $this->toProcess++;
            // }

            $this->localFiles[$etdname]['STATUS'] = "processed";
            $this->writeLog("END Processing ETD [#{$s} of {$this->countTotalETDs}]", $fn, $etdname);
        }

        // Completed processing all ETD files.
        $this->writeLog("------------------------------", $fn);
        $this->writeLog("Completed processing all ETD files.", $fn);

        return true;
    }

    /**
     * Initializes a connection to a Fedora file repository server.
     * 
     * @return Boolean Success value.
     */
    function initFedoraConnection() {
        $fn = "initFedoraConnection";
        $url = $this->settings['fedora']['url'];
        $user = $this->settings['fedora']['username'];
        $pass = $this->settings['fedora']['password'];

        // Check all values exist.
        if (empty($url) || empty($user) || empty($pass)) {
            $errorMessage = "Can't connect to Fedora instance. One or more Fedora settings are not set.";
            $this->writeLog("ERROR: {$errorMessage}", $fn);
            array_push($this->processingErrors, $errorMessage);
            return false;
        }

        // Make Fedora repository connection.
        // Tuque library exceptions defined here:
        // https://github.com/Islandora/tuque/blob/7.x-1.7/RepositoryException.php
        try {
            $this->connection = new RepositoryConnection($url, $user, $pass);
            $this->api = new FedoraApi($this->connection);
            $this->repository = new FedoraRepository($this->api, new simpleCache());
            $this->writeLog("Connected to the Fedora repository.", $fn);
        } catch(Exception $e) { // RepositoryException
            $errorMessage = "Can't connect to Fedora instance: " . $e->getMessage();
            $this->writeLog("ERROR: {$errorMessage}", $fn);
            $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn);
            return false;
        }

        // TODO: make a test connection

        // Fedora Management API.
        $this->api_m = $this->repository->api->m;
        return true;
    }

    /**
     * Manages the post-process handling of an ETD ingest. 
     * TODO: This function will be replaced by calls to postProcess().
     *
     * @param boolean $status The success status of the calling function.
     * @param string $etdname The name of the ETD to print.
     * @param object $etd An object containing the ETD submission metadata.
     * 
     * @return boolean Sucess value.
     */
    private function ingestHandlerPostProcess($status, $etdname, $etd){
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
            $successMessage .= " • " . $submission['PID'] . "\t";

            // Set success status for email message.
            if (isset($submission['EMBARGO'])) {
                $successMessage .= "EMBARGO UNTIL: {$submission['EMBARGO']}\t";
            } else {
                $successMessage .= "NO EMBARGO\t";
            }
            $successMessage .= $submission['LABEL'] . "\n";

            // Move processed PDF file to a new directory. Ex: /path/to/files/processed
            $processdirFTP = $this->settings['ftp']['processdir'];
            $fullProcessdirFTP = "~/" . $processdirFTP . "/" . $fnameFTP;

            // TODO: use relative or absolute path?
            $this->writeLog("Currently in FTP directory: {$this->ftp->ftp_pwd()}", $fn, $etdname);

            $this->writeLog("Now attempting to move {$fullfnameFTP} into {$fullProcessdirFTP}", $fn, $etdname);

            if ($this->debug === true) {
                $this->writeLog("DEBUG: Not moving ETD files on FTP.", $fn, $etdname);
                return true;
            }

            $ftpRes = $this->ftp->ftp_rename($fullfnameFTP, $fullProcessdirFTP);
            
            // Check if there was an error moving the ETD file on the FTP server.
            if ($ftpRes === false) {
                $this->writeLog("ERROR: Could not move ETD file to 'processed' FTP directory!", $fn, $etdname);
                return false;
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

            $this->writeLog("Now attempting to move {$fullfnameFTP} into {$fullFaildirFTP}", $fn, $etdname);

            if ($this->debug === true) {
                $this->writeLog("DEBUG: Not moving ETD files on FTP.", $fn, $etdname);
                return true;
            }

            $ftpRes = $this->ftp->ftp_rename($fullfnameFTP, $fullFaildirFTP);

            // Check if there was an error moving the ETD file on the FTP server.
            if ($ftpRes === false) {
                $this->writeLog("ERROR: Could not move ETD file to 'failed' FTP directory!", $fn, $etdname);
                return false;
            }

            $this->writeLog("Moved ETD file to 'failed' FTP directory.", $fn, $etdname);
        }

        return true;
    }

    /**
     * Generate a simple status update message
     */
    function statusCheck(){
        $fn = "statusCheck";

        // List all ETDS
        $message = "\n";

        // First, find if there are processing errors
        $countProcessingErrors = count($this->processingErrors);

        // Check if there are processing errors.
        if ($countProcessingErrors >  0) {
            $message .= "This script failed to run because of the following issue(s):\n";
            
            foreach ($this->processingErrors as $processingError) {
                $message .= "  • {$processingError}\n";
            }
        } else {
            $i = 0;

            $countETDs = count($this->localFiles);
            $message .= "There were {$countETDs} ETD(s) processed.\n"; 
            foreach ($this->localFiles as $local) {
                $i++;
                $errorsCount = count($local["INGEST_ERRORS"]);
                $message .= "\n  [{$i}] Zip filename:      {$local['ZIP_FILENAME']}\n";
                $message .= "      Status:            {$local['STATUS']}\n";
                $message .= "      Has supplements:   " . ($local['HAS_SUPPLEMENTS'] ? "true" : "false") . "\n";
                
                // If this ETD has supplements then display message and continue to next ETD.
                if ($local['HAS_SUPPLEMENTS']) {
                    $message .= "      WARNING: This ETD contains supplemental files and was not processed.\n";
                    continue;
                }

                // Display ingest errors and continue to next ETD.
                if ($errorsCount > 0) {
                    $message .= "      WARNING: This ETD failed to ingest because of the following reasons(s):\n";
                    foreach ($local["INGEST_ERRORS"] as $ingestError) {
                        $message .= "       • {$ingestError}\n";
                    }
                    continue;
                }

                $message .= "      Has OA agreement:  " . ($local['OA_AVAILABLE'] ? "true" : "false") . "\n";
                $message .= "      Has embargo:       " . ($local['HAS_EMBARGO'] ? "true" : "false") . "\n";
                if ($local['HAS_EMBARGO']) {
                    $message .= "      Embargo date:      {$local['EMBARGO_DATE']}\n";
                }
                $message .= "      PID:               {$local['PID']}\n";
                $message .= "      URL:               {$local['RECORD_URL']}\n";
                $message .= "      Author:            {$local['AUTHOR']}\n";
                $message .= "      ETD title:         {$local['LABEL']}\n";
            }
        }

        $message .= "\nThe full log file can be found at:\n{$this->logFileLocation}.\n";

        return $message;
    }

    /**
     * Parse script results and compose email body.
     */
    private function postProcess() {
        /*
         * Steps: 
         *  check $this->processingErrors[]
         *  check $this->allFoundETDs[]
         *  check each $this->localFiles[] as local
         *      local["HAS_SUPPLEMENTS]
         *      local["STATUS"]
         *      local["PID"]
         *      local["LABEL"]
         *      local["AUTHOR"]
         *      local["HAS_EMBARGO"]
         *      local["EMBARGO_DATE"]
         *      local["INGESTED"] ??
        */
        $fn = "postProcess";

        // $this->writeLog("Parsing script results.", $fn);

        // Get overall status.
        $message = $this->statusCheck();

        // Move files in FTP server
        $ret = $this->moveFTPFiles();

        // Send email

        return true;
    }

    /**
     * Process a failed datastream ingest.
     * 
     * @param string $errorMessage the error message to display
     * @param string $datastreamName the name of the datastream
     * @param integer $fileIndex the localFiles index
     * @param string $etdname the name of the ETD file 
     */
    private function datastreamIngestFailed($errorMessage, $datastreamName, $fileIndex, $etdName) {
        $functionName = "ingest";
        array_push($this->allFailedETDs, $this->localFiles[$fileIndex]["ETD_SHORTNAME"]);
        array_push($this->localFiles[$fileIndex]['INGEST_ERRORS'], $errorMessage);
        $this->writeLog("[{$datastreamName}] ERROR: $errorMessage", $functionName, $etdName);
        // $this->writeLog("[{$datastreamName}] trace:\n" . $e->getTraceAsString(), $functionName, $etdName);
        $this->localfiles[$fileIndex]["STATUS"] = "failed";
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
     * 
     * @return boolean Success value
     * 
     * @throws Exception if there are no ETDs to ingest
     */
    function ingest() {
        $fn = "ingest";

        // Check to see if there are any ETD files to process.
        if ( empty($this->localFiles) ) {
            // Shortcut to sending email update.
            $message = "No ETD files to ingest.";
            $this->writeLog($message, $fn);
            $res = $this->sendEmail($message);

            // TODO: call postProcess()
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        $this->writeLog("########################", $fn);
        $this->writeLog("Now Ingesting {$this->countTotalETDs} ETD file(s).", $fn);

        global $pidcount, $successCount, $failureCount;
        global $successMessage, $failureMessage, $processingMessage;

        $successMessage = "The following ETDs ingested successfully:\n";
        $failureMessage = "\n\nWARNING!! The following ETDs __FAILED__ to ingest:\n";
        $processingMessage = "\n\nThe following staging directories were used:\n";

        $fop_config = $this->settings['packages']['fop_config'];
        $executable_fop = $this->settings['packages']['fop'];
        $executable_convert = $this->settings['packages']['convert'];
        $executable_pdftk = $this->settings['packages']['pdftk'];
        $executable_pdftotext = $this->settings['packages']['pdftotext'];

        // TODO: list the file path for script log.

        // Go through each ETD local file bundle.
        $i = 0;
        foreach ($this->localFiles as $file => $submission) {
            $i++;

            $workingDir = $submission['WORKING_DIR'];
            $this->localFiles[$file]['DATASTREAMS_CREATED'] = [];
            $this->localFiles[$file]['INGESTED'] = false;
            $this->localFiles[$file]['INGEST_ERRORS'] = [];

            $processingMessage .= " • " . $workingDir . "\n";

            // Pull out the ETD shortname that was generated in getFiles()
            $etdname = $this->localFiles[$file]['ETD_SHORTNAME'];
            if ( empty($etdname) ) {
                $etdname = substr($this->localFiles[$file]["ETD"],0,strlen($this->localFiles[$file]["ETD"])-4);
                $this->localFiles[$file]['ETD_SHORTNAME'] = $etdname;
            }
            $this->writeLog("------------------------------", $fn);
            $this->writeLog("BEGIN Ingesting ETD file [{$i} of {$this->countTotalETDs}]", $fn, $etdname);

            // No need to process ETDs that have supplemental files.
            if ($this->localFiles[$file]["HAS_SUPPLEMENTS"]) {
                $this->writeLog("SKIP Ingesting ETD since it contains supplemental files.", $fn, $etdname);
                $this->writeLog("END Ingesting ETD file [{$i} of {$this->countTotalETDs}]", $fn, $etdname);
                continue;
            }

            // Reconstruct name of zip file from the local ETD work space directory name.
            // TODO: there must be a better way to do this...
            //$directoryArray = explode('/', $workingDir);
            //$fnameFTP = array_values(array_slice($directoryArray, -1))[0] . '.zip';

            // Build full FTP path for ETD file incase $fetchdirFTP is not the root directory.
            // $fetchdirFTP = $this->settings['ftp']['fetchdir'];
            // $fullfnameFTP = "";
            // if ($this->fetchdirFTP == "") {
            //     $fullfnameFTP = $fnameFTP;
            // } else {
            //     $fullfnameFTP = "~/" . $this->fetchdirFTP . "/" . $fnameFTP;
            // }
            $fullfnameFTP = $this->localFiles[$file]["FTP_PATH_FOR_ETD"];
            $this->writeLog("The full path of the ETD file on the FTP server is: {$fullfnameFTP}", $fn, $etdname);

            // collect some values for ingestHandlerPostProcess()
            // $this->etd["submission"] = $submission;
            // $this->etd["fnameFTP"] = $fnameFTP;
            // $this->etd["fullfnameFTP"] = $fullfnameFTP;

            // Check for supplemental files, and create log message.
            // if ($this->localFiles[$file]['PROCESS'] === '1') {
            //     // Still Load - but notify admin about supp files.
            //     $this->writeLog("Supplementary files found.", $fn, $etdname);
            // }

            // Instantiated a Fedora object and use the generated PID as its ID.
            // TODO: not sure this function throws an exception
            //       https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php
            try {
                $fedoraObj = $this->repository->constructObject($this->localFiles[$file]['PID']);
                $this->writeLog("Instantiated a Fedora object with PID: {$this->localFiles[$file]['PID']}", $fn, $etdname);
            } catch (Exception $e) {
                $errorMessage = "Could not instanciate a Fedora object with PID '" . $this->localFiles[$file]['PID'] . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                // $errorMessage = "Could not instanciate Fedora object: " . $e->getMessage();
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog($errorMessage, $fn, $etdname);
                // $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Assign the Fedora object label the ETD name/label
            $fedoraObj->label = $this->localFiles[$file]['LABEL'];
            $this->writeLog("Assigned a title to Fedora object: {$this->localFiles[$file]['LABEL']}", $fn, $etdname);

            // All Fedora objects are owned by the same generic account
            $fedoraObj->owner = 'fedoraAdmin';

            $this->writeLog("Now generating Fedora datastreams.", $fn, $etdname);


            /**
             * Generate RELS-EXT (XACML) datastream.
             *
             *
             */
            $dsid = "RELS-EXT";
            $this->writeLog("[{$dsid}] Generating (XACML) datastream.", $fn, $etdname);

            // Set the default Parent and Collection policies for the Fedora object.
            try {
                $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID);
                $collectionName = GRADUATE_THESES;
            } catch (Exception $e) { // RepositoryException
                $errorMessage = "Could not fetch Fedora object '" . ISLANDORA_BC_ROOT_PID . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog($errorMessage, $fn, $etdname);
                // $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Update the Parent and Collection policies if this ETD is embargoed.
            if (isset($this->localFiles[$file]['EMBARGO'])) {
                $collectionName = GRADUATE_THESES_RESTRICTED;
                try {
                    $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID_EMBARGO);
                    $this->writeLog("[{$dsid}] Adding to Graduate Theses (Restricted) collection.", $fn, $etdname);
                } catch (Exception $e) { // RepositoryException
                    $errorMessage = "Could not fetch Fedora object '" . ISLANDORA_BC_ROOT_PID_EMBARGO . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                    // $errorMessage = "Could not instanciate Fedora object 'GRADUATE_THESES_RESTRICTED': " . $e->getMessage();
                    // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                    // $this->writeLog($errorMessage, $fn, $etdname);
                    // $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                    // $this->localfiles[$file]["STATUS"] = "failed";
                    // array_push($this->allFailedETDs, $filename);
                    $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                    continue;
                }
            } else {
                $this->writeLog("[{$dsid}] Adding to Graduate Theses collection.", $fn, $etdname);
            }

            // Update the Fedora object's relationship policies
            $fedoraObj->models = array('bc-ir:graduateETDCModel');
            $fedoraObj->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $collectionName);

            // Set various other Fedora object settings.
            $fedoraObj->checksumType = 'SHA-256';
            $fedoraObj->state = 'I';

            // Get Parent XACML policy.
            $policyObj = $parentObject->getDatastream(ISLANDORA_BC_XACML_POLICY);
            $this->writeLog("[{$dsid}] Fetching Islandora XACML datastream.", $fn, $etdname);
            $this->writeLog("[{$dsid}] Deferring RELS-EXT (XACML) datastream ingestion until other datastreams are generated.", $fn, $etdname);


            /**
             * Build MODS Datastream.
             *
             *
             */
            $dsid = 'MODS';
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);

            // Build Fedora object MODS datastream.
            $datastream = $fedoraObj->constructDatastream($dsid, 'X');

            // Set various MODS datastream values.
            $datastream->label = 'MODS Record';
            // OLD: $datastream->label = $this->localFiles[$file]['LABEL'];
            $datastream->mimeType = 'application/xml';

            // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/author_name.XML
            $datastream->setContentFromFile($workingDir . "//" . $this->localFiles[$file]['MODS']);
            $this->writeLog("[{$dsid}] Selecting file for this datastream:", $fn, $etdname);
            $this->writeLog("[{$dsid}]   {$this->localFiles[$file]['MODS']}", $fn, $etdname);

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Build ARCHIVE MODS datastream.
             *
             * Original Proquest Metadata will be saved as ARCHIVE.
             * Original filename is used as label for identification.
             */
            $dsid = 'ARCHIVE';
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);

            // Build Fedora object ARCHIVE MODS datastream from original Proquest XML.
            $datastream = $fedoraObj->constructDatastream($dsid, 'X');

            // Assign datastream label as original Proquest XML file name without file extension. Ex: etd_original_name
            $datastream->label = substr($this->localFiles[$file]['METADATA'], 0, strlen($this->localFiles[$file]['METADATA'])-4);
            //$this->writeLog("Using datastream label: " . $datastream->label, $fn, $etdname);

            // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/etd_original_name.XML
            $datastream->setContentFromFile($workingDir . "//" . $this->localFiles[$file]['METADATA']);
            $this->writeLog("[{$dsid}] Selecting file for this datastream:", $fn, $etdname);
            $this->writeLog("[{$dsid}]    {$this->localFiles[$file]['METADATA']}", $fn, $etdname);

            // Set various ARCHIVE MODS datastream values.
            $datastream->mimeType = 'application/xml';
            $datastream->checksumType = 'SHA-256';
            $datastream->state = 'I';

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Build ARCHIVE-PDF datastream.
             *
             * PDF will always be loaded as ARCHIVE-PDF DSID regardless of embargo.
             * Splash paged PDF will be PDF dsid.
             */
            $dsid = 'ARCHIVE-PDF';
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);

            // Default Control Group is M.
            // Build Fedora object ARCHIVE PDF datastream from original Proquest PDF.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // OLD: $datastream->label = $this->localFiles[$file]['LABEL'];
            $datastream->label = 'ARCHIVE-PDF Datastream';

            // Set various ARCHIVE-PDF datastream values.
            $datastream->mimeType = 'application/pdf';
            $datastream->checksumType = 'SHA-256';
            $datastream->state = 'I';

            // Set datastream content to be ARCHIVE-PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $datastream->setContentFromFile($workingDir . "//" . $this->localFiles[$file]['ETD']);
            $this->writeLog("[{$dsid}] Selecting file for this datastream:", $fn, $etdname);
            $this->writeLog("[{$dsid}]   {$this->localFiles[$file]['ETD']}", $fn, $etdname);

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Build PDF datastream.
             *
             * First, build splash page PDF.
             * Then, concatenate splash page onto ETD PDF for final PDF.
             */
            $dsid = "PDF";
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);
            $this->writeLog("[{$dsid}] First, generate PDF splash page.", $fn, $etdname);

            // Source file is the original Proquest XML file.
            $source = $workingDir . "/" . $this->localFiles[$file]['MODS'];

            // Assign PDF splash document to ETD file's directory.
            $splashtemp = $workingDir . "/splash.pdf";

            // Use the custom XSLT splash stylesheet to build the PDF splash document.
            $splashxslt = $this->settings['xslt']['splash'];

            // Use FOP (Formatting Objects Processor) to build PDF splash page.
            // Execute 'fop' command and check return code.
            $command = "$executable_fop -c $fop_config -xml $source -xsl $splashxslt -pdf $splashtemp";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'fop' command to build PDF splash page.", $fn, $etdname);

    		if (!$return) {
                $this->writeLog("[{$dsid}] Splash page created successfully.", $fn, $etdname);
    		} else {
                $errorMessage = "PDF splash page creation failed! ". $return;
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
    		    continue;
    		}

            // Update ETD file's object to store splash page's file location and name.
            $this->localFiles[$file]['SPLASH'] = 'splash.pdf';
            array_push($this->localFiles[$file]['DATASTREAMS_CREATED'], "SPLASH");

            /**
             * Build concatted PDF document.
             *
             * Load splash page PDF to core PDF if under embargo. -- TODO: find out when/how this happens
             */
            $this->writeLog("[{$dsid}] Next, generate concatenated PDF document.", $fn, $etdname);

            // Assign concatenated PDF document to ETD file's directory.
            $concattemp = $workingDir . "/concatted.pdf";

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $pdf = $workingDir . "//" . $this->localFiles[$file]['ETD'];

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
            $this->writeLog("[{$dsid}] WARNING: A splashpage will not be appended to the ingested PDF file. Instead, a clone of the original PDF will be used.", $fn, $etdname);

            if (!copy($pdf,$concattemp)) {
                // TODO: handle this error case
                $this->writeLog("[{$dsid}] ERROR: PDF document cloning failed!", $fn, $etdname);
            } else {
                $this->writeLog("[{$dsid}] PDF document cloned successfully.", $fn, $etdname);
            }

            // Default Control Group is M
            // Build Fedora object PDF datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various PDF datastream values.
            $datastream->label = 'PDF Datastream';
            $datastream->mimeType = 'application/pdf';
            $datastream->checksumType = 'SHA-256';

            // Set datastream content to be PDF file. Ex: /tmp/processed/file_name_1234/concatted.PDF
            $datastream->setContentFromFile($concattemp);
            $this->writeLog("[{$dsid}] Selecting file for datastream:", $fn, $etdname);
            $this->writeLog("[{$dsid}]    {$concattemp}", $fn, $etdname);

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Build FULL_TEXT datastream.
             *
             *
             */
            $dsid = "FULL_TEXT";
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $source = $workingDir . "/" . $this->localFiles[$file]['ETD'];

            // Assign FULL_TEXT document to ETD file's directory.
            $fttemp = $workingDir . "/fulltext.txt";

            // Use pdftotext (PDF to Text) to generate FULL_TEXT document.
            // Execute 'pdftotext' command and check return code.
            $command = "$executable_pdftotext $source $fttemp";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'pdftotext' command.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("[{$dsid}] datastream generated successfully.", $fn, $etdname);
            } else {
                $errorMessage = "FULL_TEXT document creation failed!" . $return;
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Build Fedora object FULL_TEXT datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various FULL_TEXT datastream values.
            $datastream->label = 'FULL_TEXT';
            $datastream->mimeType = 'text/plain';

            // Read in the full-text document that was just generated.
            $fulltext = file_get_contents($fttemp);

            // Check if file read failed.
            if ($fulltext === false) {
                $errorMessage = "Could not read in file: ". $fttemp;
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Strip out junky characters that mess up SOLR.
            $replacement = '';
            $sanitized = preg_replace('/[\x00-\x1f]/', $replacement, $fulltext);

            // In the slim chance preg_replace fails.
            if ($sanitized === null) {
                $errorMessage = "preg_replace failed to return valid sanitized FULL_TEXT string!";
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Set FULL_TEXT datastream to be sanitized version of full-text document.
            $datastream->setContentFromString($sanitized);
            $this->writeLog("[{$dsid}] Selecting file for datastream:", $fn, $etdname);
            $this->writeLog("[{$dsid}]    {$fttemp}", $fn, $etdname);

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Build Thumbnail (TN) datastream
             *
             *
             */
            $dsid = "TN";
            $this->writeLog("[{$dsid}] Generating (thumbnail) datastream.", $fn, $etdname);

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            // TODO: figure out what "[0]" means in this context.
            $source = $workingDir . "/" . $this->localFiles[$file]['ETD'] . "[0]";

            // Use convert (from ImageMagick tool suite) to generate TN document.
            // Execute 'convert' command and check return code.
            $command = "$executable_convert $source -quality 75 -resize 200x200 -colorspace RGB -flatten " . $workingDir . "/thumbnail.jpg";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'convert' command to build TN document.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("[{$dsid}] Datastream generated successfully.", $fn, $etdname);
            } else {
                $errorMessage = "TN document creation failed! " . $return;
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Build Fedora object TN datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various TN datastream values.
            $datastream->label = 'TN';
            $datastream->mimeType = 'image/jpeg';

            // Set TN datastream to be the generated thumbnail image.
            $datastream->setContentFromFile($workingDir . "//thumbnail.jpg");
            $this->writeLog("[{$dsid}] Selecting file for datastream: thumbnail.jpg", $fn, $etdname);

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Build PREVIEW datastream.
             *
             *
             */
            $dsid = "PREVIEW";
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            // TODO: figure out what "[0]" means in this context.
            $source = $workingDir . "/" . $this->localFiles[$file]['ETD'] . "[0]";

            // Use convert (from ImageMagick tool suite) to generate PREVIEW document.
            // Execute 'convert' command and check return code.
            $command = "$executable_convert $source -quality 75 -resize 500x700 -colorspace RGB -flatten " . $workingDir . "/preview.jpg";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'convert' command to build PREVIEW document.", $fn, $etdname);

            if (!$return) {
                $this->writeLog("[{$dsid}] PREVIEW datastream generated successfully.", $fn, $etdname);
            } else {
                $errorMessage = "PREVIEW document creation failed! " . $return;
                // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                // $this->localfiles[$file]["STATUS"] = "failed";
                // array_push($this->allFailedETDs, $filename);
                $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                continue;
            }

            // Build Fedora object PREVIEW datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various PREVIEW datastream values.
            $datastream->label = 'PREVIEW';
            $datastream->mimeType = 'image/jpeg';

            // Set PREVIEW datastream to be the generated preview image.
            $datastream->setContentFromFile($workingDir . "//preview.jpg");
            $this->writeLog("[{$dsid}] Selecting TN datastream to use: preview.jpg", $fn, $etdname);

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
            } catch(Exception $e) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }

            /**
             * Continue RELS-EXT datastream.
             *
             *
             */
            // TODO: understand why this command is down here and not in an earlier POLICY datastream section.
            $dsid = "RELS-EXT";
            $this->writeLog("[{$dsid}] Resuming RELS-EXT datastream ingestion now that other datastreams are generated.", $fn, $etdname);

            $status = $this->prepareIngestDatastream($fedoraObj, $policyObj, $dsid, $etdname);

            if (!$status) {
                // Ingest failed. Continue to the next ETD.
                continue;
            }


            /**
             * Build RELS-INT datastream.
             *
             * This checks if there is an OA policy set for this ETD.
             * If there is, then set Embargo date in the custom XACML policy file.
             */
            $dsid = "RELS-INT";
            $this->writeLog("[{$dsid}] Generating datastream.", $fn, $etdname);
            $this->writeLog("[{$dsid}] Reading in custom RELS XSLT file...", $fn, $etdname);

            // $submission['OA'] is either '0' for no OA policy, or some non-zero value.
            $relsint = '';
            $relsFile = "";
            if ($submission['OA'] === 0) {
                // No OA policy.
                $relsFile = "xsl/permRELS-INT.xml";
                $relsint = file_get_contents($relsFile);

                // Check if file read failed.
                if ($relsint === false) {
                    $errorMessage = "Could not read in file: " . $relsFile;
                    // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                    // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                    // $this->localfiles[$file]["STATUS"] = "failed";
                    // array_push($this->allFailedETDs, $filename);
                    $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                    continue;
                }

                $relsint = str_replace('######', $submission['PID'], $relsint);

                $this->writeLog("[{$dsid}] No OA policy for ETD: read in: {$relsFile}", $fn, $etdname);
            } else if (isset($submission['EMBARGO'])) {
                // Has an OA policy, and an embargo date.
                $relsFile = "xsl/embargoRELS-INT.xml";
                $relsint = file_get_contents($relsFile);

                // Check if file read failed.
                if ($relsint === false) {
                    $errorMessage = "Could not read in file: " . $relsFile;
                    // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                    // $this->writeLog("[{$dsid}] ERROR: {$errorMessage}", $fn, $etdname);
                    // $this->localfiles[$file]["STATUS"] = "failed";
                    // array_push($this->allFailedETDs, $filename);
                    $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                    continue;
                }

                $relsint = str_replace('######', $submission['PID'], $relsint);
                $relsint = str_replace('$$$$$$', $submission['EMBARGO'], $relsint);

                $this->writeLog("[{$dsid}] OA policy found and Embargo date found for ETD: read in: {$relsFile}", $fn, $etdname);
            }

            // TODO: handle case where there is an OA policy and no embargo date?

            // Ingest datastream if we have a XACML policy set.
            if (isset($relsint) && $relsint !== '') {
                $dsid = "RELS-INT";

                // Build Fedora object RELS-INT datastream.
                $datastream = $fedoraObj->constructDatastream($dsid);

                // Set various RELS-INT datastream values.
                $datastream->label = 'Fedora Relationship Metadata';
                $datastream->mimeType = 'application/rdf+xml';

                // Set RELS-INT datastream to be the custom XACML policy file read in above.
                $datastream->setContentFromString($relsint);
                $this->writeLog("[{$dsid}] Selecting fire for datastream: {$relsFile}", $fn, $etdname);

                try {
                    $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdname);
                } catch(Exception $e) {
                    // Ingest failed. Continue to the next ETD.
                    continue;
                }
            }

            // Completed datastream completion
            $this->writeLog("Created all datastreams.", $fn, $etdname);

            /**
             * Ingest full object into Fedora.
             *
             *
             */

            // DEBUG: ignore Fedora ingest.
            $res = true;
            if ($this->debug === true) {
                $this->writeLog("DEBUG: Ignore ingesting object into Fedora.", $fn, $etdname);
            } else {
                try {
                    $res = $this->repository->ingestObject($fedoraObj);
                    $this->writeLog("START ingestion of Fedora object...", $fn, $etdname);
                } catch (Exception $e) {
                    $errorMessage = "Could not ingest Fedora object: " . $e->getMessage();
                    // array_push($this->localFiles[$file]['INGEST_ERRORS'], $errorMessage);
                    // $this->writeLog("ERROR: {$errorMessage}", $fn, $etdname);
                    // $this->writeLog("trace:\n" . $e->getTraceAsString(), $fn, $etdname);
                    // $this->localfiles[$file]["STATUS"] = "failed";
                    // array_push($this->allFailedETDs, $filename);
                    $this->datastreamIngestFailed($errorMessage, $dsid, $file, $etdname);
                    continue;
                }
            }

            $this->localFiles[$file]["STATUS"] = "ingested";
            $this->localFiles[$file]['INGESTED'] = true;
            $this->countProcessedETDs++;
            array_push($this->allIngestedETDs, $this->localFiles[$file]["ETD_SHORTNAME"]);

            // Make sure we give every processing loop enough time to complete.
            sleep(2);

            // Assign URL to this ETD
            $this->localFiles[$file]['RECORD_URL'] = "{$this->record_path}{$this->localFiles[$file]["PID"]}";

            $this->writeLog("END Ingesting ETD file [{$i} of {$this->countTotalETDs}]", $fn, $etdname);
        }

        $this->writeLog("------------------------------", $fn);
        $this->writeLog("Completed ingesting all ETD files.", $fn);

        // Run a quick status check.
        $this->writeLog("------------------------------");
        $this->writeLog("Status Check:");
        $this->writeLog($this->statusCheck());
        $this->writeLog("------------------------------");

        // At this point run postProcess() to complete the workflow.
        $this->postProcess();

        return true;
    }
}
?>
