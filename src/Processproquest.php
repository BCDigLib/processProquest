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
require_once 'src/ProquestFTP.php';
require_once 'src/RecordProcessor.php';
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

class ProcessingException extends \Exception {};

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
    protected $fedoraConnection = null;     // FedoraRepositoryWrapper object
    protected $ftpConnection = null;        // ProquestFTP object
    protected $allFedoraRecordProcessorObjects = []; // List of all FedoraRecordProcessorObject objects
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
     * @param string $configurationFile The configuration file name.
     * @param array $configurationSettings An array containing configuration settings.
     * @param object $loggerObj The logger object.
     * @param boolean $debug If true run script in debug mode, which doesn't ingest ETD into Fedora.
     */
    public function __construct($configurationFile, $configurationSettings, $loggerObj, $debug = DEFAULT_DEBUG_VALUE) {
        $this->configurationFile = $configurationFile;
        $this->settings = $configurationSettings;
        $this->debug = boolval($debug);
        $this->root_url = $this->settings["islandora"]["root_url"];
        $this->path = $this->settings["islandora"]["path"];
        $this->record_path = "{$this->root_url}{$this->path}";
        $this->logFile = $this->settings["log"]["location"];
        $this->ftpRoot = $this->settings["ftp"]["fetchdir"];
        $this->processingFailure = false;
        $this->logger = $loggerObj;

        // Pull out logfile location from logger object.
        // @codeCoverageIgnoreStart
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
        // @codeCoverageIgnoreEnd

        $this->logger->info("STATUS: Starting processProquest script.");
        $this->logger->info("STATUS: Running with DEBUG value: " . ($this->debug ? 'TRUE' : 'FALSE'));
        $this->logger->info("STATUS: Using configuration file: {$this->configurationFile}");
    }

    /**
     * Setter function to assign an FTP connection object.
     * This uses a fluent interface API design.
     * 
     * @param object $ftpConnectionObj The FTP connection object.
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
     * @param boolean $debug The debug value.
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
     * @param object $fedoraConnectionObj The Fedora connection object.
     * 
     * @return object $this.
     */
    public function setFedoraConnection($fedoraConnectionObj) {
        $this->fedoraConnection = $fedoraConnectionObj;

        return $this;
    }

    /**
     * Setter function to append FedoraRecordProcessor objects into the allFedoraRecordProcessorObjects array.
     * 
     * @param object $fedoraRecordProcessor A FedoraRecordProcessor object.
     * @param boolean $forceAppend Ignore class checking and append object regardless.
     * 
     * @return bool Status value.
     */
    public function appendallFedoraRecordProcessorObjects($fedoraRecordProcessorObject, $forceAppend = false) {
        
        // Don't check class type of passed object.
        if ($forceAppend === true) {
            // Push FedoraRecordProcessor object onto the allFedoraRecordProcessorObjects array.
            array_push($this->allFedoraRecordProcessorObjects, $fedoraRecordProcessorObject);

            return true;
        }

        // Check class type and reject if this is not a FedoraRecordProcessor object.
        $className = get_class($fedoraRecordProcessorObject);
        if (strcmp($className, "Processproquest\Record\FedoraRecordProcessor") == 0) {
            // Push FedoraRecordProcessor object onto the allFedoraRecordProcessorObjects array.
            array_push($this->allFedoraRecordProcessorObjects, $fedoraRecordProcessorObject);

            return true;
        } 

        return false;
    }

    /**
     * Getter function for allFedoraRecordProcessorObjects array.
     * 
     * @return array The allFedoraRecordProcessorObjects array.
     */
    public function getallFedoraRecordProcessorObjects() {
        return $this->allFedoraRecordProcessorObjects;
    }

    /**
     * Send email notification.
     * 
     * @codeCoverageIgnore
     *
     * @param string $message The email body to send.
     * 
     * @return bool Was the email sent successfully.
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
        if ( $this->debug === true ) {
            $this->logger->info("DEBUG: Not sending email notification.");

            return true;
        } 

        // INFO: mail() Returns true if the mail was successfully accepted for delivery, false otherwise.
        if ( mail($email_to, $email_subject, $email_message) === false ) {
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
     * @return bool Success value.
     * 
     * @throws ProcessingException if the FTP connection failed.
     */
    public function logIntoFTPServer() {
        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("Logging into FTP server.");

        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];

        if ( (empty($userFTP) === true) || (empty($passwordFTP) === true) ) {
            $errorMessage = "FTP login values are missing. Please check your settings.";
            $this->manageProcessingError($errorMessage);
            throw new ProcessingException($errorMessage);
        }

        // Pass login credentials to login method.
        // INFO: login() Returns true on success or false on failure.
        if ( $this->ftpConnection->login($userFTP, $passwordFTP) === true ) {
            $this->logger->info("FTP login sucecssful.");
            return true;
        } else {
            $errorMessage = "FTP login failed.";
            $this->manageProcessingError($errorMessage);
            throw new ProcessingException($errorMessage);
        }
    }

    /**
     * Moves files on FTP server at the end of the process.
     * 
     * @return bool Success value.
     */
    private function moveFTPFiles(){
        $processdirFTP = $this->settings['ftp']['processdir'];
        $faildirFTP = $this->settings['ftp']['faildir'];
        $manualdirFTP = $this->settings['ftp']['manualdir'];

        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("BEGIN Moving processed ETDs into respective post-processing directories.");
        $this->logger->info("Currently in FTP directory: {$this->fetchdirFTP}");
        $this->logger->info(LOOP_DIVIDER);

        foreach ($this->allFedoraRecordProcessorObjects as $fedoraRecordObj) {
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

            $this->logger->info("Processing ETD: {$fedoraRecordObj->ETD_SHORTNAME}");
            $this->logger->info("Was ETD successfully ingested?: " . ($ingested ? "true" : "false"));
            $this->logger->info("Now attempting to move:");
            $this->logger->info("   from: {$ftpPathForETD}");
            $this->logger->info("   into: {$moveFTPDir}");

            // @codeCoverageIgnoreStart
            if ( $this->debug === true ) {
                $this->logger->info("DEBUG: Not moving ETD files on FTP.");
                $this->logger->info(LOOP_DIVIDER);
                $fedoraRecordObj->setFTPPostprocessLocation($moveFTPDir);
                continue;
            }
            // @codeCoverageIgnoreEnd

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
        $this->logger->info(SECTION_DIVIDER);

        return true;
    }

    /**
     * Find all ETD zip files from FTP server.
     * 
     * @param string $customRegex Overwrite the regular expression set in the settings file.
     * 
     * @return array An array of all ETD files found on the FTP server matching the regular expression.
     * 
     * @throws ProcessingException if the working directory isn't reachable, or if there are no ETDs found.
     */
    public function scanForETDFiles(string $customRegex = "") {
        $fn = "fetchFilesFromFTP";

        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Scanning for valid ETD files on the FTP server.");
        $this->logger->info(LOOP_DIVIDER);

        // Look at specific directory on FTP server for ETD files. Ex: /path/to/files/
        $this->fetchdirFTP = $this->settings['ftp']['fetchdir'];
        if ( empty($this->fetchdirFTP) === true ) {
            $this->fetchdirFTP = "~/";  // @codeCoverageIgnore
        }

        // Define local directory for file processing. Ex: /tmp/processed/
        $localdirFTP = $this->settings['ftp']['localdir'];
        if ( empty($localdirFTP) === true ) {
            $errorMessage = "Local working directory not set.";
            $this->manageProcessingError($errorMessage);
            throw new ProcessingException($errorMessage);
        }

        // Change FTP directory if $fetchdirFTP is not empty (aka root directory).
        if ( $this->fetchdirFTP != "" ) {
            // INFO: changeDir() Returns true on success or false on failure.
            if ( $this->ftpConnection->changeDir($this->fetchdirFTP) ) {
                $this->logger->info("Changed to local FTP directory: {$this->fetchdirFTP}");
            } else {
                $errorMessage = "Cound not change FTP directory: {$this->fetchdirFTP}";
                $this->manageProcessingError($errorMessage);
                throw new ProcessingException($errorMessage);
            }
        }

        //Look for files that begin with a specific string.
        $file_regex = $this->settings['ftp']['file_regex'];

        // Use the custom regex instead if it was passed as an argument.
        if ( empty($customRegex) === false ) {
            $file_regex = $customRegex;  // @codeCoverageIgnore
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
            throw new ProcessingException($errorMessage);
        }

        $this->logger->info("Found {$countTotalETDs} ETD file(s):");
        foreach ($etdZipFiles as $zipFileName) {
            $this->logger->info("   • {$zipFileName}");
        }

        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Scanning for valid ETD files on the FTP server.");
        $this->logger->info(SECTION_DIVIDER);

        return $this->allFoundETDs;
    }

    /**
     * Generate a single FedoraRecordProcessor object.
     * 
     * @param string $zipFileName The name of the zip file.
     * 
     * @return object The generated FedoraRecordProcessor object.
     */
    public function createFedoraRecordProcessorObject($zipFileName) {
        // Create a FedoraRecordProcessor object.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);
        $recordObj = new FR\FedoraRecordProcessor(
                            $etdShortName, 
                            $this->settings, 
                            $zipFileName, 
                            $this->fedoraConnection, 
                            $this->ftpConnection, 
                            $this->logger
                        );
        $recordObj->setStatus("scanned");

        // Append this record to out collection.
        array_push($this->allFedoraRecordProcessorObjects, $recordObj);

        return $recordObj;
    }

    /**
     * Generate a FedoraRecordProcessor object for every ETD zip file found.
     * This function calls createFedoraRecordProcessorObject() for every ETD zip file found.
     * 
     * @return array An array of all instantiated FedoraRecordProcessor objects.
     * 
     * @throws ProcessingException if there are no ETDs found.
     */
    public function createFedoraRecordProcessorObjects() {
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
            throw new ProcessingException($errorMessage);
        }

        // Create FedoraRecordProcessor objects.
        $this->logger->info("Generating the following Fedora records from ETD file(s):");
        foreach ($etdZipFiles as $zipFileName) {
            // Generate a single FedoraRecordProcessor object.
            $recordObj = $this->createFedoraRecordProcessorObject($zipFileName);
            //array_push($this->allFedoraRecordProcessorObjects, $recordObj);
            $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);
            $this->logger->info("   • {$etdShortName}");
        }

        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Generate Fedora objects.");
        $this->logger->info(SECTION_DIVIDER);

        return $this->allFedoraRecordProcessorObjects;
    }

    /**
     * Depricated.
     * This method processes a batch of ETD files. 
     * It calls on processFile() for each ETD to process.
     * 
     * @deprecated
     * @codeCoverageIgnore
     * 
     * @return array Any exceptions caught.
     */
    public function processAllFiles() {
        $caughtExceptions = [];

        foreach ($this->allFedoraRecordProcessorObjects as $fedoraRecordObj) {            
            // Call processFile() method to process each ETD.
            try {
                $this->processFile($fedoraRecordObj);
            } catch (ProcessingException $e) {
                // Capture any exception and pass them back to calling method.
                $thisException = [
                    "record" => $fedoraRecordObj,
                    "exception_caught" => $e
                ];
                array_push($caughtExceptions, $thisException);
            }
        }

        return $caughtExceptions; 
    }

    /**
     * This function completes a few tasks.
     *   1) Download an ETD file onto the working directory.
     *   2) Processes a FedoraRecordProcessor object.
     *     a) Parses the ETD file and checks for supplementary files.
     *     b) Processes the ETD file and collects metadata.
     *     c) Generates and ingests various datastreams.
     *     d) Ingests the record.
     * 
     * @param object $fedoraRecordProcessor The FedoraRecordProcessor object.
     * 
     * @return bool Success value.
     * 
     * @throws RecordProcessingException on download, parse, process, datastream creation, or ingest errors.
     */
    public function processFile($fedoraRecordProcessor) {
        // Generate Record objects for further processing.

        // Download ETD zip file from FTP server.
        try {
            $fedoraRecordProcessor->downloadETD();
        } catch (\Processproquest\Record\RecordProcessingException $e) {
            // Bubble up exception.
            throw $e; // @codeCoverageIgnore
        }

        // Parse through this record.
        try {
            $fedoraRecordProcessor->parseETD();
        } catch (\Processproquest\Record\RecordProcessingException $e) {
            // Bubble up exception.
            throw $e; // @codeCoverageIgnore
        }

        // Process this record.
        try {
            $fedoraRecordProcessor->processETD();
        } catch (\Processproquest\Record\RecordProcessingException $e) {
            // Bubble up exception.
            throw $e; // @codeCoverageIgnore
        }

        // Generate datastreams for this record.
        try {
            $fedoraRecordProcessor->generateDatastreams();
        } catch (\Processproquest\Record\RecordProcessingException $e) {
            // Bubble up exception.
            throw $e; // @codeCoverageIgnore
        } catch (\Processproquest\Record\RecordIngestException $e) {
            // Bubble up exception.
            throw $e; // @codeCoverageIgnore
        }

        // Ingest this record.
        try {
            $fedoraRecordProcessor->ingestETD();
        } catch (\Processproquest\Record\RecordProcessingException $e) {
            // Bubble up ProcessingException.
            throw $e; // @codeCoverageIgnore
        }

        return true; 
    }

    /**
     * Process a failed task.
     * 
     * @codeCoverageIgnore
     * 
     * @param string $errorMessage The error message to display.
     */
    private function manageProcessingError($errorMessage) {
        array_push($this->processingErrors, $errorMessage);
        $this->logger->error("ERROR: {$errorMessage}");
        $this->processingFailure = true;
    }

    /**
     * Generate a simple status update message.
     * 
     * @return string A summary message of all processed ETDs.
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
            $countETDs = count($this->allFedoraRecordProcessorObjects);
            $message .= "There were {$countETDs} ETD(s) processed.\n"; 
            foreach ($this->allFedoraRecordProcessorObjects as $fedoraRecordObj) {
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
     * 
     * @codeCoverageIgnore
     */
    public function postProcess() {
        $fn = "postProcess";

        // Move files in FTP server only when applicable.
        // INFO processingFailure() Returns a boolean.
        if ( $this->processingFailure === false ) {
            $ret = $this->moveFTPFiles();
        }

        // Get overall status.
        $message = $this->statusCheck();

        // Send email.
        $ret = $this->sendEmail($message);
    }
}
?>
