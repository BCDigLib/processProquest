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
define('SECTION_DIVIDER', "#######################################################");
define('LOOP_DIVIDER', '----------------------------------------');

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
    protected $fedoraConnection = null;     // FedoraRepository object
    protected $ftpConnection = null;        // ProquestFTP object
    protected $allFedoraRecordObjects = []; // List of all FedoraRecord objects
    protected $logFile = "";                // Log file name
    protected $processingErrors = [];       // Keep track of processing errors
    protected $processingFailure = false;   // Track if there's been a critical error
    protected $allFoundETDs = [];           // List of all found ETD zip files
    protected $allFoundETDPaths = [];       // List of all found ETD zip files with full FTP file path
    protected $configurationFile = [];      // The configuration file
    protected $root_url = "";               // The Islandora root url
    protected $path = "";                   // The Islandora record path
    protected $record_path = "";            // Combination of the $root_url and $path
    protected $ftpRoot = "";                // The root directory of the FTP server as defined in the settings ini flle
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

        $this->logger->info("STATUS: Starting processProquest script.");
        $this->logger->info("STATUS: Running with DEBUG value: " . ($this->debug ? 'TRUE' : 'FALSE'));
        $this->logger->info("STATUS: Using configuration file: {$this->configurationFile}");
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
        $this->ftpConnection = $ftpConnectionObj;

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
     * Setter function to append FedoraRecord objects into the allFedoraRecordObjects array.
     * 
     * @param object $fedoraRecord a FedoraRecord object.
     * @param boolean $forceAppend ignore class checking and append object regardless.
     * 
     * @return boolean status value.
     */
    public function appendAllFedoraRecordObjects($fedoraRecord, $forceAppend = false) {
        
        // Don't check class type of passed object.
        if ($forceAppend === true) {
            // Push FedoraRecord object onto the allFedoraRecordObjects array.
            array_push($this->allFedoraRecordObjects, $fedoraRecord);

            return true;
        }

        // Check class type and reject if this is not a FedoraRecord object.
        $className = get_class($fedoraRecord);
        if (strcmp($className, "Processproquest\Record\FedoraRecord") == 0) {
            // Push FedoraRecord object onto the allFedoraRecordObjects array.
            array_push($this->allFedoraRecordObjects, $fedoraRecord);

            return true;
        } 

        return false;
    }

    /**
     * Getter function for allFedoraRecordObjects array.
     * 
     * @return array the allFedoraRecordObjects array.
     */
    public function getAllFedoraRecordObjects() {
        return $this->allFedoraRecordObjects;
    }

    /**
     * Send email notification.
     * 
     * @codeCoverageIgnore
     *
     * @param string $message The email body to send.
     * @return boolean Was the email sent successfully.
     */
    private function sendEmail($message) {
        $this->logger->info("Generating an email notification.");

        $email_to = $this->settings['notify']['email'];
        $email_subject = "Message from processProquest";
        $email_message = $message;

        // Check for empty email values.
        if ( empty($email_to) === true ) {
            $errorMessage = "Email to: field is empty.";
            $this->logger->error("ERROR: {$errorMessage}");
            return false;
        }

        if ( empty($email_subject) === true ) {
            $errorMessage = "Email subject: field is empty.";
            $this->logger->error("ERROR: {$errorMessage}");
            return false;
        }

        if ( empty($email_message) === true ) {
            $errorMessage = "Email body: field is empty.";
            $this->logger->error("ERROR: {$errorMessage}");
            return false;
        }

        $this->logger->info("Attempting to send out the following email:\n\tto:[" . $email_to . "]\n\tbody:[" . $email_message . "]");

        // DEBUG: don't send email.
        $res = true;
        if ( $this->debug === true ) {
            $this->logger->info("DEBUG: Not sending email notification.");
            return true;
        } else {
            // INFO: mail() Returns true if the mail was successfully accepted for delivery, false otherwise.
            $res = mail($email_to, $email_subject, $email_message);
        }

        // Check mail success.
        if ( $res === false ) {
            $errorMessage = "Email not sent.";
            $this->logger->error("ERROR: {$errorMessage}");
            return false;
        }

        $this->logger->info("Email sent.");

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
        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("Logging into FTP server.");

        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        if ( (empty($userFTP) === true) || (empty($passwordFTP) === true) ) {
            $errorMessage = "FTP login values are missing. Please check your settings.";
            $this->manageProcessingError($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Pass login credentials to login method.
        // INFO: login() Returns true on success or false on failure.
        if ( $this->ftpConnection->login($userFTP, $passwordFTP) === true ) {
            $this->logger->info("FTP login sucecssful.");
            return true;
        } else {
            $errorMessage = "FTP login failed.";
            $this->manageProcessingError($errorMessage);
            throw new \Exception($errorMessage);
        }
    }

    /**
     * Moves files on FTP server at the end of the process.
     * 
     * @return boolean Success value.
     */
    private function moveFTPFiles(){
        $processdirFTP = $this->settings['ftp']['processdir'];
        $faildirFTP = $this->settings['ftp']['faildir'];
        $manualdirFTP = $this->settings['ftp']['manualdir'];

        $this->logger->info("BEGIN Moving processed ETDs into respective post-processing directories.");
        $this->logger->info("Currently in FTP directory: {$this->fetchdirFTP}");
        $this->logger->info(LOOP_DIVIDER);

        foreach ($this->allFedoraRecordObjects as $fedoraRecordObj) {
            $ingested = $fedoraRecordObj->INGESTED; // boolean
            $hasSupplements = $fedoraRecordObj->HAS_SUPPLEMENTS; // boolean
            $zipFileName = $fedoraRecordObj->ZIP_FILENAME;
            $ftpPathForETD = $fedoraRecordObj->FTP_PATH_FOR_ETD;

            if ( $ingested === true ) {
                $moveFTPDir = $processdirFTP . $zipFileName;
            } elseif ($hasSupplements  === true ) {
                $moveFTPDir = $manualdirFTP . $zipFileName;
            } else {
                $moveFTPDir = $faildirFTP . $zipFileName;
            }

            $this->logger->info("Was ETD successfully ingested?: " . ($ingested ? "true" : "false"));
            $this->logger->info("Now attempting to move:");
            $this->logger->info("   from: {$ftpPathForETD}");
            $this->logger->info("   into: {$moveFTPDir}");

            if ( $this->debug === true ) {
                $this->logger->info("DEBUG: Not moving ETD files on FTP.");
                $this->logger->info(LOOP_DIVIDER);
                $fedoraRecordObj->setFTPPostprocessLocation($moveFTPDir);
                continue;
            }

            // INFO: moveFile() returns true on success or false on failure.
            $ftpRes = $this->ftpConnection->moveFile($zipFileName, $ftpPathForETD, $moveFTPDir);
            
            // Check if there was an error moving the ETD file on the FTP server.
            if ( $ftpRes === false ) {
                $errorMessage = "Could not move ETD file to '{$moveFTPDir} FTP directory.";
                $this->logger->error("ERROR: {$errorMessage}");
                $this->logger->info(LOOP_DIVIDER);
                // Log this as a noncritical error and continue.
                array_push($fedoraRecordObj->NONCRITICAL_ERRORS, $errorMessage);
                continue;
            } else {
                $this->logger->info("Move was successful.");
                $this->logger->info(LOOP_DIVIDER);
                $fedoraRecordObj->setFTPPostprocessLocation($moveFTPDir);
            }
        }
        $this->logger->info("END Moving processed ETDs into respective post-processing directories.");

        return true;
    }

    /**
     * Find all ETD zip files from FTP server.
     * 
     * @param string $customRegex overwrite the regular expression set in the settings file.
     * 
     * @return array an array of all ETD files found on the FTP server matching the regular expression.
     * 
     * @throws Exception if the working directory isn't reachable, or if there are no ETDs found.
     */
    public function scanForETDFiles(string $customRegex = "") {
        $fn = "fetchFilesFromFTP";

        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Scanning for valid ETD files on the FTP server.");
        $this->logger->info(LOOP_DIVIDER);

        // Look at specific directory on FTP server for ETD files. Ex: /path/to/files/
        $this->fetchdirFTP = $this->settings['ftp']['fetchdir'];
        if ( empty($this->fetchdirFTP) === true ) {
            $this->fetchdirFTP = "~/";
        }

        // Define local directory for file processing. Ex: /tmp/processed/
        $localdirFTP = $this->settings['ftp']['localdir'];
        if ( empty($localdirFTP) === true ) {
            $errorMessage = "Local working directory not set.";
            $this->manageProcessingError($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Change FTP directory if $fetchdirFTP is not empty (aka root directory).
        if ( $this->fetchdirFTP != "" ) {
            // INFO: changeDir() Returns true on success or false on failure.
            if ( $this->ftpConnection->changeDir($this->fetchdirFTP) ) {
                $this->logger->info("Changed to local FTP directory: {$this->fetchdirFTP}");
            } else {
                $errorMessage = "Cound not change FTP directory: {$this->fetchdirFTP}";
                $this->manageProcessingError($errorMessage);
                throw new \Exception($errorMessage);
            }
        }

        //Look for files that begin with a specific string.
        $file_regex = $this->settings['ftp']['file_regex'];

        // Use the custom regex instead if it was passed as an argument.
        if ( empty($customRegex) === false ) {
            $file_regex = $customRegex;
        }

        $this->logger->info("Looking for ETD zip files that match this pattern: /{$file_regex}/");

        // INFO: getFileList() Returns an array of filenames from the specified directory on success or false on error.
        $allFiles = $this->ftpConnection->getFileList($file_regex);

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
        $countTotalETDs = count($etdZipFiles);

        // Throw exception if there are no ETD files to process.
        if ( empty($etdZipFiles) === true ) {
            $errorMessage = "Did not find any ETD files on the FTP server.";
            $this->logger->warning("WARNING: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new \Exception($errorMessage);
        }

        $this->logger->info("Found {$countTotalETDs} ETD file(s):");
        foreach ($etdZipFiles as $zipFileName) {
            $this->logger->info("   • {$zipFileName}");
        }

        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Scanning for valid ETD files on the FTP server.");

        return $this->allFoundETDs;
    }

    /**
     * Generate a single FedoraRecord object.
     * 
     * @param string $zipFileName the name of the zip file.
     * 
     * @return object $recordObj the generated FedoraRecord object.
     */
    public function createFedoraObject($zipFileName) {
        // Create a FedoraRecord object.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);
        $recordObj = new FR\FedoraRecord(
                            $etdShortName, 
                            $this->settings, 
                            $zipFileName, 
                            $this->fedoraConnection, 
                            $this->ftpConnection, 
                            $this->logger
                        );
        $recordObj->setStatus("scanned");

        return $recordObj;
    }

    /**
     * Generate a FedoraRecord object for every ETD zip file found.
     * This function calls createFedoraObject() for every ETD zip file found.
     * 
     * @return array an array of all instantiated FedoraRecord objects.
     * 
     * @throws Exception if there are no ETDs found.
     */
    public function createFedoraObjects() {
        $etdZipFiles = $this->allFoundETDs;
        $countTotalETDs = count($etdZipFiles);

        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Generate Fedora objects.");
        $this->logger->info(LOOP_DIVIDER);

        // Throw exception if there are no ETD files to process.
        if ( empty($etdZipFiles) === true ) {
            $errorMessage = "Did not find any ETD files on the FTP server.";
            $this->logger->warning("WARNING: {$errorMessage}");
            array_push($this->processingErrors, $errorMessage);
            throw new \Exception($errorMessage);
        }

        // Create FedoraRecord objects.
        $this->logger->info("Generating the following Fedora records from ETD file(s):");
        foreach ($etdZipFiles as $zipFileName) {
            // Generate a single FedoraRecord object.
            $recordObj = $this->createFedoraObject($zipFileName);
            array_push($this->allFedoraRecordObjects, $recordObj);
            $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);
            $this->logger->info("   • {$etdShortName}");
        }

        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Generate Fedora objects.");
        $this->logger->info(SECTION_DIVIDER);

        return $this->allFedoraRecordObjects;
    }

    /**
     * This function completes a few tasks.
     *   1) Downloads all available ETD files onto the working directory.
     *   2) Creates a FedoraRecord object to process each ETD file.
     *     a) Parses each ETD file and checks for supplementary files.
     *     b) Processes each file and collects metadata.
     *     c) Generates and ingests various datastreams.
     *     d) Ingests the record.
     * 
     * TODO: don't run this as a loop, but call this function
     *       for each $this->allFedoraRecordObjects element.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception on download, parse, process, or ingest errors.
     */
    public function processAllFiles() {
        // Generate Record objects for further processing.
        // TODO: don't end this loop when a single ETD fails a step.
        foreach ($this->allFedoraRecordObjects as $fedoraRecordObj) {
            // Download ETD zip file from FTP server.
            try {
                $fedoraRecordObj->downloadETD();
            } catch (Exception $e) {
                // Bubble up exception.
                throw $e;
            }

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

            // Generate datastreams for this record.
            try {
                $fedoraRecordObj->generateDatastreams();
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

        return true; 
    }

    /**
     * Process a failed task.
     * 
     * @codeCoverageIgnore
     * 
     * @param string $errorMessage the error message to display.
     */
    private function manageProcessingError($errorMessage) {
        array_push($this->processingErrors, $errorMessage);
        $this->logger->error("ERROR: {$errorMessage}");
        $this->processingFailure = true;
    }

    /**
     * Generate a simple status update message.
     * 
     * @return string $message a summary message of all processed ETDs.
     */
    public function statusCheck(){
        $this->logger->info("Generating status message for email message.");
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
            $countETDs = count($this->allFedoraRecordObjects);
            $message .= "There were {$countETDs} ETD(s) processed.\n"; 
            foreach ($this->allFedoraRecordObjects as $fedoraRecordObj) {
                $i++;
                $criticalErrorsCount = count($fedoraRecordObj->CRITICAL_ERRORS);
                $noncriticalErrorsCount = count($fedoraRecordObj->NONCRITICAL_ERRORS);

                $message .= "\n  [{$i}] Zip filename:      {$fedoraRecordObj->ZIP_FILENAME}\n";
                $message .= "      Status:            {$fedoraRecordObj->STATUS}\n";
                $message .= "      Has supplements:   " . ($fedoraRecordObj->HAS_SUPPLEMENTS ? "true" : "false") . "\n";
                
                // If this ETD has supplements then display message and continue to next ETD.
                if ( $fedoraRecordObj->HAS_SUPPLEMENTS === true ) {
                    $message .= "      WARNING: This ETD contains supplemental files and was not processed.\n";
                    $message .= "               Please manually process the ETD zip file, which can be found here on the FTP server:\n";
                    $message .= "               {$fedoraRecordObj->FTP_POSTPROCESS_LOCATION}\n";
                    continue;
                }

                // Display critical errors and continue to next ETD.
                if ( $criticalErrorsCount > 0 ) {
                    $message .= "      WARNING: This ETD failed to ingest because of the following reasons(s):\n";
                    foreach ($fedoraRecordObj->CRITICAL_ERRORS as $criticalError) {
                        $message .= "       • {$criticalError}\n";
                    }
                    continue;
                }

                $message .= "      Has OA agreement:  " . ($fedoraRecordObj->OA_AVAILABLE ? "true" : "false") . "\n";
                $message .= "      Has embargo:       " . ($fedoraRecordObj->HAS_EMBARGO ? "true" : "false") . "\n";
                if ($fedoraRecordObj->HAS_EMBARGO) {
                    $message .= "      Embargo date:      {$fedoraRecordObj->EMBARGO_DATE}\n";
                }
                $message .= "      PID:               {$fedoraRecordObj->PID}\n";
                $message .= "      URL:               {$fedoraRecordObj->RECORD_URL}\n";
                $message .= "      Author:            {$fedoraRecordObj->AUTHOR}\n";
                $message .= "      Title:             {$fedoraRecordObj->LABEL}\n";

                // Display noncritical errors.
                if ( $noncriticalErrorsCount > 0 ) {
                    $message .= "      WARNING: This ETD was ingested but logged the following noncritical issues:\n";
                    foreach ($fedoraRecordObj->NONCRITICAL_ERRORS as $noncriticalError) {
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

        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("BEGIN Running post-process steps.");

        // Move files in FTP server only when applicable.
        // INFO processingFailure() Returns a boolean.
        if ( $this->processingFailure === false ) {
            $ret = $this->moveFTPFiles();
        }

        // Get overall status.
        $message = $this->statusCheck();

        // Send email.
        $ret = $this->sendEmail($message);

        $this->logger->info("END Running post-process steps.");
        $this->logger->info(SECTION_DIVIDER);
    }
}
?>
