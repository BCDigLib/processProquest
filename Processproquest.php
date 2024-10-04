<?php declare(strict_types=1);
namespace Processproquest;

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
require_once 'ProquestFTP.php';
require_once 'FedoraRecord.php';
use \Processproquest\Record as FR;

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
    protected $allFedoraRecordObjects = []; // List of all FedoraRecord objects
    protected $logFile = "";                // Log file name
    protected $processingErrors = [];       // Keep track of processing errors
    protected $allFoundETDs = [];           // List of all found ETD zip files
    protected $allFoundETDPaths = [];       // List of all found ETD zip files with full FTP file path
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
    protected $configurationFile = [];      // The configuration file
    protected $root_url = "";               // The Islandora root url
    protected $path = "";                   // The Islandora record path
    protected $record_path = "";            // Combination of the $root_url and $path
    protected $ftpRoot = "";                // The root directory of the FTP server as defined in the settings ini flle
    protected $processingFailure = false;   // Track if there's been a critical error
    protected $logger;                      // A Monolog object
    protected $logFileLocation = "";        // Location of the log file
    protected $fetchdirFTP = "";            // The FTP directory to fetch ETD files

    /**
     * Class constructor.
     *
     * This builds a local '$this' object that contains various script settings.
     *
     * @param array $configurationArray An array containing the configuration file and values.
     * @param object $loggerObj The logger object.
     * @param boolean $debug If true run script in debug mode, which doesn't ingest ETD into Fedora.
     * 
     * @throws Exception if an empty logger object was passed as an argument.
     */
    // public function __construct($configurationArray, $loggerObj, $ftpConnectionObj, $debug = DEFAULT_DEBUG_VALUE) {
    public function __construct($configurationArray, $loggerObj, $debug = DEFAULT_DEBUG_VALUE) {
        $this->configurationFile = $configurationArray["file"];
        $this->settings = $configurationArray["settings"];
        $this->debug = boolval($debug);
        $this->root_url = $this->settings["islandora"]["root_url"];
        $this->path = $this->settings["islandora"]["path"];
        $this->record_path = "{$this->root_url}{$this->path}";
        $this->logFile = $this->settings["log"]["location"];
        $this->ftpRoot = $this->settings["ftp"]["fetchdir"];
        $this->processingFailure = false;
        $this->logger = $loggerObj;

        // Pull out logfile location from logger object.
        $logHandlers = $this->logger->getHandlers();
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
    }

    /**
     * Setter function to assign an FTP connection object.
     * This uses a fluent interface API design.
     * 
     * @param object $ftpConnectionObj the FTP connection object.
     * 
     * @return object $this.
     */
    public function setFTPConnection($ftpConnectionObj) {
        $this->ftp = $ftpConnectionObj;

        return $this;
    }

    /**
     * Setter function to assign the debug value.
     * This uses a fluent interface API design.
     * 
     * @param boolean $debug the debug value.
     * 
     * @return object $this.
     */
    public function setDebug($debug) {
        $this->debug = $debug;

        return $this;
    }

    /**
     * Setter function to assign an Fedora connection object.
     * This uses a fluent interface API design.
     * 
     * @param object $fedoraConnectionObj the Fedora connection object.
     * 
     * @return object $this.
     */
    public function setFedoraConnection($fedoraConnectionObj) {
        $this->fedoraConnection = $fedoraConnectionObj;

        return $this;
    }

    /**
     * Output messages to log file and to console.
     *
     * @param string $message The message to log.
     * @param string $functionName Optional. The name of the function calling this function. Is wrapped in ().
     * @param string $prefix Optional. The prefix to include before the message. Is wrapped in [].
     * 
     * @return string The output string.
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

        return $completeMessage;
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
     * Logs into an FTP server.
     *
     * Calls on ProquestFTP.php
     *
     * @return boolean Success value.
     * 
     * @throws Exception if the FTP connection failed.
     */
    public function LogIntoFTPServer() {
        $fn = "LogIntoFTPServer";

        $this->writeLog("Logging into FTP server.");

        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        if ( (empty($userFTP) === true) || (empty($passwordFTP) === true) ) {
            $errorMessage = "FTP login values are missing. Please check your settings.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            $this->processingFailure = true;
            throw new \Exception($errorMessage);
        }

        // Pass login credentials to login method.
        // INFO: login() Returns true on success or false on failure. 
        //       If login fails, PHP will also throw a warning.
        if ( $this->ftp->login($userFTP, $passwordFTP) ) {
            $this->writeLog("FTP login sucecssful.");
            return true;
        } else {
            // TODO: get ftp error message with set_error_handler().
            $errorMessage = "FTP login failed.";
            $this->writeLog("ERROR: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            $this->processingFailure = true;
            throw new \Exception($errorMessage);
        }
    }

    /**
     * Moves files on FTP server at the end of the process.
     * 
     * TODO: update this function.
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

            // INFO: moveFile() returns true on success or false on failure.
            $ftpRes = $this->ftp->moveFile($ftpPathForETD, $moveFTPDir);
            
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
     * Fetch ETD zip files from FTP server. NEW.
     *
     * Create a local directory for each zip file from FTP server and save into directory.
     * Local directory name is based on file name.
     * Next, varify that PDF and XML files exist. Also keep track of supplementary files.
     * 
     * @return array all instantiated FedoraRecord objects.
     * 
     * @throws Exception if the working directory isn't reachable, or there are no ETDs found.
     */
    public function scanForETDFiles() {
        $fn = "fetchFilesFromFTP";

        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("BEGIN scanning for valid ETD files on the FTP server.");

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
            throw new \Exception($errorMessage);
        }

        // Change FTP directory if $fetchdirFTP is not empty (aka root directory).
        if ( $this->fetchdirFTP != "" ) {
            // INFO: changeDir() Returns true on success or false on failure. 
            //       If changing directory fails, PHP will also throw a warning.
            if ( $this->ftp->changeDir($this->fetchdirFTP) ) {
                $this->writeLog("Changed to local FTP directory: {$this->fetchdirFTP}");
            } else {
                $errorMessage = "Cound not change FTP directory: {$this->fetchdirFTP}";
                $this->writeLog("ERROR: {$errorMessage}");
                array_push($this->processingErrors, $errorMessage);
                throw new \Exception($errorMessage);
            }
        }

        /**
         * Look for files that begin with a specific string.
         * In our specific case the file prefix is "etdadmin_upload_*".
         * Save results into $etdZipFiles array.
         */
        $file_regex = $this->settings['ftp']['file_regex'];
         // INFO: getFileList() Returns an array of filenames from the specified directory on success or false on error.
        $allFiles = $this->ftp->getFileList($file_regex);

        // Only collect zip files.
        $etdZipFiles = [];
        $etdZipFilesOnFTP = [];
        foreach($allFiles as $file) {
            if ( str_contains($file, ".zip") === true ) {
                array_push($etdZipFiles, $file);
                array_push($etdZipFilesOnFTP, $this->fetchdirFTP . $file);
            }
        }

        $this->allFoundETDs = $etdZipFiles;
        $this->allFoundETDPaths = $etdZipFilesOnFTP; 
        $this->countTotalETDs = count($etdZipFiles);
        $localdirFTP = $this->settings['ftp']['localdir'];

        // Throw exception if there are no ETD files to process.
        if ( empty($etdZipFiles) === true ) {
            $errorMessage = "Did not find any ETD files on the FTP server.";
            $this->writeLog("WARNING: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new \Exception($errorMessage);
        }

        // Create FedoraRecord objects.
        $this->writeLog("Found {$this->countTotalETDs} ETD file(s).");
        foreach ($etdZipFiles as $zipFileName) {
            $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);
            $workingDir = "{$localdirFTP}{$etdShortName}";
            $recordObj = new FR\FedoraRecord($etdShortName, $this->settings, $workingDir, $zipFileName, $this->fedoraConnection, $this->logger);
            $recordObj->setStatus("scanned");
            array_push($this->allFedoraRecordObjects, $recordObj);
            $this->writeLog("   • {$zipFileName}");
        }

        $this->writeLog("END Scanning for valid ETD files on the FTP server.");

        return $this->allFedoraRecordObjects;
    }

    /**
     * Downloads ETD zip files from the FTP server and places them into the designated working directory.
     * 
     * TODO: move into FedoraRecord.php
     */
    public function downloadETDFiles() {
        $this->writeLog(SECTION_DIVIDER);
        $this->writeLog("BEGIN Downloading each ETD file.");

        $localdirFTP = $this->settings['ftp']['localdir'];
        $countFedoraRecordObjects = count($this->allFedoraRecordObjects);

        /**
         * Loop through each match in $etdZipFiles.
         * There may be multiple matched files so process each individually.
         */
        $f = 0;
        foreach ($this->allFedoraRecordObjects as $fedoraRecordObj) {
            $f++;
            $etdShortName = $fedoraRecordObj->ETD_SHORTNAME;
            $etdWorkingDir = $fedoraRecordObj->WORKING_DIR;
            $zipFileName = $fedoraRecordObj->ZIP_FILENAME;

            $this->writeLog(LOOP_DIVIDER);
            $this->writeLog("BEGIN Downloading ETD file [{$f} of {$countFedoraRecordObjects}]");

            // Check to see if zipFileName is more than four chars. Continue if string fails.
            if ( strlen($zipFileName) <= 4 ) {
                $this->writeLog("WARNING File name only has " . strlen($zipFileName) . " characters. Moving to the next ETD." );
                $this->countTotalInvalidETDs++;
                array_push($this->allInvalidETDs, $zipFileName);
                $fedoraRecordObj->setStatus("invalid");
                continue;
            }
            $this->writeLog("Is file valid?... true.");

            // Increment number of valid ETDs.
            $this->countTotalValidETDs++;

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
                    array_push($this->allFailedETDs, $etdShortName);
                    array_push($this->processingErrors, $errorMessage);
                    $this->writeLog("ERROR: {$errorMessage}");
                    $fedoraRecordObj->setStatus("invalid");
                    continue;
                } else {
                    $this->writeLog("   • Existing directory was removed.");
                }
            }
            
            // INFO: mkdir() Returns true on success or false on failure.
            if ( mkdir($etdWorkingDir, 0755, true) === false ) {
                $errorMessage = "Failed to create local working directory: {$etdWorkingDir}. Moving to the next ETD.";
                array_push($this->allFailedETDs, $etdShortName);
                array_push($this->processingErrors, $errorMessage);
                $this->writeLog("ERROR: {$errorMessage}");
                $fedoraRecordObj->setStatus("invalid");
                continue;
            } else {
                $this->writeLog("   • Directory was created.");
            }
            $etdZipFileFullPath = $etdWorkingDir . "/" . $zipFileName;

            // HACK: give loop some time to create directory.
            usleep(30000); // 30 milliseconds

            /**
             * Gets the file from the FTP server.
             * Saves it locally to local working directory. Ex: /tmp/processing/file_name_1234
             * File is saved locally as a binary file.
             */
            // INFO: getFile() Returns true on success or false on failure.
            if ( $this->ftp->getFile($etdZipFileFullPath, $zipFileName, FTP_BINARY) === true ) {
                $this->writeLog("Downloaded ETD zip file from FTP server.");
            } else {
                $errorMessage = "Failed to download ETD zip file from FTP server: {$etdZipFileFullPath}. Moving to the next ETD.";
                array_push($this->allFailedETDs, $etdShortName);
                array_push($this->processingErrors, $errorMessage);
                $this->writeLog("ERROR: {$errorMessage}");
                $fedoraRecordObj->setStatus("invalid");
                continue;
            }

            // Update status.
            $fedoraRecordObj->setStatus("downloaded");
        }

        $this->writeLog("END Downloading each ETD file.");
    }

    /**
     * This function completes a few tasks.
     *   1) Scans the FTP server for all available ETD files.
     *   2) Downloads all available ETD files onto the working directory.
     *   3) Creates a FedoraRecord object to process each ETD file.
     *     a) Parses each ETD file and checks for supplementary files.
     *     b) Processes each file and collects metadata.
     *     c) Generates and ingests various datastreams.
     *     d) Ingests the record.
     * 
     * @throws Exception on parse, process, or ingest error.
     */
    public function processAllFiles() {
        // scanForETDFiles() can throw an exception.
        try {
            $this->scanForETDFiles();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }
        
        // Download ETD zip file into the working directory.
        $this->downloadETDFiles();

        // Generate Record objects for further processing.
        foreach ($this->allFedoraRecordObjects as $fedoraRecordObj) {
            // Parse through this record.
            try {
                $fedoraRecordObj->parseETD();
            } catch (Exception $e) {
                // Bubble up exception.
                throw $e;
            }

            // Process this record.
            try {
                $fedoraRecordObj->processETD();
            } catch (Exception $e) {
                // Bubble up exception.
                throw $e;
            }

            // Ingest this record.
            try {
                $fedoraRecordObj->ingestETD();
            } catch (Exception $e) {
                // Bubble up exception.
                throw $e;
            }
        }

        return; 
    }

    /**
     * Generate a simple status update message.
     * 
     * TODO: update this function.
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
}
?>
