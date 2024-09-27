<?php declare(strict_types=1);
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
define('SECTION_DIVIDER', "###################################");
define('LOOP_DIVIDER', '-----------------------------------');

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
class Processproquest {

    public $settings;                       // Object to store script settings
    public $debug;                          // Debug bool
    protected $ftp;                         // FTP connection object
    protected $localFiles = [];             // Object to store all ETD metadata
    protected $connection;                  // Repository connection object
    protected $api;                         // Fedora API connection object
    protected $api_m;                       // Fedora API iterator object
    protected $repository;                  // Repository connection object
    protected $logFile = "";                // Log file name
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
    protected $currentProcessedETD = "";    // Current ETD that is being processed

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
        $this->processingFailure = false;

        // INFO: is_object() Returns true if value is an object, false otherwise.
        if ( is_object($logger) === false ) {
            // An empty logger object was passed.
            return false;
        }

        $this->logger = $logger;

        // Pull out logfile location from logger object.
        $logHandlers = $logger->getHandlers();
        foreach ($logHandlers as $handler) {
            $url = $handler->getUrl();
            // INFO: str_contains() Returns true if needle is in haystack, false otherwise.
            if ( str_contains($url, "php://") === true ) {
                // Ignore the stdout/console handler.
                continue;
            }
            $this->logFileLocation = $url;
        }

        $this->writeLog("STATUS: Starting processProquest script.");
        $this->writeLog("STATUS: Running with DEBUG value: " . ($this->debug ? 'TRUE' : 'FALSE'));
        $this->writeLog("STATUS: Using configuration file: {$this->configurationFile}");

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
    private function writeLog($message) {
        $functionName = debug_backtrace()[1]['function'];
        $completeMessage = "";
        $completeMessage .= "({$functionName}) ";

        // Check if $this->currentProcessedETD is nonempty and use the etdShortName as the $prefix value.
        $currentETDShortName = $this->currentProcessedETD;

        // INFO: empty() Returns true if var does not exist or has a value that is empty or equal to zero, 
        //       aka falsey. Otherwise returns false.
        if ( empty($currentETDShortName) === false ) {
            $completeMessage .= "[{$currentETDShortName}] ";
        } 

        $completeMessage .= "{$message}";

        // Write out message.
        // TODO: handle other logging levels
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
        $this->writeLog("Generating an email notification.");

        $email_to = $this->settings['notify']['email'];
        $email_subject = "Message from processProquest";
        $email_message = $message;

        // Check for empty email values.
        if ( empty($email_to) === true ) {
            $errorMessage = "Email to: field is empty.";
            $this->writeLog("ERROR: {$errorMessage}");
            return false;
        }

        if ( empty($email_subject) === true ) {
            $errorMessage = "Email subject: field is empty.";
            $this->writeLog("ERROR: {$errorMessage}");
            return false;
        }

        if ( empty($email_message) === true ) {
            $errorMessage = "Email body: field is empty.";
            $this->writeLog("ERROR: {$errorMessage}");
            return false;
        }

        $this->writeLog("Attempting to send out the following email:\n\tto:[" . $email_to . "]\n\tbody:[" . $email_message . "]");

        // DEBUG: don't send email.
        $res = true;
        if ( $this->debug === true ) {
            $this->writeLog("DEBUG: Not sending email notification.");
            return true;
        } else {
            // INFO: mail() Returns true if the mail was successfully accepted for delivery, false otherwise.
            $res = mail($email_to, $email_subject, $email_message);
        }

        // Check mail success.
        if ( $res === false ) {
            $errorMessage = "Email not sent.";
            $this->writeLog("ERROR: {$errorMessage}");
            return false;
        }

        $this->writeLog("Email sent.");

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
    public function initFTP() {
        $fn = "initFTP";

        $this->writeLog("Initializing FTP connection.");

        $urlFTP = $this->settings['ftp']['server'];
        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        if ( (empty($urlFTP) === true) || (empty($userFTP) === true) || (empty($passwordFTP) === true) ) {
            $errorMessage = "FTP login values are missing. Please check your settings.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            $this->processingFailure = true;
            throw new Exception($errorMessage);
        }

        // Create ftp object used for connection.
        $this->ftp = new proquestFTP($urlFTP);

        // Set session time out. Default is 90.
        $this->ftp->ftp_set_option(FTP_TIMEOUT_SEC, 150);

        // Pass login credentials to login method.
        // INFO: ftp_login() Returns true on success or false on failure. 
        //       If login fails, PHP will also throw a warning.
        if ( $this->ftp->ftp_login($userFTP, $passwordFTP) ) {
            $this->writeLog("FTP connection sucecssful.");
            return true;
        } else {
            // TODO: get ftp error message with set_error_handler().
            $errorMessage = "FTP connection failed.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            $this->processingFailure = true;
            throw new Exception($errorMessage);
        }
    }

    /**
     * Prepares Fedora datastreams for ingestion.
     *
     * @param $fedoraObj A Fedora connection object.
     * @param $datastreamObj A datastream object, usually a file.
     * @param $datastreamName The name of the datastream.
     * @param $etdShortName The name of the ETD file being processed.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    private function prepareIngestDatastream($fedoraObj, $datastreamObj, $datastreamName, $etdShortName) {
        if ( $this->debug === true ) {
            array_push($this->localFiles[$etdShortName]['DATASTREAMS_CREATED'], $datastreamName);
            $this->writeLog("[{$datastreamName}] DEBUG: Did not ingest datastream.");
            return true;
        }

        // Ingest datastream into Fedora object.
        try {
            $fedoraObj->ingestDatastream($datastreamObj);
        } catch (Exception $e) {
            $errorMessage = "{$datastreamName} datastream ingest failed: " . $e->getMessage();
            array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
            $this->writeLog("ERROR: {$errorMessage}");
            $this->writeLog("trace:\n" . $e->getTraceAsString());
            array_push($this->processingErrors, $errorMessage);
            $this->countFailedETDs++;
            throw new Exception($errorMessage);
        }

        array_push($this->localFiles[$etdShortName]['DATASTREAMS_CREATED'], $datastreamName);
        $this->writeLog("[{$datastreamName}] Ingested datastream.");
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

        $this->writeLog("BEGIN Moving processed ETDs into respective post-processing directories.");
        $this->writeLog("Currently in FTP directory: {$this->fetchdirFTP}");
        $this->writeLog(LOOP_DIVIDER);

        foreach ($this->localFiles as $etdShortName => $etdArray) {
            $this->currentProcessedETD = $etdShortName;
            $ingested = $this->localFiles[$etdShortName]["INGESTED"]; // boolean
            $zipFileName = $this->localFiles[$etdShortName]["ZIP_FILENAME"];
            $ftpPathForETD = $this->localFiles[$etdShortName]["FTP_PATH_FOR_ETD"];

            if ( $ingested === true ) {
                $moveFTPDir = $processdirFTP . $zipFileName;
            } else {
                $moveFTPDir = $faildirFTP . $zipFileName;
            }

            $this->writeLog("Was ETD successfully ingested?: " . ($ingested ? "true" : "false"));
            $this->writeLog("Now attempting to move:");
            $this->writeLog("   from: {$ftpPathForETD}");
            $this->writeLog("   into: {$moveFTPDir}");

            if ( $this->debug === true ) {
                $this->writeLog("DEBUG: Not moving ETD files on FTP.");
                $this->writeLog(LOOP_DIVIDER);
                $this->localFiles[$etdShortName]['FTP_POSTPROCESS_LOCATION'] = $moveFTPDir;
                continue;
            }

            // INFO: ftp_rename() returns true on success or false on failure.
            $ftpRes = $this->ftp->ftp_rename($ftpPathForETD, $moveFTPDir);
            
            // Check if there was an error moving the ETD file on the FTP server.
            if ( $ftpRes === false ) {
                $errorMessage = "Could not move ETD file to '{$moveFTPDir} FTP directory.";
                $this->writeLog("ERROR: {$errorMessage}");
                $this->writeLog(LOOP_DIVIDER);
                // Log this as a noncritical error and continue.
                array_push($this->localFiles[$etdShortName]['NONCRITICAL_ERRORS'], $errorMessage);
                return false;
            }
            $this->writeLog("Move was successful.");
            $this->writeLog(LOOP_DIVIDER);
            $this->localFiles[$etdShortName]['FTP_POSTPROCESS_LOCATION'] = $moveFTPDir;
        }

        $this->writeLog("END Moving processed ETDs into respective post-processing directories.");

        $this->currentProcessedETD = "";
        return true;
    }

    /**
     * Recursively delete a directory.
     * From: https://stackoverflow.com/a/18838141
     * 
     * @param string $dir The name of the directory to delete.
     * 
     * @return boolean The status of the rmdir() function.
     */
    private function recurseRmdir($dir) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            // is_dir() Returns true if the filename exists and is a directory, false otherwise.
            // is_link() Returns true if the filename exists and is a symbolic link, false otherwise.
            ( (is_dir("$dir/$file") === true) && (is_link("$dir/$file") === false) ) ? $this->recurseRmdir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * Recursively scan a directory.
     * From: https://stackoverflow.com/a/46697247
     * 
     * @param string $dir The name of the directory to scan.
     * 
     * @return array An array listing all the files found.
     */
    private function scanAllDir($dir) {
        $result = [];
        // INFO: scandir() Returns an array of filenames on success, or false on failure. 
        //       If directory is not a directory, then boolean false is returned, 
        //       and an error of level E_WARNING is generated.
        foreach(scandir($dir) as $filename) {
          if ( $filename[0] === '.' ) continue;
          $filePath = $dir . '/' . $filename;
          if ( is_dir($filePath) === true ) {
            $result[] = $filename;
            foreach ($this->scanAllDir($filePath) as $childFilename) {
              $result[] = $filename . '/' . $childFilename;
            }
          } else {
            $result[] = $filename;
          }
        }
        return $result;
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
    public function getFiles() {
        $fn = "getFiles";

        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("Fetching ETD files from FTP server.");

        // Look at specific directory on FTP server for ETD files. Ex: /path/to/files/
        $this->fetchdirFTP = $this->settings['ftp']['fetchdir'];
        if ( empty($this->fetchdirFTP) === true ) {
            $this->fetchdirFTP = "~/";
        }

        // Define local directory for file processing. Ex: /tmp/processed/
        $localdirFTP = $this->settings['ftp']['localdir'];
        if ( empty($localdirFTP) === true ) {
            $errorMessage = "Local working directory not set.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        // Change FTP directory if $fetchdirFTP is not empty (aka root directory).
        if ( $this->fetchdirFTP != "" ) {
            if ( $this->ftp->ftp_chdir($this->fetchdirFTP) ) {
                $this->writeLog("Changed to local FTP directory: {$this->fetchdirFTP}");
            } else {
                $errorMessage = "Cound not change FTP directory: {$this->fetchdirFTP}";
                $this->writeLog("ERROR: {$errorMessage}");
                array_push($this->processingErrors, $errorMessage);
                throw new Exception($errorMessage);
            }
        }

        // $this->writeLog("Currently in FTP directory: {$this->fetchdirFTP}");

        /**
         * Look for files that begin with a specific string.
         * In our specific case the file prefix is "etdadmin_upload_*".
         * Save results into $etdZipFiles array.
         */
        $file_regex = $this->settings['ftp']['file_regex'];
        $allFiles = $this->ftp->ftp_nlist($file_regex);

        // Only collect zip files.
        $etdZipFiles = [];
        foreach($allFiles as $file) {
            if ( str_contains($file, ".zip") === true ) {
                array_push($etdZipFiles, $file);
            }
        }

        $this->allFoundETDs = $etdZipFiles;
        $this->countTotalETDs = count($etdZipFiles);

        // Throw exception if there are no ETD files to process.
        if ( empty($etdZipFiles) === true ) {
            $errorMessage = "Did not find any ETD files on the FTP server.";
            $this->writeLog("WARNING: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        $this->writeLog("Found {$this->countTotalETDs} ETD file(s).");
        foreach ($etdZipFiles as $zipFileName) {
            $this->writeLog("   • {$zipFileName}");
        }
        $this->writeLog("Now parsing each ETD file.");

        /**
         * Loop through each match in $etdZipFiles.
         * There may be multiple matched files so process each individually.
         */
        $f = 0;
        foreach ($etdZipFiles as $zipFileName) {
            $f++;
            /**
             * Set the directory name for each ETD file.
             * This is based on the file name without any file extension.
             * Ex: etd_file_name_1234.zip -> /tmp/processing/etd_file_name_1234
             */

            // Get the regular file name without file extension.
            $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);
            $this->currentProcessedETD = $etdShortName;

            // Set the path of the local working directory. Ex: /tmp/processing/file_name_1234
            $etdWorkingDir = $localdirFTP . $etdShortName;

            $this->writeLog(LOOP_DIVIDER);
            $this->writeLog("BEGIN Gathering ETD file [{$f} of {$this->countTotalETDs}]");

            // Check to see if zipFileName is more than four chars. Continue if string fails.
            if ( strlen($zipFileName) <= 4 ) {
                $this->writeLog("WARNING File name only has " . strlen($zipFileName) . " characters. Moving to the next ETD." );
                $this->countTotalInvalidETDs++;
                array_push($this->allInvalidETDs, $zipFileName);
                continue;
            }
            $this->writeLog("Is file valid?... true.");

            // Increment number of valid ETDs.
            $this->countTotalValidETDs++;

            $this->localFiles[$etdShortName]['ETD_SHORTNAME'] = $etdShortName;
            $this->localFiles[$etdShortName]['WORKING_DIR'] = $etdWorkingDir;
            $this->localFiles[$etdShortName]['SUPPLEMENTS'] = [];
            $this->localFiles[$etdShortName]['HAS_SUPPLEMENTS'] = false;
            $this->localFiles[$etdShortName]['FILE_ETD'] = "";
            $this->localFiles[$etdShortName]['FILE_METADATA'] = "";
            $this->localFiles[$etdShortName]['ZIP_FILENAME'] = $zipFileName;
            $this->localFiles[$etdShortName]['ZIP_CONTENTS'] = [];
            $this->localFiles[$etdShortName]['FTP_PATH_FOR_ETD'] = "{$this->fetchdirFTP}{$zipFileName}";
            $this->localFiles[$etdShortName]['FTP_POSTPROCESS_LOCATION'] = "{$this->fetchdirFTP}{$zipFileName}";
            $this->localFiles[$etdShortName]['NONCRITICAL_ERRORS'] = [];
            $this->localFiles[$etdShortName]['CRITICAL_ERRORS'] = [];

            // Set status to 'processing'.
            $this->localFiles[$etdShortName]['STATUS'] = "unprocessed";

            $this->writeLog("Local working directory status:");
            $this->writeLog("   • Directory to create: {$etdWorkingDir}");

            // Create the local directory if it doesn't already exists.
            // INFO: file_exists() Returns true if the file or directory specified by filename exists; false otherwise.
            if ( file_exists($etdWorkingDir) === true ) {
                $this->writeLog("   • Directory already exists.");

                // INFO: $this->recurseRmdir() Returns a boolean success value.
                if ( $this->recurseRmdir($etdWorkingDir) === false ) {
                    // We couldn't clear out the directory.
                    $errorMessage = "Failed to remove local working directory: {$etdWorkingDir}. Moving to the next ETD.";
                    // $this->writeLog("ERROR: {$errorMessage}");
                    // array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                    $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                    continue;
                } else {
                    $this->writeLog("   • Existing directory was removed.");
                }
            }
            
            // INFO: mkdir() Returns true on success or false on failure.
            if ( mkdir($etdWorkingDir, 0755, true) === false ) {
                $errorMessage = "Failed to create local working directory: {$etdWorkingDir}. Moving to the next ETD.";
                // $this->writeLog("ERROR: {$errorMessage}");
                // array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            } else {
                $this->writeLog("   • Directory was created.");
            }
            $etdZipFileFullPath = $etdWorkingDir . "/" . $zipFileName;

            // HACK: give loop some time to create directory.
            sleep(2);

            /**
             * Gets the file from the FTP server.
             * Saves it locally to local working directory. Ex: /tmp/processing/file_name_1234
             * File is saved locally as a binary file.
             */
            // INFO: ftp_get() Returns true on success or false on failure.
            if ( $this->ftp->ftp_get($etdZipFileFullPath, $zipFileName, FTP_BINARY) === true ) {
                $this->writeLog("Fetched ETD zip file from FTP server.");
            } else {
                $errorMessage = "Failed to fetch file from FTP server: {$etdZipFileFullPath}. Moving to the next ETD.";
                // $this->writeLog("ERROR: {$errorMessage}");
                // array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }

            // Store location of local directory if it hasn't been stored yet.
            if( isset($this->localFiles[$etdShortName]) === true ) {
                $this->localFiles[$etdShortName];
            }

            $zip = new ZipArchive;

            // Open and extract zip file to local directory.
            // INFO: zip_open() returns either false or the number of error if filename does not exist 
            //       or in case of other error.
            $res = $zip->open($etdZipFileFullPath);
            if ($res === TRUE) {
                $zip->extractTo($etdWorkingDir);
                $zip->close();

                $this->writeLog("Extracting ETD zip file to local working directory.");
            } else {
                $errorMessage = "Failed to extract ETD zip file: " . $res;
                // $this->writeLog("ERROR: {$errorMessage}");
                // array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }

            // There are files we want to ignore when running scandir().
            $filesToIgnore = [".", ".." , $this->localFiles[$etdShortName]['ZIP_FILENAME']];

            // INFO: array_diff() Returns an array containing all the entries from array that  
            //       are not present in any of the other arrays.
            $expandedETDFiles = array_diff($this->scanAllDir($etdWorkingDir), $filesToIgnore);

            if ( count($expandedETDFiles) === 0) {
                // There are no files in this expanded zip file.
                $errorMessage = "There are no files in this expanded zip file.";
                //$this->writeLog("ERROR: {$errorMessage}");
                //array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }

            $this->writeLog("There are " . count($expandedETDFiles) . " files found in this working directory:");

            $z = 0;
            foreach($expandedETDFiles as $etdFileName) {
                $z++;
                $this->writeLog("  [{$z}] File name: {$etdFileName}");
                array_push($this->localFiles[$etdShortName]['ZIP_CONTENTS'], $etdFileName);
            
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
                if ( preg_match('/0016/', $etdFileName) ) {
                    // INFO: substr() Returns the extracted part of string, or an empty string.
                    $fileExtension = strtolower(substr($etdFileName,strlen($etdFileName)-3));

                    // Check if this is a PDF file.
                    if ( ($fileExtension === 'pdf') && (empty($this->localFiles[$etdShortName]['FILE_ETD']) === true) ) {
                        $this->localFiles[$etdShortName]['FILE_ETD'] = $etdFileName;
                        $this->writeLog("      File type: PDF");
                        continue;
                    }

                    // Check if this is an XML file.
                    if ( ($fileExtension === 'xml') && (empty($this->localFiles[$etdShortName]['FILE_METADATA']) === true) ) {
                        $this->localFiles[$etdShortName]['FILE_METADATA'] = $etdFileName;
                        $this->writeLog("      File type: XML");
                        continue;
                    }

                    /**
                     * Supplementary files - could be permissions or data.
                     * Metadata will contain boolean key for permission in DISS_file_descr element.
                     * [0] element should always be folder.
                     */
                    // echo "Is this a directory? {$etdWorkingDir}/{$etdFileName}: " . is_dir($etdWorkingDir . "/" . $etdFileName);
                    try {
                        $checkIfDir = is_dir($etdWorkingDir . "/" . $etdFileName);
                    } catch (Exception $e) {
                        $errorMessage = "Couldn't check if file is a directory: " . $e->getMessage();
                        $this->writeLog("ERROR: {$errorMessage}");
                        $this->writeLog("trace:\n" . $e->getTraceAsString());
                        continue;
                    }

                    if ( $checkIfDir === true ) {
                        $this->writeLog("      This is a directory. Next parsed file may be a supplemental file.");
                        // array_push($this->localFiles[$etdShortName]['ZIP_CONTENTS_DIRS'], $etdFileName);
                        continue;
                    } else {
                        array_push($this->localFiles[$etdShortName]['SUPPLEMENTS'], $etdFileName);
                        $this->localFiles[$etdShortName]['HAS_SUPPLEMENTS'] = true;
                        $this->countSupplementalETDs++;
                        array_push($this->allSupplementalETDs, $zipFileName);
                        $this->writeLog("      This is a supplementary file.");
                    }
                } else {
                    // If file doesn't contain /0016/ then we'll log it as a noncritical error and then ignore it. 
                    // Later, we'll check that an expected MOD and PDF file were found in this zip file.
                    $errorMessage = "Located a file that was not named properly and was ignored: {$etdFileName}";
                    $this->writeLog("      WARNING: {$errorMessage}");
                    array_push($this->localFiles[$etdShortName]['NONCRITICAL_ERRORS'], $errorMessage);
                }
            }

            if ( $this->localFiles[$etdShortName]['HAS_SUPPLEMENTS'] === true ){
                // At this point we can leave this function if the ETD has supplemental files.
                $this->writeLog("This ETD has supplementary files. No further processing is required. Moving to the next ETD.");
                $this->writeLog("END Gathering ETD file [{$f} of {$this->countTotalETDs}]");
                $this->localFiles[$etdShortName]['STATUS'] = "skipped";
                continue;
            } else {
                array_push($this->allRegularETDs, $zipFileName);
            }

            /**
             * Check that both:
             *  - $this->localFiles[$etdShortName]['FILE_ETD']
             *  - $this->localFiles[$etdShortName]['FILE_METADATA']
             * are defined and are nonempty strings.
             */
            $this->writeLog("Checking that PDF and XML files were found in this zip file:");
            if ( empty($this->localFiles[$etdShortName]['FILE_ETD']) === true ) {
                $errorMessage = "   The ETD PDF file was not found or set.";
                // $this->writeLog("ERROR: {$errorMessage}");
                // array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                // $this->localFiles[$etdShortName]['STATUS'] = "failure";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }
            $this->writeLog("   ✓ The ETD PDF file was found.");

            if ( empty($this->localFiles[$etdShortName]['FILE_METADATA']) === true ) {
                $errorMessage = "   The ETD XML file was not found or set.";
                // $this->writeLog("ERROR: {$errorMessage}");
                // array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
                // $this->localFiles[$etdShortName]['STATUS'] = "failure";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }
            $this->writeLog("   ✓ The ETD XML file was found.");

            $this->writeLog("END Gathering ETD file [{$f} of {$this->countTotalValidETDs}]");
            $this->localFiles[$etdShortName]['STATUS'] = "success";
        }
        $this->currentProcessedETD = "";

        // Completed fetching all ETD zip files.
        $this->writeLog(LOOP_DIVIDER);
        $this->writeLog("Completed fetching all ETD zip files from FTP server.");
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
    public function processFiles() {
        $fn = "processFiles";

        // Return false if there are no ETD files to process.
        if ( empty($this->localFiles) === true ) {
            $errorMessage = "Did not find any ETD files to process.";
            $this->writeLog($errorMessage);
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("Now processing {$this->countTotalValidETDs} ETD file(s).");

        /**
         * Load Proquest MODS XSLT stylesheet.
         * Ex: /path/to/proquest/crosswalk/Proquest_MODS.xsl
         */
        $xslt = new xsltProcessor;
        $proquestxslt = new DOMDocument();
        $proquestxslt->load($this->settings['xslt']['xslt']);
        // INFO: XSLTProcessor::importStylesheet() Returns true on success or false on failure.
        if ( $xslt->importStyleSheet($proquestxslt)  === true) {
            $this->writeLog("Loaded MODS XSLT stylesheet.");
        } else {
            $errorMessage = "Failed to load MODS XSLT stylesheet.";
            $this->writeLog("ERROR: {$errorMessage}");
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
        if ( $label->importStyleSheet($labelxslt) === true ) {
            $this->writeLog("Loaded Fedora Label XSLT stylesheet.");
        } else {
            $errorMessage = "Failed to load Fedora Label XSLT stylesheet.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        /**
         * Given the array of ETD local files, generate additional metadata.
         */
        $s = 0;
        foreach ($this->localFiles as $etdShortName => $etdArray) {
            $s++;
            $zipFileName = $this->localFiles[$etdShortName]["ZIP_FILENAME"];
            $etdShortName = $this->localFiles[$etdShortName]['ETD_SHORTNAME'];
            // $this->localFiles[$etdShortName]["FOO"] = "BAR";
            $this->currentProcessedETD = $etdShortName;

            $this->writeLog(LOOP_DIVIDER);
            $this->writeLog("BEGIN Processing ETD file [{$s} of {$this->countTotalETDs}]");

            // No need to process ETDs that have supplemental files.
            if ( $this->localFiles[$etdShortName]["HAS_SUPPLEMENTS"] === true ) {
                $this->writeLog("SKIP Processing ETD since it contains supplemental files.");
                $this->writeLog("END Processing ETD file [{$s} of {$this->countTotalValidETDs}]");
                continue;
            }

            // Create XPath object from the ETD XML file.
            $metadata = new DOMDocument();
            $metadata->load($this->localFiles[$etdShortName]['WORKING_DIR'] . '//' . $this->localFiles[$etdShortName]['FILE_METADATA']);
            $xpath = new DOMXpath($metadata);

            /**
             * Get OA permission.
             * This looks for the existance of an "oa" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_acceptance/text()
             */
            $this->writeLog("Searching for OA agreement...");

            $openaccess = 0;
            $openaccess_available = false;
            // INFO: DOMXPath::query() Returns a DOMNodeList containing all nodes matching 
            //       the given XPath expression. Any expression which does not return nodes 
            //       will return an empty DOMNodeList. If the expression is malformed or the 
            //       contextNode is invalid, DOMXPath::query() returns false.
            // INFO: DOMNode::C14N() Returns canonicalized nodes as a string or false on failure.
            $oaElements = $xpath->query($this->settings['xslt']['oa']);
            // Check if an open access node was found. 
            // Else, check if that node has the value '0'.
            // Else, assume that node has the value '1'.
            if ( $oaElements->length == 0 ) {
                $this->writeLog("No OA agreement found.");
            } elseif ( $oaElements->item(0)->C14N() === '0' ) {
                $this->writeLog("No OA agreement found.");
            } else {
                // This value is '1' if available for Open Access.
                $openaccess = $oaElements->item(0)->C14N();
                $openaccess_available = true;
                $this->writeLog("Found an OA agreement.");
            }

            $this->localFiles[$etdShortName]['OA'] = $openaccess;
            $this->localFiles[$etdShortName]['OA_AVAILABLE'] = $openaccess_available;

            /**
             * Get embargo permission/dates.
             * This looks for the existance of an "embargo" node in the XPath object.
             * Ex: /DISS_submission/DISS_repository/DISS_delayed_release/text()
             */
            $this->writeLog("Searching for embargo information...");

            $embargo = 0;
            $has_embargo = false;
            $this->localFiles[$etdShortName]['HAS_EMBARGO'] = false;
            $emElements = $xpath->query($this->settings['xslt']['embargo']);
            if ( $emElements->item(0) ) {
                $has_embargo = true;
                // Convert date string into proper PHP date object format.
                $embargo = $emElements->item(0)->C14N();
                $this->writeLog("Unformatted embargo date: {$embargo}");
                $embargo = str_replace(" ","T",$embargo);
                $embargo = $embargo . "Z";
                $this->writeLog("Using embargo date of: {$embargo}");
            } else {
                $this->writeLog("There is no embargo on this record.");
            }

            /**
             * Check to see if there is no OA policy, and there is no embargo.
             * If so, set the embargo permission/date to "indefinite".
             */
            if ( $openaccess_available === $has_embargo ) {
                $embargo = 'indefinite';
                $has_embargo = true;
                $this->writeLog("Changing embargo date to 'indefinite'");
                $this->writeLog("Using embargo date of: {$embargo}");
            }

            $this->localFiles[$etdShortName]['HAS_EMBARGO'] = $has_embargo;
            $this->localFiles[$etdShortName]['EMBARGO'] = $embargo;
            $this->localFiles[$etdShortName]['EMBARGO_DATE'] = $embargo;

            /**
             * Fetch next PID from Fedora.
             * Prepend PID with locally defined Fedora namespace.
             * Ex: "bc-ir:" for BC.
             */
            // DEBUG: generate random PID.
            if ( $this->debug === true ) {
                $pid = "bc-ir:" . rand(50000,100000) + 9000000;
                $this->writeLog("DEBUG: Generating random PID for testing (NOT fetched from Fedora): {$pid}");
            } else {
                $pid = $this->api_m->getNextPid($this->settings['fedora']['namespace'], 1);
                $this->writeLog("Fetched new PID from Fedora: {$pid}");
            }

            $this->localFiles[$etdShortName]['PID'] = $pid;

            $this->writeLog("Fedora PID value for this ETD: {$pid}");

            /**
             * Insert the PID value into the Proquest MODS XSLT stylesheet.
             * The "handle" value should be set the PID.
             */
            // INFO: XSLTProcessor::setParameter() Returns true on success or false on failure.
            $res = $xslt->setParameter('mods', 'handle', $pid);
            if ( $res === false ) {
                $errorMessage = "Could not update XSLT stylesheet with PID value.";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }
            $this->writeLog("Update XSLT stylesheet with PID value.");

            /**
             * Generate MODS file.
             * This file is generated by applying the Proquest MODS XSLT stylesheet to the ETD XML file.
             * Additional metadata will be generated from the MODS file.
             */
            // INFO: XSLTProcessor::transformToDoc() The resulting document or false on error.
            $mods = $xslt->transformToDoc($metadata);
            if ( $mods === false ) {
                $errorMessage = "Could not transform ETD MODS XML file.";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }
            $this->writeLog("Transformed ETD MODS XML file with XSLT stylesheet.");

            /**
             * Generate ETD title/Fedora Label.
             * The title is generated by applying the Fedora Label XSLT stylesheet to the above generated MODS file.
             * This uses mods:titleInfo.
             */
            // INFO: XSLTProcessor::transformToXml() The result of the transformation as a string or false on error.
            $fedoraLabel = $label->transformToXml($mods);
            if ( $fedoraLabel === false ) {
                $errorMessage = "Could not generate ETD title using Fedora Label XSLT stylesheet.";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }
            $this->localFiles[$etdShortName]['LABEL'] = $fedoraLabel;

            $this->writeLog("Generated ETD title: " . $fedoraLabel);

            /**
             * Generate ETD author.
             * This looks for the existance of an "author" node in the MODS XPath object.
             * Ex: /mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()
             */
            $xpathAuthor = new DOMXpath($mods);
            $authorElements = $xpathAuthor->query($this->settings['xslt']['creator']);
            $author = $authorElements->item(0)->C14N();
            $this->writeLog("Generated ETD author: [{$author}]");

            /**
             * Normalize the ETD author string. This forms the internal file name convention.
             * Ex: Jane Anne O'Foo => Jane-Anne-OFoo
             */
            #$normalizedAuthor = str_replace(array(" ",",","'",".","&apos;",'"',"&quot;"), array("-","","","","","",""), $author);
            $normalizedAuthor = $this->normalizeString($author);
            $this->localFiles[$etdShortName]['AUTHOR'] = $author;
            $this->localFiles[$etdShortName]['AUTHOR_NORMALIZED'] = $normalizedAuthor;

            $this->writeLog("Generated normalized ETD author: [{$normalizedAuthor}]");
            $this->writeLog("Now using the normalized ETD author name to update ETD PDF and MODS files.");

            // Create placeholder full-text text file using normalized author's name.
            $this->localFiles[$etdShortName]['FULLTEXT'] = $normalizedAuthor . ".txt";
            //$this->writeLog("Generated placeholder full text file name: " . $this->localFiles[$etdShortName]['FULLTEXT']);

            // Rename Proquest PDF using normalized author's name.
            // INFO: rename() Returns true on success or false on failure.
            $res = rename($this->localFiles[$etdShortName]['WORKING_DIR'] . "/". $this->localFiles[$etdShortName]['FILE_ETD'] , $this->localFiles[$etdShortName]['WORKING_DIR'] . "/" . $normalizedAuthor . ".pdf");
            if ( $res === false ) {
                $errorMessage = "Could not rename ETD PDF file.";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }

            // Update local file path for ETD PDF file.
            $normalizedAuthorPDFName = $normalizedAuthor . ".pdf";
            $this->writeLog("Renamed ETD PDF file from {$this->localFiles[$etdShortName]['FILE_ETD']} to {$normalizedAuthorPDFName}");
            $this->localFiles[$etdShortName]['FILE_ETD'] = $normalizedAuthorPDFName;

            // Save MODS using normalized author's name.
            // INFO: DOMDocument::save() Returns the number of bytes written or false if an error occurred.
            $res = $mods->save($this->localFiles[$etdShortName]['WORKING_DIR'] . "/" . $normalizedAuthor . ".xml");
            if ( $res === false ) {
                $errorMessage = "Could not create new ETD MODS file.";
                $this->preprocessingTaskFailed($errorMessage, $etdShortName);
                continue;
            }

            // Update local file path for MODS file.
            $this->localFiles[$etdShortName]['MODS'] = $normalizedAuthor . ".xml";
            $this->writeLog("Created new ETD MODS file {$this->localFiles[$etdShortName]['MODS']}");


            /**
             * Check for supplemental files.
             * This looks for the existance of an "DISS_attachment" node in the ETD XML XPath object.
             * Ex: /DISS_submission/DISS_content/DISS_attachment
             */
            // TODO: remove duplicative logic to find supplemental files.
            // $suppxpath = new DOMXpath($metadata);
            // $suElements = $suppxpath->query($this->settings['xslt']['supplement']);

            $this->localFiles[$etdShortName]['STATUS'] = "processed";
            $this->writeLog("END Processing ETD [#{$s} of {$this->countTotalETDs}]");
        }
        $this->currentProcessedETD = "";

        // Completed processing all ETD files.
        $this->writeLog(LOOP_DIVIDER);
        $this->writeLog("Completed processing all ETD files.");
        return true;
    }

    /**
     * Initializes a connection to a Fedora file repository server.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if Fedora connection fails.
     */
    public function initFedoraConnection() {
        $fn = "initFedoraConnection";
        $url = $this->settings['fedora']['url'];
        $user = $this->settings['fedora']['username'];
        $pass = $this->settings['fedora']['password'];

        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("Connecting to Fedora instance at {$url}");

        // Check all values exist.
        if ( (empty($url) === true) || (empty($user) === true) || (empty($pass) === true) ) {
            $errorMessage = "Can't connect to Fedora instance. One or more Fedora settings are not set.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            $this->processingFailure = true;
            throw new Exception($errorMessage);
        }

        // Make Fedora repository connection.
        // Tuque library exceptions defined here:
        // https://github.com/Islandora/tuque/blob/7.x-1.7/RepositoryException.php
        try {
            $this->connection = new RepositoryConnection($url, $user, $pass);
            $this->api = new FedoraApi($this->connection);
            $this->repository = new FedoraRepository($this->api, new simpleCache());
            $this->writeLog("Connected to the Fedora repository.");
        } catch(Exception $e) { // RepositoryException
            $errorMessage = "Can't connect to Fedora instance: " . $e->getMessage();
            $this->writeLog("ERROR: {$errorMessage}");
            $this->writeLog("trace:\n" . $e->getTraceAsString());
            array_push($this->processingErrors, $errorMessage);
            $this->processingFailure = true;
            throw new Exception($errorMessage);
        }

        // Create a Fedora Management API object shortcut.
        $this->api_m = $this->repository->api->m;
        return true;
    }

    /**
     * Generate a simple status update message
     */
    public function statusCheck(){
        $fn = "statusCheck";
        $this->writeLog("Generating status message for email message.");
        $message = "\n";

        // First, find if there are processing errors
        $countProcessingErrors = count($this->processingErrors);
        if ( $countProcessingErrors > 0 ) {
            $message .= "This script failed to run because of the following issue(s):\n";
            
            foreach ($this->processingErrors as $processingError) {
                $message .= "  • {$processingError}\n";
            }
        } else {
            $i = 0;

            $countETDs = count($this->localFiles);
            $message .= "There were {$countETDs} ETD(s) processed.\n"; 
            foreach ($this->localFiles as $etdShortName => $etdArray) {
                $i++;
                $criticalErrorsCount = count($this->localFiles[$etdShortName]["CRITICAL_ERRORS"]);
                $noncriticalErrorsCount = count($this->localFiles[$etdShortName]["NONCRITICAL_ERRORS"]);
                $message .= "\n  [{$i}] Zip filename:      {$this->localFiles[$etdShortName]['ZIP_FILENAME']}\n";
                $message .= "      Status:            {$this->localFiles[$etdShortName]['STATUS']}\n";
                $message .= "      Has supplements:   " . ($this->localFiles[$etdShortName]['HAS_SUPPLEMENTS'] ? "true" : "false") . "\n";
                
                // If this ETD has supplements then display message and continue to next ETD.
                if ( $this->localFiles[$etdShortName]['HAS_SUPPLEMENTS'] === true ) {
                    $message .= "      WARNING: This ETD contains supplemental files and was not processed.\n";
                    $message .= "               Please manually process the ETD zip file, which can be found here on the FTP server:\n";
                    $message .= "               {$this->localFiles[$etdShortName]['FTP_POSTPROCESS_LOCATION']}\n";
                    continue;
                }

                // Display critical errors and continue to next ETD.
                if ( $criticalErrorsCount > 0 ) {
                    $message .= "      WARNING: This ETD failed to ingest because of the following reasons(s):\n";
                    foreach ($this->localFiles[$etdShortName]["CRITICAL_ERRORS"] as $criticalError) {
                        $message .= "       • {$criticalError}\n";
                    }
                    continue;
                }

                $message .= "      Has OA agreement:  " . ($this->localFiles[$etdShortName]['OA_AVAILABLE'] ? "true" : "false") . "\n";
                $message .= "      Has embargo:       " . ($this->localFiles[$etdShortName]['HAS_EMBARGO'] ? "true" : "false") . "\n";
                if ($this->localFiles[$etdShortName]['HAS_EMBARGO']) {
                    $message .= "      Embargo date:      {$this->localFiles[$etdShortName]['EMBARGO_DATE']}\n";
                }
                $message .= "      PID:               {$this->localFiles[$etdShortName]['PID']}\n";
                $message .= "      URL:               {$this->localFiles[$etdShortName]['RECORD_URL']}\n";
                $message .= "      Author:            {$this->localFiles[$etdShortName]['AUTHOR']}\n";
                $message .= "      Title:             {$this->localFiles[$etdShortName]['LABEL']}\n";

                // Display noncritical errors.
                if ( $noncriticalErrorsCount > 0 ) {
                    $message .= "      WARNING: This ETD was ingested but logged the following noncritical issues:\n";
                    foreach ($this->localFiles[$etdShortName]["NONCRITICAL_ERRORS"] as $noncriticalError) {
                        $message .= "       • {$noncriticalError}\n";
                    }
                }
            }
        }

        $message .= "\nThe full log file can be found at:\n{$this->logFileLocation}.\n";

        return $message;
    }

    /**
     * Parse script results and compose email body.
     */
    public function postProcess() {
        $fn = "postProcess";

        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("BEGIN Running post-process steps.");

        // Move files in FTP server only when applicable.
        // INFO processingFailure() Returns a boolean.
        if ( $this->processingFailure === false ) {
            $ret = $this->moveFTPFiles();
        }

        // Get overall status.
        $message = $this->statusCheck();

        // Send email.
        $ret = $this->sendEmail($message);

        $this->writeLog("END Running post-process steps.");
        $this->writeLog(SECTION_DIVIDER);
        return true;
    }

    /**
     * Process a failed datastream ingest.
     * 
     * @param string $errorMessage the error message to display.
     * @param string $datastreamName the name of the datastream.
     * @param string $etdShortName the name of the ETD file.
     */
    private function datastreamIngestFailed($errorMessage, $datastreamName, $etdShortName) {
        array_push($this->allFailedETDs, $etdShortName);
        array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
        $this->writeLog("[{$datastreamName}] ERROR: $errorMessage");
        $this->localFiles[$etdShortName]["STATUS"] = "failed";
    }

    /**
     * Process a failed file pre-processing task.
     * 
     * @param string $errorMessage the error message to display.
     * @param string $functionName the name of the calling function.
     * @param string $etdShortName the name of the ETD file.
     */
    private function preprocessingTaskFailed($errorMessage, $etdShortName) {
        array_push($this->allFailedETDs, $etdShortName);
        array_push($this->localFiles[$etdShortName]['CRITICAL_ERRORS'], $errorMessage);
        $this->writeLog("ERROR: {$errorMessage}");
        $this->localFiles[$etdShortName]['STATUS'] = "failed";
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
    public function ingest() {
        $fn = "ingest";

        // Check to see if there are any ETD files to process.
        if ( empty($this->localFiles) === true ) {
            $errorMessage = "No ETD files to ingest.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new Exception($errorMessage);
        }

        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("Now Ingesting {$this->countTotalETDs} ETD file(s).");

        $fop_config = $this->settings['packages']['fop_config'];
        $executable_fop = $this->settings['packages']['fop'];
        $executable_convert = $this->settings['packages']['convert'];
        $executable_pdftk = $this->settings['packages']['pdftk'];
        $executable_pdftotext = $this->settings['packages']['pdftotext'];

        // Go through each ETD local file bundle.
        $i = 0;
        foreach ($this->localFiles as $etdShortName => $etdObject) {
            $i++;

            $workingDir = $this->localFiles[$etdShortName]['WORKING_DIR'];
            $this->localFiles[$etdShortName]['DATASTREAMS_CREATED'] = [];
            $this->localFiles[$etdShortName]['INGESTED'] = false;
            
            $this->writeLog(LOOP_DIVIDER);
            $this->currentProcessedETD = $etdShortName;
            $this->writeLog("BEGIN Ingesting ETD file [{$i} of {$this->countTotalETDs}]");

            // No need to process ETDs that have supplemental files.
            if ( $this->localFiles[$etdShortName]["HAS_SUPPLEMENTS"] === true ) {
                $this->writeLog("SKIP Ingesting ETD since it contains supplemental files.");
                $this->writeLog("END Ingesting ETD file [{$i} of {$this->countTotalETDs}]");
                continue;
            }

            $fullfnameFTP = $this->localFiles[$etdShortName]["FTP_PATH_FOR_ETD"];
            $this->writeLog("The full path of the ETD file on the FTP server is: {$fullfnameFTP}");

            // Instantiated a Fedora object and use the generated PID as its ID.
            // TODO: not sure this function throws an exception
            //       https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php
            try {
                $fedoraObj = $this->repository->constructObject($this->localFiles[$etdShortName]['PID']);
                $this->writeLog("Instantiated a Fedora object with PID: {$this->localFiles[$etdShortName]['PID']}");
            } catch (Exception $e) {
                $errorMessage = "Could not instanciate a Fedora object with PID '" . $this->localFiles[$etdShortName]['PID'] . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Assign the Fedora object label the ETD name/label
            $fedoraObj->label = $this->localFiles[$etdShortName]['LABEL'];
            $this->writeLog("Assigned a title to Fedora object: {$this->localFiles[$etdShortName]['LABEL']}");

            // All Fedora objects are owned by the same generic account
            $fedoraObj->owner = 'fedoraAdmin';

            $this->writeLog("Now generating Fedora datastreams.");


            /**
             * Generate RELS-EXT (XACML) datastream.
             *
             *
             */
            $dsid = "RELS-EXT";
            $this->writeLog("[{$dsid}] Generating (XACML) datastream.");

            // Set the default Parent and Collection policies for the Fedora object.
            try {
                $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID);
                $collectionName = GRADUATE_THESES;
            } catch (Exception $e) { // RepositoryException
                $errorMessage = "Could not fetch Fedora object '" . ISLANDORA_BC_ROOT_PID . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Update the Parent and Collection policies if this ETD is embargoed.
            if (isset($this->localFiles[$etdShortName]['EMBARGO'])) {
                $collectionName = GRADUATE_THESES_RESTRICTED;
                try {
                    $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID_EMBARGO);
                    $this->writeLog("[{$dsid}] Adding to Graduate Theses (Restricted) collection.");
                } catch (Exception $e) { // RepositoryException
                    $errorMessage = "Could not fetch Fedora object '" . ISLANDORA_BC_ROOT_PID_EMBARGO . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                    $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                    continue;
                }
            } else {
                $this->writeLog("[{$dsid}] Adding to Graduate Theses collection.");
            }

            // Update the Fedora object's relationship policies
            $fedoraObj->models = array('bc-ir:graduateETDCModel');
            $fedoraObj->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $collectionName);

            // Set various other Fedora object settings.
            $fedoraObj->checksumType = 'SHA-256';
            $fedoraObj->state = 'I';

            // Get Parent XACML policy.
            $policyObj = $parentObject->getDatastream(ISLANDORA_BC_XACML_POLICY);
            $this->writeLog("[{$dsid}] Fetching Islandora XACML datastream.");
            $this->writeLog("[{$dsid}] Deferring RELS-EXT (XACML) datastream ingestion until other datastreams are generated.");


            /**
             * Build MODS Datastream.
             *
             *
             */
            $dsid = 'MODS';
            $this->writeLog("[{$dsid}] Generating datastream.");

            // Build Fedora object MODS datastream.
            $datastream = $fedoraObj->constructDatastream($dsid, 'X');

            // Set various MODS datastream values.
            $datastream->label = 'MODS Record';
            // OLD: $datastream->label = $this->localFiles[$etdShortName]['LABEL'];
            $datastream->mimeType = 'application/xml';

            // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/author_name.XML
            $datastream->setContentFromFile($workingDir . "//" . $this->localFiles[$etdShortName]['MODS']);
            $this->writeLog("[{$dsid}] Selecting file for this datastream:");
            $this->writeLog("[{$dsid}]   {$this->localFiles[$etdShortName]['MODS']}");

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Generating datastream.");

            // Build Fedora object ARCHIVE MODS datastream from original Proquest XML.
            $datastream = $fedoraObj->constructDatastream($dsid, 'X');

            // Assign datastream label as original Proquest XML file name without file extension. Ex: etd_original_name
            $datastream->label = substr($this->localFiles[$etdShortName]['FILE_METADATA'], 0, strlen($this->localFiles[$etdShortName]['FILE_METADATA'])-4);
            //$this->writeLog("Using datastream label: " . $datastream->label);

            // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/etd_original_name.XML
            $datastream->setContentFromFile($workingDir . "//" . $this->localFiles[$etdShortName]['FILE_METADATA']);
            $this->writeLog("[{$dsid}] Selecting file for this datastream:");
            $this->writeLog("[{$dsid}]    {$this->localFiles[$etdShortName]['FILE_METADATA']}");

            // Set various ARCHIVE MODS datastream values.
            $datastream->mimeType = 'application/xml';
            $datastream->checksumType = 'SHA-256';
            $datastream->state = 'I';

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Generating datastream.");

            // Default Control Group is M.
            // Build Fedora object ARCHIVE PDF datastream from original Proquest PDF.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // OLD: $datastream->label = $this->localFiles[$etdShortName]['LABEL'];
            $datastream->label = 'ARCHIVE-PDF Datastream';

            // Set various ARCHIVE-PDF datastream values.
            $datastream->mimeType = 'application/pdf';
            $datastream->checksumType = 'SHA-256';
            $datastream->state = 'I';

            // Set datastream content to be ARCHIVE-PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $datastream->setContentFromFile($workingDir . "//" . $this->localFiles[$etdShortName]['FILE_ETD']);
            $this->writeLog("[{$dsid}] Selecting file for this datastream:");
            $this->writeLog("[{$dsid}]   {$this->localFiles[$etdShortName]['FILE_ETD']}");

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Generating datastream.");
            $this->writeLog("[{$dsid}] First, generate PDF splash page.");

            // Source file is the original Proquest XML file.
            $source = $workingDir . "/" . $this->localFiles[$etdShortName]['MODS'];

            // Assign PDF splash document to ETD file's directory.
            $splashtemp = $workingDir . "/splash.pdf";

            // Use the custom XSLT splash stylesheet to build the PDF splash document.
            $splashxslt = $this->settings['xslt']['splash'];

            // Use FOP (Formatting Objects Processor) to build PDF splash page.
            // Execute 'fop' command and check return code.
            $command = "$executable_fop -c $fop_config -xml $source -xsl $splashxslt -pdf $splashtemp";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'fop' command to build PDF splash page.");
            // FOP returns 0 on success.
    		if ( $return == false ) {
                $this->writeLog("[{$dsid}] Splash page created successfully.");
    		} else {
                $errorMessage = "PDF splash page creation failed. ". $return;
                $this->writeLog("[{$dsid}] ERROR: {$$errorMessage}");
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
    		    continue;
    		}

            // Update ETD file's object to store splash page's file location and name.
            $this->localFiles[$etdShortName]['SPLASH'] = 'splash.pdf';
            array_push($this->localFiles[$etdShortName]['DATASTREAMS_CREATED'], "SPLASH");

            /**
             * Build concatted PDF document.
             *
             * Load splash page PDF to core PDF if under embargo.
             * TODO: find out when/how this happens
             */
            $this->writeLog("[{$dsid}] Next, generate concatenated PDF document.");

            // Assign concatenated PDF document to ETD file's directory.
            $concattemp = $workingDir . "/concatted.pdf";

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $pdf = $workingDir . "//" . $this->localFiles[$etdShortName]['FILE_ETD'];

            /*
            // Temporarily deactivating the use of pdftk -- binary is no longer supported in RHEL 7

            // Use pdftk (PDF Toolkit) to edit PDF document.
            // Execute 'pdftk' command and check return code.
            $command = "$executable_pdftk $splashtemp $pdf cat output $concattemp";
            exec($command, $output, $return);
            $this->writeLog("Running 'pdftk' command to build concatenated PDF document.");

            if (!$return) {
                $this->writeLog("Concatenated PDF document created successfully.");
            } else {
                $this->writeLog("ERROR: Concatenated PDF document creation failed! " . $return);
                $this->ingestHandlerPostProcess(false, $etdShortName, $this->etd);
                continue;
            }
            */

            // Temporarily copying over the $pdf file as the $concattemp version since pdftk is not supported on RHEL7
            $this->writeLog("[{$dsid}] WARNING: A splashpage will not be appended to the ingested PDF file. Instead, a clone of the original PDF will be used.");

            // INFO: copy() Returns true on success or false on failure.
            if ( copy($pdf,$concattemp) === false ) {
                $errorMessage = "Could not generate a concatenated PDF document.";
                $this->writeLog("[{$dsid}] ERROR: {$errorMessage}");
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            } else {
                $this->writeLog("[{$dsid}] PDF document cloned successfully.");
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
            $this->writeLog("[{$dsid}] Selecting file for datastream:");
            $this->writeLog("[{$dsid}]    {$concattemp}");

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Generating datastream.");

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $source = $workingDir . "/" . $this->localFiles[$etdShortName]['FILE_ETD'];

            // Assign FULL_TEXT document to ETD file's directory.
            $fttemp = $workingDir . "/fulltext.txt";

            // Use pdftotext (PDF to Text) to generate FULL_TEXT document.
            // Execute 'pdftotext' command and check return code.
            $command = "$executable_pdftotext $source $fttemp";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'pdftotext' command.");
            // pdftotext returns 0 on success.
            if ( $return == false ) {
                $this->writeLog("[{$dsid}] datastream generated successfully.");
            } else {
                $errorMessage = "FULL_TEXT document creation failed. " . $return;
                $this->writeLog("[{$dsid}] ERROR: {$errorMessage}");
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Build Fedora object FULL_TEXT datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various FULL_TEXT datastream values.
            $datastream->label = 'FULL_TEXT';
            $datastream->mimeType = 'text/plain';

            // Read in the full-text document that was just generated.
            // INFO: file_get_contents() The function returns the read data or false on failure.
            $fulltext = file_get_contents($fttemp);

            // Check if file read failed.
            if ( $fulltext === false ) {
                $errorMessage = "Could not read in file: ". $fttemp;
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Strip out junky characters that mess up SOLR.
            $replacement = '';
            // INFO: preg_replace() Returns an array if the subject parameter is an array, or a string otherwise.
            $sanitized = preg_replace('/[\x00-\x1f]/', $replacement, $fulltext);

            // In the slim chance preg_replace returns an empty string.
            if ( $sanitized === '' ) {
                $errorMessage = "preg_replace failed to return valid sanitized FULL_TEXT string. String has length of 0.";
                $this->writeLog("[{$dsid}] ERROR: {$errorMessage}");
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Set FULL_TEXT datastream to be sanitized version of full-text document.
            $datastream->setContentFromString($sanitized);
            $this->writeLog("[{$dsid}] Selecting file for datastream:");
            $this->writeLog("[{$dsid}]    {$fttemp}");

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Generating (thumbnail) datastream.");

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $source = $workingDir . "/" . $this->localFiles[$etdShortName]['FILE_ETD'];

            // Use convert (from ImageMagick tool suite) to generate TN document.
            // Execute 'convert' command and check return code.
            $command = "$executable_convert $source -quality 75 -resize 200x200 -colorspace RGB -flatten " . $workingDir . "/thumbnail.jpg";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'convert' command to build TN document.");
            // convert returns 0 on success.
            if ( $return == false ) {
                $this->writeLog("[{$dsid}] Datastream generated successfully.");
            } else {
                $errorMessage = "TN document creation failed. " . $return;
                $this->writeLog("[{$dsid}] ERROR: {$errorMessage}");
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Build Fedora object TN datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various TN datastream values.
            $datastream->label = 'TN';
            $datastream->mimeType = 'image/jpeg';

            // Set TN datastream to be the generated thumbnail image.
            $datastream->setContentFromFile($workingDir . "//thumbnail.jpg");
            $this->writeLog("[{$dsid}] Selecting file for datastream: thumbnail.jpg");

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Generating datastream.");

            // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
            $source = $workingDir . "/" . $this->localFiles[$etdShortName]['FILE_ETD'];

            // Use convert (from ImageMagick tool suite) to generate PREVIEW document.
            // Execute 'convert' command and check return code.
            $command = "$executable_convert $source -quality 75 -resize 500x700 -colorspace RGB -flatten " . $workingDir . "/preview.jpg";
            exec($command, $output, $return);
            $this->writeLog("[{$dsid}] Running 'convert' command to build PREVIEW document.");
            // convert returns 0 on success.
            if ( $return == false ) {
                $this->writeLog("[{$dsid}] PREVIEW datastream generated successfully.");
            } else {
                $errorMessage = "PREVIEW document creation failed. " . $return;
                $this->writeLog("[{$dsid}] ERROR: {$errorMessage}");
                $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                continue;
            }

            // Build Fedora object PREVIEW datastream.
            $datastream = $fedoraObj->constructDatastream($dsid);

            // Set various PREVIEW datastream values.
            $datastream->label = 'PREVIEW';
            $datastream->mimeType = 'image/jpeg';

            // Set PREVIEW datastream to be the generated preview image.
            $datastream->setContentFromFile($workingDir . "//preview.jpg");
            $this->writeLog("[{$dsid}] Selecting TN datastream to use: preview.jpg");

            try {
                $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
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
            $this->writeLog("[{$dsid}] Resuming RELS-EXT datastream ingestion now that other datastreams are generated.");

            // INFO: prepareIngestDatastream() Returns a boolean.
            $status = $this->prepareIngestDatastream($fedoraObj, $policyObj, $dsid, $etdShortName);

            if ( $status === false ) {
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
            $this->writeLog("[{$dsid}] Generating datastream.");
            $this->writeLog("[{$dsid}] Reading in custom RELS XSLT file...");

            // $this->localFiles[$etdShortName]['OA'] is either '0' for no OA policy, or some non-zero value.
            $relsint = '';
            $relsFile = "";
            if ( $this->localFiles[$etdShortName]['OA'] === '0' ) {
                // No OA policy.
                $relsFile = "xsl/permRELS-INT.xml";
                $relsint = file_get_contents($relsFile);

                // Check if file read failed.
                if ( $relsint === false ) {
                    $errorMessage = "Could not read in file: " . $relsFile;
                    $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                    continue;
                }

                $relsint = str_replace('######', $this->localFiles[$etdShortName]['PID'], $relsint);

                $this->writeLog("[{$dsid}] No OA policy for ETD: read in: {$relsFile}");
            } else if ( isset($this->localFiles[$etdShortName]['EMBARGO']) === true ) {
                // Has an OA policy, and an embargo date.
                $relsFile = "xsl/embargoRELS-INT.xml";
                $relsint = file_get_contents($relsFile);

                // Check if file read failed.
                if ( $relsint === false ) {
                    $errorMessage = "Could not read in file: " . $relsFile;
                    $this->writeLog("[{$dsid}] ERROR: {$errorMessage}");
                    $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                    continue;
                }

                $relsint = str_replace('######', $this->localFiles[$etdShortName]['PID'], $relsint);
                $relsint = str_replace('$$$$$$', (string)$this->localFiles[$etdShortName]['EMBARGO'], $relsint);

                $this->writeLog("[{$dsid}] OA policy found and Embargo date found for ETD: read in: {$relsFile}");
            }

            // TODO: handle case where there is an OA policy and no embargo date?

            // Ingest datastream if we have a XACML policy set.
            // INFO: isset() returns true if var exists and has any value other than null. false otherwise.
            if ( (isset($relsint) === true) && ($relsint !== '') ) {
                $dsid = "RELS-INT";

                // Build Fedora object RELS-INT datastream.
                $datastream = $fedoraObj->constructDatastream($dsid);

                // Set various RELS-INT datastream values.
                $datastream->label = 'Fedora Relationship Metadata';
                $datastream->mimeType = 'application/rdf+xml';

                // Set RELS-INT datastream to be the custom XACML policy file read in above.
                $datastream->setContentFromString($relsint);
                $this->writeLog("[{$dsid}] Selecting fire for datastream: {$relsFile}");

                try {
                    $status = $this->prepareIngestDatastream($fedoraObj, $datastream, $dsid, $etdShortName);
                } catch(Exception $e) {
                    // Ingest failed. Continue to the next ETD.
                    continue;
                }
            }

            // Completed datastream completion
            $this->writeLog("Created all datastreams.");

            /**
             * Ingest full object into Fedora.
             *
             *
             */

            // DEBUG: ignore Fedora ingest.
            $res = true;
            if ( $this->debug === true ) {
                $this->writeLog("DEBUG: Ignore ingesting object into Fedora.");
            } else {
                try {
                    $res = $this->repository->ingestObject($fedoraObj);
                    $this->writeLog("START ingestion of Fedora object...");
                } catch (Exception $e) {
                    $errorMessage = "Could not ingest Fedora object. " . $e->getMessage();
                    $this->writeLog("ERROR: {$errorMessage}");
                    $this->datastreamIngestFailed($errorMessage, $dsid, $etdShortName);
                    continue;
                }
            }

            $this->localFiles[$etdShortName]["STATUS"] = "ingested";
            $this->localFiles[$etdShortName]['INGESTED'] = true;
            $this->countProcessedETDs++;
            array_push($this->allIngestedETDs, $this->localFiles[$etdShortName]["ETD_SHORTNAME"]);

            // Make sure we give every processing loop enough time to complete.
            sleep(2);

            // Assign URL to this ETD
            $this->localFiles[$etdShortName]['RECORD_URL'] = "{$this->record_path}{$this->localFiles[$etdShortName]["PID"]}";

            $this->writeLog("END Ingesting ETD file [{$i} of {$this->countTotalETDs}]");
        }
        $this->currentProcessedETD = "";

        $this->writeLog(LOOP_DIVIDER);
        $this->writeLog("Completed ingesting all ETD files.");
        return true;
    }
}
?>
