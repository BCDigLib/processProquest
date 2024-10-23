<?php declare(strict_types=1);
namespace Processproquest\Record;

/**
 * Record template.
 */
interface RecordTemplate {
    public function parseETD();
    public function processETD();
    public function generateDatastreams();
    public function ingestETD();
}

class FedoraRecord implements RecordTemplate {
    public $id = "";
    public $settings = [];
    public $debug = "false";
    public $logger = null;
    public $fedoraObj = null;
    public $fedoraConnection = null;
    public $ftpConnection = null;
    public $ETD_SHORTNAME = "";
    public $WORKING_DIR = "";
    public $SUPPLEMENTS = [];
    public $HAS_SUPPLEMENTS = false;
    public $FILE_ETD = "";
    public $FILE_METADATA = "";
    public $ZIP_FILENAME = "";
    public $ZIP_CONTENTS = [];
    public $FTP_PATH_FOR_ETD = "";
    public $FTP_POSTPROCESS_LOCATION = "";
    public $NONCRITICAL_ERRORS = [];
    public $CRITICAL_ERRORS = [];
    public $ZIP_FILE_FULLPATH = "";
    public $STATUS = "";
    public $INGESTED = false;
    public $DATASTREAMS_CREATED = [];
    public $fop_config = "";
    public $executable_fop = "";
    public $executable_convert = "";
    public $executable_pdftk = "";
    public $executable_pdftotext = "";

    /**
     * @param string $id a unique ID for this record.
     * @param array $settings script settings.
     * @param string $zipFileName the name of the ETD zip file.
     * @param object $fedoraConnection the fedoraConnection object.
     * @param object $logger the logger object.
     */
    public function __construct(string $id, array $settings, string $zipFileName, object $fedoraConnection, object $ftpConnection, object $logger) {
        $this->id = $id;
        $this->settings = $settings;
        $this->ETD_SHORTNAME = $id;
        $this->ZIP_FILENAME = $zipFileName;
        $this->ZIP_FILE_FULLPATH = "{$this->WORKING_DIR}/{$this->ZIP_FILENAME}";
        $this->fedoraConnection = $fedoraConnection;
        $this->ftpConnection = $ftpConnection;

        // Calculate WORKING_DIR.
        $localdirFTP = $this->settings['ftp']['localdir'];
        $workingDir = "{$localdirFTP}{$id}";
        $this->WORKING_DIR = $workingDir;
        
        // Clone logger object to adjust the %extra% field in the logger object.
        $recordLogger = $logger->withName('FedoraRecord');
        $recordLogger->pushProcessor(function ($record) {
            // Add ETD_SHORTNAME as an extra field in logger object.
            $record['extra']["ETD"] = "{$this->ETD_SHORTNAME}";

            return $record;
        });
        $this->logger = $recordLogger;

        // Parse settings array.
        $this->debug = boolval($this->settings['script']['debug']);
        $this->root_url = $this->settings["islandora"]["root_url"];
        $this->path = $this->settings["islandora"]["path"];
        $this->fetchDir = $this->settings["ftp"]["fetchdir"];
        $this->record_path = "{$this->root_url}{$this->path}";
        $this->fop_config = $this->settings['packages']['fop_config'];
        $this->executable_fop = $this->settings['packages']['fop'];
        $this->executable_convert = $this->settings['packages']['convert'];
        $this->executable_pdftk = $this->settings['packages']['pdftk'];
        $this->executable_pdftotext = $this->settings['packages']['pdftotext'];
        $this->FTP_PATH_FOR_ETD = "{$this->fetchDir}{$zipFileName}";
        $this->FTP_POSTPROCESS_LOCATION = $this->FTP_PATH_FOR_ETD;
    }

    /**
     * Update this object's status.
     * 
     * @param string $newStatus the new status.
     */
    public function setStatus(string $newStatus) {
        $this->STATUS = $newStatus;
    }

    /**
     * Update this object's FTP_POSTPROCESS_LOCATION value.
     * 
     * @param string $location the new FTP_POSTPROCESS_LOCATION.
     */
    public function setFTPPostprocessLocation(string $location) {
        $this->FTP_POSTPROCESS_LOCATION = $location;
    }

    /**
     * Download ETD zip file.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception download error.
     */
    public function downloadETD() {
        // $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Downloading this ETD file.");

        $etdShortName = $this->ETD_SHORTNAME;
        $etdWorkingDir = $this->WORKING_DIR;
        $zipFileName = $this->ZIP_FILENAME;
        $etdZipFileFullPath = $this->ZIP_FILE_FULLPATH;

        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("Local working directory status:");
        $this->logger->info("   • Directory to create: {$etdWorkingDir}");

        // Create the local directory if it doesn't already exists.
        // INFO: file_exists() Returns true if the file or directory specified by filename exists; false otherwise.
        if ( file_exists($etdWorkingDir) === true ) {
            $this->logger->info("   • Directory already exists.");

            // INFO: $this->recurseRmdir() Returns a boolean success value.
            if ( $this->recurseRmdir($etdWorkingDir) === false ) {
                // Failed to remove directory.
                $errorMessage = "Failed to remove local working directory: {$etdWorkingDir}.";
                $this->recordDownloadFailed($errorMessage);
                throw new \Exception($errorMessage);
            } else {
                $this->logger->info("   • Existing directory was removed.");
            }
        }
        
        // INFO: mkdir() Returns true on success or false on failure.
        if ( mkdir($etdWorkingDir, 0755, true) === false ) {
            $errorMessage = "Failed to create local working directory: {$etdWorkingDir}.";
            $this->recordDownloadFailed($errorMessage);
            throw new \Exception($errorMessage);
        } else {
            $this->logger->info("   • Directory was created.");
        }

        // Give loop some time to create directory.
        usleep(30000); // 30 milliseconds

        /**
         * Gets the file from the FTP server.
         * Saves it locally to local working directory. Ex: /tmp/processing/file_name_1234
         * File is saved locally as a binary file.
         */
        // INFO: getFile() Returns true on success or false on failure.
        if ( $this->ftpConnection->getFile($etdZipFileFullPath, $zipFileName, FTP_BINARY) === true ) {
            $this->logger->info("Downloaded ETD zip file from FTP server.");
        } else {
            $errorMessage = "Failed to download ETD zip file from FTP server: {$etdZipFileFullPath}.";
            $this->recordDownloadFailed($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Update status.
        $this->setStatus("downloaded");
        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Downloading this ETD file.");

        return true;
    }

    /**
     * Parse the ETD zip file fetched from the FTP server.
     *
     * Expand zip file contents into local directory.
     * Check for supplementary files in each ETD zip file.
     *
     * On any download error the function recordParseFailed() is called to manage error handling.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception on parsing and ingest errors.
     */
    public function parseETD() {
        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Parsing this ETD file.");
        
        $this->logger->info(LOOP_DIVIDER);

        $zip = new \ZipArchive;

        // Open and extract zip file to local directory.
        // INFO: zip_open() returns either false or the number of error if filename does not exist 
        //       or in case of other error.
        $res = $zip->open($this->ZIP_FILE_FULLPATH);
        if ($res === TRUE) {
            $zip->extractTo($this->WORKING_DIR);
            $zip->close();
            $this->logger->info("Extracting ETD zip file to local working directory.");
        } else {
            $errorMessage = "Failed to extract ETD zip file: " . $res;
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }

        // There are files we want to ignore when running scandir().
        $filesToIgnore = [".", ".." , $this->ZIP_FILENAME];

        // INFO: array_diff() Returns an array containing all the entries from array that  
        //       are not present in any of the other arrays.
        $expandedETDFiles = array_diff($this->scanAllDir($this->WORKING_DIR), $filesToIgnore);

        if ( count($expandedETDFiles) === 0) {
            // There are no files in this expanded zip file.
            $errorMessage = "There are no files in this expanded zip file.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }

        $this->logger->info("There are " . count($expandedETDFiles) . " files found in this working directory:");

        $z = 0;
        foreach($expandedETDFiles as $etdFileName) {
            $z++;
            $this->logger->info("  [{$z}] File name: {$etdFileName}");
            array_push($this->ZIP_CONTENTS, $etdFileName);
        
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
                if ( ($fileExtension === 'pdf') && (empty($this->FILE_ETD) === true) ) {
                    $this->FILE_ETD = $etdFileName;
                    $this->logger->info("      File type: PDF");
                    continue;
                }

                // Check if this is an XML file.
                if ( ($fileExtension === 'xml') && (empty($this->FILE_METADATA) === true) ) {
                    $this->FILE_METADATA = $etdFileName;
                    $this->logger->info("      File type: XML");
                    continue;
                }

                /**
                 * Supplementary files - could be permissions or data.
                 * Metadata will contain boolean key for permission in DISS_file_descr element.
                 * [0] element should always be folder.
                 */
                try {
                    $checkIfDir = is_dir($this->WORKING_DIR . "/" . $etdFileName);
                } catch (Exception $e) {
                    $errorMessage = "Couldn't check if file is a directory: " . $e->getMessage();
                    $this->logger->info("ERROR: {$errorMessage}");
                    $this->logger->info("trace:\n" . $e->getTraceAsString());
                    // Don't log this error; just continue.
                    continue;
                }

                if ( $checkIfDir === true ) {
                    $this->logger->info("      This is a directory. Next parsed file may be a supplemental file.");
                    // array_push($this->ZIP_CONTENTS_DIRS, $etdFileName);
                    continue;
                } else {
                    array_push($this->SUPPLEMENTS, $etdFileName);
                    $this->HAS_SUPPLEMENTS = true;
                    $this->logger->info("      This is a supplementary file.");
                }
            } else {
                // If file doesn't contain /0016/ then we'll log it as a noncritical error and then ignore it. 
                // Later, we'll check that an expected MOD and PDF file were found in this zip file.
                $errorMessage = "Located a file that was not named properly and was ignored: {$etdFileName}";
                $this->logger->info("      WARNING: {$errorMessage}");
                array_push($this->NONCRITICAL_ERRORS, $errorMessage);
            }

            if ( $this->HAS_SUPPLEMENTS === true ) {
                // At this point we can leave this function if the ETD has supplemental files.
                $this->logger->info("This ETD has supplementary files. No further processing is required. Moving to the next ETD.");
                $this->logger->info("END Gathering ETD file.");
                $this->STATUS = "skipped";
                continue;
            }
        }

        // No need for futher processing if supplemental files were found.
        if ( $this->HAS_SUPPLEMENTS === true ) {
            return false;
        }

        /**
         * Check that both:
         *  - $this->FILE_ETD
         *  - $this->FILE_METADATA
         * are defined and are nonempty strings.
         */
        $this->logger->info("Checking that PDF and XML files were found in this zip file:");
        if ( empty($this->FILE_ETD) === true ) {
            $errorMessage = "   ❌ The ETD PDF file was not found or set.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }
        $this->logger->info("   ✓ The ETD PDF file was found.");

        if ( empty($this->FILE_METADATA) === true ) {
            $errorMessage = "   ❌ The ETD XML file was not found or set.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }
        $this->logger->info("   ✓ The ETD XML file was found.");
        $this->STATUS = "success";
        
        // Completed fetching all ETD zip files.
        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Parsing this ETD file.");

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
    public function processETD() {
        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Processing this ETD file.");
        $this->logger->info(LOOP_DIVIDER);

        // No need to process ETDs that have supplemental files.
        if ( $this->HAS_SUPPLEMENTS === true ) {
            $this->logger->info("SKIP Processing ETD since it contains supplemental files.");
            $this->logger->info("[END] Processing ETD file.");

            return false;
        }

        /**
         * Load Proquest MODS XSLT stylesheet.
         * Ex: /path/to/proquest/crosswalk/Proquest_MODS.xsl
         */
        $xslt = new \xsltProcessor;
        $proquestxslt = new \DOMDocument();

        $proquestxslt->load($this->settings['xslt']['xslt']);
        // INFO: XSLTProcessor::importStylesheet() Returns true on success or false on failure.
        if ( $xslt->importStyleSheet($proquestxslt)  === true) {
            $this->logger->info("Loaded MODS XSLT stylesheet.");
        } else {
            $errorMessage = "Failed to load MODS XSLT stylesheet.";
            $this->logger->info("ERROR: {$errorMessage}");
            array_push($this->CRITICAL_ERRORS, $errorMessage);
            throw new \Exception($errorMessage);
        }

        /**
         * Load Fedora Label XSLT stylesheet.
         * Ex: /path/to/proquest/xsl/getLabel.xsl
         */
        $label = new \xsltProcessor;
        $labelxslt = new \DOMDocument();
        $labelxslt->load($this->settings['xslt']['label']);
        if ( $label->importStyleSheet($labelxslt) === true ) {
            $this->logger->info("Loaded Fedora Label XSLT stylesheet.");
        } else {
            $errorMessage = "Failed to load Fedora Label XSLT stylesheet.";
            $this->logger->info("ERROR: {$errorMessage}");
            array_push($this->CRITICAL_ERRORS, $errorMessage);
            throw new \Exception($errorMessage);
        }

        /**
         * Given the array of ETD local files, generate additional metadata.
         */

        $zipFileName = $this->ZIP_FILENAME;
        $etdShortName = $this->ETD_SHORTNAME;

        // Create XPath object from the ETD XML file.
        $metadata = new \DOMDocument();
        $metadata->load($this->WORKING_DIR . '//' . $this->FILE_METADATA);
        $xpath = new \DOMXpath($metadata);

        /**
         * Get OA permission.
         * This looks for the existance of an "oa" node in the XPath object.
         * Ex: /DISS_submission/DISS_repository/DISS_acceptance/text()
         */
        $this->logger->info("Searching for OA agreement...");

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
            $this->logger->info("No OA agreement found.");
        } elseif ( $oaElements->item(0)->C14N() === '0' ) {
            $this->logger->info("No OA agreement found.");
        } else {
            // This value is '1' if available for Open Access.
            $openaccess = $oaElements->item(0)->C14N();
            $openaccess_available = true;
            $this->logger->info("Found an OA agreement.");
        }

        $this->OA = $openaccess;
        $this->OA_AVAILABLE = $openaccess_available;

        /**
         * Get embargo permission/dates.
         * This looks for the existance of an "embargo" node in the XPath object.
         * Ex: /DISS_submission/DISS_repository/DISS_delayed_release/text()
         */
        $this->logger->info("Searching for embargo information...");

        $embargo = 0;
        $has_embargo = false;
        $this->HAS_EMBARGO = false;
        $emElements = $xpath->query($this->settings['xslt']['embargo']);
        if ( $emElements->item(0) ) {
            $has_embargo = true;
            // Convert date string into proper PHP date object format.
            $embargo = $emElements->item(0)->C14N();
            $this->logger->info("Unformatted embargo date: {$embargo}");
            $embargo = str_replace(" ","T",$embargo);
            $embargo = $embargo . "Z";
            $this->logger->info("Using embargo date of: {$embargo}");
        } else {
            $this->logger->info("There is no embargo on this record.");
        }

        /**
         * Check to see if there is no OA policy, and there is no embargo.
         * If so, set the embargo permission/date to "indefinite".
         */
        if ( $openaccess_available === $has_embargo ) {
            $embargo = 'indefinite';
            $has_embargo = true;
            $this->logger->info("Changing embargo date to 'indefinite'");
            $this->logger->info("Using embargo date of: {$embargo}");
        }

        $this->HAS_EMBARGO = $has_embargo;
        $this->EMBARGO = $embargo;
        $this->EMBARGO_DATE = $embargo;

        /**
         * Fetch next PID from Fedora.
         * Prepend PID with locally defined Fedora namespace.
         * Ex: "bc-ir:" for BC.
         */
        // DEBUG: generate random PID.
        if ( $this->debug === true ) {
            $pid = "bc-ir:" . rand(50000,100000) + 9000000;
            $this->logger->info("DEBUG: Generating random PID for testing (NOT fetched from Fedora): {$pid}");
        } else {
            $pid = $this->fedoraConnection->getNextPid($this->settings['fedora']['namespace'], 1);
            $this->logger->info("Fetched new PID from Fedora: {$pid}");
        }

        $this->PID = $pid;

        $this->logger->info("Fedora PID value for this ETD: {$pid}");

        /**
         * Insert the PID value into the Proquest MODS XSLT stylesheet.
         * The "handle" value should be set the PID.
         */
        // INFO: XSLTProcessor::setParameter() Returns true on success or false on failure.
        $res = $xslt->setParameter('mods', 'handle', $pid);
        if ( $res === false ) {
            $errorMessage = "Could not update XSLT stylesheet with PID value.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }
        $this->logger->info("Update XSLT stylesheet with PID value.");

        /**
         * Generate MODS file.
         * This file is generated by applying the Proquest MODS XSLT stylesheet to the ETD XML file.
         * Additional metadata will be generated from the MODS file.
         */
        // INFO: XSLTProcessor::transformToDoc() The resulting document or false on error.
        $mods = $xslt->transformToDoc($metadata);
        if ( $mods === false ) {
            $errorMessage = "Could not transform ETD MODS XML file.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }
        $this->logger->info("Transformed ETD MODS XML file with XSLT stylesheet.");

        /**
         * Generate ETD title/Fedora Label.
         * The title is generated by applying the Fedora Label XSLT stylesheet to the above generated MODS file.
         * This uses mods:titleInfo.
         */
        // INFO: XSLTProcessor::transformToXml() The result of the transformation as a string or false on error.
        $fedoraLabel = $label->transformToXml($mods);
        if ( $fedoraLabel === false ) {
            $errorMessage = "Could not generate ETD title using Fedora Label XSLT stylesheet.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }
        $this->LABEL = $fedoraLabel;

        $this->logger->info("Generated ETD title: " . $fedoraLabel);

        /**
         * Generate ETD author.
         * This looks for the existance of an "author" node in the MODS XPath object.
         * Ex: /mods:mods/mods:name[@type='personal'][@usage='primary']/mods:displayForm/text()
         */
        $xpathAuthor = new \DOMXpath($mods);
        $authorElements = $xpathAuthor->query($this->settings['xslt']['creator']);
        $author = $authorElements->item(0)->C14N();
        $this->logger->info("Generated ETD author: [{$author}]");

        /**
         * Normalize the ETD author string. This forms the internal file name convention.
         * Ex: Jane Anne O'Foo => Jane-Anne-OFoo
         */
        $normalizedAuthor = $this->normalizeString($author);
        $this->AUTHOR = $author;
        $this->AUTHOR_NORMALIZED = $normalizedAuthor;

        $this->logger->info("Generated normalized ETD author: [{$normalizedAuthor}]");
        $this->logger->info("Now using the normalized ETD author name to update ETD PDF and MODS files.");

        // Create placeholder full-text text file using normalized author's name.
        $this->FULLTEXT = $normalizedAuthor . ".txt";

        // Rename Proquest PDF using normalized author's name.
        // INFO: rename() Returns true on success or false on failure.
        $res = rename($this->WORKING_DIR . "/". $this->FILE_ETD , $this->WORKING_DIR . "/" . $normalizedAuthor . ".pdf");
        if ( $res === false ) {
            $errorMessage = "Could not rename ETD PDF file.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Update local file path for ETD PDF file.
        $normalizedAuthorPDFName = $normalizedAuthor . ".pdf";
        $this->logger->info("Renamed ETD PDF file from {$this->FILE_ETD} to {$normalizedAuthorPDFName}");
        $this->FILE_ETD = $normalizedAuthorPDFName;

        // Save MODS using normalized author's name.
        // INFO: DOMDocument::save() Returns the number of bytes written or false if an error occurred.
        $res = $mods->save($this->WORKING_DIR . "/" . $normalizedAuthor . ".xml");
        if ( $res === false ) {
            $errorMessage = "Could not create new ETD MODS file.";
            $this->recordParseFailed($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Update local file path for MODS file.
        $this->MODS = $normalizedAuthor . ".xml";
        $this->logger->info("Created new ETD MODS file {$this->MODS}");

        /**
         * Check for supplemental files.
         * This looks for the existance of an "DISS_attachment" node in the ETD XML XPath object.
         * Ex: /DISS_submission/DISS_content/DISS_attachment
         */
        // TODO: remove duplicative logic to find supplemental files.
        // $suppxpath = new DOMXpath($metadata);
        // $suElements = $suppxpath->query($this->settings['xslt']['supplement']);

        $this->STATUS = "processed";
        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Processing this ETD file.");

        return true;
    }

    /**
     * Generate Fedora datastreams.
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
     * @return boolean Success value.
     * 
     * @throws Exception if there are no ETDs to ingest.
     */
    public function generateDatastreams() {
        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Generating datastreams.");
        $this->logger->info(LOOP_DIVIDER);

        $etdShortName = $this->ETD_SHORTNAME;

        // Go through each ETD local file bundle.
        $workingDir = $this->WORKING_DIR;
        $this->DATASTREAMS_CREATED = [];
        $this->INGESTED = false;

        // No need to process ETDs that have supplemental files.
        if ( $this->HAS_SUPPLEMENTS === true ) {
            $this->logger->info("SKIP Ingesting ETD since it contains supplemental files.");
            $this->logger->info(LOOP_DIVIDER);
            $this->logger->info("[END] Generating datastreams.");

            return false;
        }

        // Instantiated a Fedora object and use the generated PID as its ID.
        // TODO: not sure this function throws an exception
        //       https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php
        try {
            $this->fedoraObj = $this->fedoraConnection->constructObject($this->PID);
            $this->logger->info("Instantiated a Fedora object with PID: {$this->PID}");
        } catch (Exception $e) {
            $errorMessage = "Could not instantiate a Fedora object with PID '{$this->PID}'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
            $this->recordIngestFailed($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Assign the Fedora object label the ETD name/label
        $this->fedoraObj->label = $this->LABEL;
        $this->logger->info("Assigned a title to Fedora object: {$this->LABEL}");

        // All Fedora objects are owned by the same generic account
        $this->fedoraObj->owner = 'fedoraAdmin';

        $this->logger->info("Now generating Fedora datastreams:");

        /**
         * Generate RELS-EXT (XACML) datastream.
         *
         *
         */
        $dsid = "RELS-EXT";
        $this->logger->info("[{$dsid}] Generating (XACML) datastream.");

        // Set the default Parent and Collection policies for the Fedora object.
        try {
            $parentObject = $this->fedoraConnection->getObject(ISLANDORA_BC_ROOT_PID);
            $collectionName = GRADUATE_THESES;
        } catch (Exception $e) { // RepositoryException
            $errorMessage = "Could not fetch Fedora object '" . ISLANDORA_BC_ROOT_PID . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Update the Parent and Collection policies if this ETD is embargoed.
        if (isset($this->EMBARGO)) {
            $collectionName = GRADUATE_THESES_RESTRICTED;
            try {
                $parentObject = $this->fedoraConnection->getObject(ISLANDORA_BC_ROOT_PID_EMBARGO);
                $this->logger->info("[{$dsid}] Adding to Graduate Theses (Restricted) collection.");
            } catch (Exception $e) { // RepositoryException
                $errorMessage = "Could not fetch Fedora object '" . ISLANDORA_BC_ROOT_PID_EMBARGO . "'. Please check the Fedora connection. Fedora error: " . $e->getMessage();
                $this->datastreamIngestFailed($errorMessage, $dsid);
                throw new \Exception($errorMessage);
            }
        } else {
            $this->logger->info("[{$dsid}] Adding to Graduate Theses collection.");
        }

        // Update the Fedora object's relationship policies
        $this->fedoraObj->models = array('bc-ir:graduateETDCModel');
        $this->fedoraObj->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $collectionName);

        // Set various other Fedora object settings.
        $this->fedoraObj->checksumType = 'SHA-256';
        $this->fedoraObj->state = 'I';

        // Get Parent XACML policy.
        $policyObj = $parentObject->getDatastream(ISLANDORA_BC_XACML_POLICY);
        $this->logger->info("[{$dsid}] Fetching Islandora XACML datastream.");
        $this->logger->info("[{$dsid}] Deferring RELS-EXT (XACML) datastream ingestion until other datastreams are generated.");

        /**
         * Build MODS Datastream.
         *
         *
         */
        try {
            $status = $this->datastreamMODS();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build ARCHIVE MODS datastream.
         *
         * Original Proquest Metadata will be saved as ARCHIVE.
         * Original filename is used as label for identification.
         */
        try {
            $status = $this->datastreamMODS();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build ARCHIVE-PDF datastream.
         *
         * PDF will always be loaded as ARCHIVE-PDF DSID regardless of embargo.
         * Splash paged PDF will be PDF dsid.
         */
        try {
            $status = $this->datastreamARCHIVEPDF();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build PDF datastream.
         *
         * First, build splash page PDF.
         * Then, concatenate splash page onto ETD PDF for final PDF.
         */
        try {
            $status = $this->datastreamPDF();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build FULL_TEXT datastream.
         *
         *
         */
        try {
            $status = $this->datastreamFULLTEXT();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build Thumbnail (TN) datastream
         *
         *
         */
        try {
            $status = $this->datastreamTN();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build PREVIEW datastream.
         *
         *
         */
        try {
            $status = $this->datastreamPREVIEW();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Continue RELS-EXT datastream.
         *
         *
         */
        // TODO: understand why this command is down here and not in an earlier POLICY datastream section.
        $dsid = "RELS-EXT";
        $this->logger->info("[{$dsid}] Resuming RELS-EXT datastream ingestion now that other datastreams are generated.");
        try {
            $status = $this->manageIngestDatastream($policyObj, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        /**
         * Build RELS-INT datastream.
         *
         * This checks if there is an OA policy set for this ETD.
         * If there is, then set Embargo date in the custom XACML policy file.
         */
        try {
            $status = $this->datastreamRELSINT();
        } catch (Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        // Completed datastream completion
        $this->logger->info("Created all datastreams.");

        /**
         * Ingest full object into Fedora.
         *
         *
         */

        // DEBUG: ignore Fedora ingest.
        $res = true;
        if ( $this->debug === true ) {
            $this->logger->info("DEBUG: Ignore ingesting object into Fedora.");
        } else {
            try {
                $res = $this->fedoraConnection->ingestObject($this->fedoraObj);
                $this->logger->info("START ingestion of Fedora object...");
            } catch (Exception $e) {
                $errorMessage = "Could not ingest Fedora object. " . $e->getMessage();
                $this->recordIngestFailed($errorMessage);
                throw new \Exception($errorMessage);
                $this->logger->info("END ingestion of Fedora object...");
            }
        }

        $this->STATUS = "ingested";
        $this->INGESTED = true;

        // Make sure we give every processing loop enough time to complete.
        usleep(30000); // 30 milliseconds

        // Assign URL to this ETD
        $this->RECORD_URL = "{$this->record_path}{$this->PID}";

        // $this->logger->info("END Ingesting ETD file [{$i} of {$this->countTotalETDs}]");
        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Generating datastreams.");
        $this->logger->info(SECTION_DIVIDER);

        return true;
    }

    /**
     * Ingest a Fedora record.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the Fedora record failed to ingest.
     */
    public function ingestETD() {
        $this->logger->info(SECTION_DIVIDER);
        $this->logger->info("[BEGIN] Ingesting this ETD file.");
        $this->logger->info(LOOP_DIVIDER);
        
        // DEBUG: ignore Fedora ingest.
        if ( $this->debug === true ) {
            $this->logger->info("DEBUG: Ignore ingesting object into Fedora.");
        } else {
            try {
                $this->fedoraConnection->ingestObject($this->fedoraObj);
                $this->logger->info("START ingestion of Fedora object...");

                // Make sure we give this ingest process enough time to complete.
                usleep(30000); // 30 milliseconds
            } catch (Exception $e) {
                $errorMessage = "Could not ingest Fedora object. " . $e->getMessage();
                $this->recordIngestFailed($errorMessage);
                throw new \Exception($errorMessage);
                $this->logger->info("END ingestion of Fedora object...");
            }
        }

        $this->STATUS = "ingested";
        $this->INGESTED = true;

        // Assign URL to this ETD
        $this->RECORD_URL = "{$this->record_path}{$this->PID}";

        // $this->logger->info("END Ingesting ETD file [{$i} of {$this->countTotalETDs}]");
        $this->logger->info(LOOP_DIVIDER);
        $this->logger->info("[END] Ingesting this ETD file.");
        $this->logger->info(SECTION_DIVIDER);

        return true;
    }

    /**
     * Manages Fedora datastreams for ingestion.
     *
     * @param $datastreamObj A datastream object, usually a file.
     * @param $datastreamName The name of the datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    private function manageIngestDatastream($datastreamObj, $datastreamName) {
        if ( $this->debug === true ) {
            array_push($this->DATASTREAMS_CREATED, $datastreamName);
            $this->logger->info("[{$datastreamName}] DEBUG: Did not ingest datastream.");

            return true;
        }

        // Ingest datastream into Fedora object.
        try {
            $this->fedoraConnection->ingestDatastream($datastreamObj);
        } catch (Exception $e) {
            $errorMessage = "{$datastreamName} datastream ingest failed: " . $e->getMessage();
            array_push($this->CRITICAL_ERRORS, $errorMessage);
            $this->logger->info("ERROR: {$errorMessage}");
            $this->logger->info("trace:\n" . $e->getTraceAsString());
            throw new \Exception($errorMessage);
        }

        array_push($this->DATASTREAMS_CREATED, $datastreamName);
        $this->logger->info("[{$datastreamName}] Ingested datastream.");

        return true;
    }

    /**
     * Process a failed file download task.
     * This is a wrapper for the processRecordError() function.
     * 
     * @param string $errorMessage the error message to display.
     */
    private function recordDownloadFailed(string $errorMessage) {
        $completeErrorMessage = "ERROR: {$errorMessage}";
        $this->processRecordError($completeErrorMessage);
    }

    /**
     * Process a failed file parsing task.
     * This is a wrapper for the processRecordError() function.
     * 
     * @param string $errorMessage the error message to display.
     */
    private function recordParseFailed(string $errorMessage) {
        $completeErrorMessage = "ERROR: {$errorMessage}";
        $this->processRecordError($completeErrorMessage);
    }

    /**
     * Process a failed record ingest task.
     * This is a wrapper for the processRecordError() function.
     * 
     * @param string $errorMessage the error message to display.
     */
    private function recordIngestFailed(string $errorMessage) {
        $completeErrorMessage = "ERROR: {$errorMessage}";
        $this->processRecordError($completeErrorMessage);
    }

    /**
     * Process a failed datastream ingest task.
     * This is a wrapper for the processRecordError() function.
     * 
     * @param string $errorMessage the error message to display.
     * @param string $datastreamName the name of the datastream.
     */
    private function datastreamIngestFailed(string $errorMessage, string $datastreamName) {
        $completeErrorMessage = "[{$datastreamName}] ERROR: {$errorMessage}";
        $this->processRecordError($completeErrorMessage);
    }

    /**
     * Process a failed task.
     * 
     * @param string $errorMessage the error message to display.
     */
    private function processRecordError(string $errorMessage) {
        array_push($this->CRITICAL_ERRORS, $errorMessage);
        $this->logger->error($errorMessage);
        $this->STATUS = "failed";
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
     * Strips out punctuation, spaces, and unicode chars from a string.
     * 
     * @return string A normalized string.
     * 
     * @throws Exception if the datastream ingest failed.
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
     * Create the MODS datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamMODS() {
        $dsid = 'MODS';
        $this->logger->info("[{$dsid}] Generating datastream.");

        // Build Fedora object MODS datastream.
        $datastream = $this->fedoraObj->constructDatastream($dsid, 'X');

        // Set various MODS datastream values.
        $datastream->label = 'MODS Record';
        // OLD: $datastream->label = $this->LABEL;
        $datastream->mimeType = 'application/xml';

        // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/author_name.XML
        $datastream->setContentFromFile($this->WORKING_DIR . "//" . $this->MODS);
        $this->logger->info("[{$dsid}] Selecting file for this datastream:");
        $this->logger->info("[{$dsid}]   {$this->MODS}");

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the ARCHIVE datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamARCHIVE() {
        $dsid = 'ARCHIVE';
        $this->logger->info("[{$dsid}] Generating datastream.");

        // Build Fedora object ARCHIVE MODS datastream from original Proquest XML.
        $datastream = $this->fedoraObj->constructDatastream($dsid, 'X');

        // Assign datastream label as original Proquest XML file name without file extension. Ex: etd_original_name
        $datastream->label = substr($this->FILE_METADATA, 0, strlen($this->FILE_METADATA)-4);
        //$this->logger->info("Using datastream label: " . $datastream->label);

        // Set datastream content to be DOMS file. Ex: /tmp/processed/file_name_1234/etd_original_name.XML
        $datastream->setContentFromFile($this->WORKING_DIR . "//" . $this->FILE_METADATA);
        $this->logger->info("[{$dsid}] Selecting file for this datastream:");
        $this->logger->info("[{$dsid}]    {$this->FILE_METADATA}");

        // Set various ARCHIVE MODS datastream values.
        $datastream->mimeType = 'application/xml';
        $datastream->checksumType = 'SHA-256';
        $datastream->state = 'I';

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the ARCHIVE-PDF datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamARCHIVEPDF() {
        $dsid = 'ARCHIVE-PDF';
        $this->logger->info("[{$dsid}] Generating datastream.");

        // Default Control Group is M.
        // Build Fedora object ARCHIVE PDF datastream from original Proquest PDF.
        $datastream = $this->fedoraObj->constructDatastream($dsid);

        // OLD: $datastream->label = $this->LABEL;
        $datastream->label = 'ARCHIVE-PDF Datastream';

        // Set various ARCHIVE-PDF datastream values.
        $datastream->mimeType = 'application/pdf';
        $datastream->checksumType = 'SHA-256';
        $datastream->state = 'I';

        // Set datastream content to be ARCHIVE-PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
        $datastream->setContentFromFile($this->WORKING_DIR . "//" . $this->FILE_ETD);
        $this->logger->info("[{$dsid}] Selecting file for this datastream:");
        $this->logger->info("[{$dsid}]   {$this->FILE_ETD}");

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the PDF datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamPDF() {
        $dsid = "PDF";
        $this->logger->info("[{$dsid}] Generating datastream.");
        $this->logger->info("[{$dsid}] First, generate PDF splash page.");

        // Source file is the original Proquest XML file.
        $source = $this->WORKING_DIR . "/" . $this->MODS;

        // Assign PDF splash document to ETD file's directory.
        $splashtemp = $this->WORKING_DIR . "/splash.pdf";

        // Use the custom XSLT splash stylesheet to build the PDF splash document.
        $splashxslt = $this->settings['xslt']['splash'];

        // Use FOP (Formatting Objects Processor) to build PDF splash page.
        // Execute 'fop' command and check return code.
        $command = "{$this->executable_fop} -c {$this->fop_config} -xml {$source} -xsl {$splashxslt} -pdf {$splashtemp}";
        exec($command, $output, $return);
        $this->logger->info("[{$dsid}] Running 'fop' command to build PDF splash page.");
        // FOP returns 0 on success.
        if ( $return == false ) {
            $this->logger->info("[{$dsid}] Splash page created successfully.");
        } else {
            $errorMessage = "PDF splash page creation failed. ". $return;
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Update ETD file's object to store splash page's file location and name.
        $this->SPLASH = 'splash.pdf';
        array_push($this->DATASTREAMS_CREATED, "SPLASH");

        /**
         * Build concatted PDF document.
         *
         * Load splash page PDF to core PDF if under embargo.
         */
        $this->logger->info("[{$dsid}] Next, generate concatenated PDF document.");

        // Assign concatenated PDF document to ETD file's directory.
        $concattemp = $this->WORKING_DIR . "/concatted.pdf";

        // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
        $pdf = $this->WORKING_DIR . "//" . $this->FILE_ETD;

        /*
        // Temporarily deactivating the use of pdftk -- binary is no longer supported in RHEL 7

        // Use pdftk (PDF Toolkit) to edit PDF document.
        // Execute 'pdftk' command and check return code.
        $command = "$executable_pdftk $splashtemp $pdf cat output $concattemp";
        exec($command, $output, $return);
        $this->logger->info("Running 'pdftk' command to build concatenated PDF document.");

        if (!$return) {
            $this->logger->info("Concatenated PDF document created successfully.");
        } else {
            $this->logger->info("ERROR: Concatenated PDF document creation failed! " . $return);
            $this->ingestHandlerPostProcess(false, $etdShortName, $this->etd);
            continue;
        }
        */

        // Temporarily copying over the $pdf file as the $concattemp version since pdftk is not supported on RHEL7
        $this->logger->info("[{$dsid}] WARNING: A splashpage will not be appended to the ingested PDF file. Instead, a clone of the original PDF will be used.");

        // INFO: copy() Returns true on success or false on failure.
        if ( copy($pdf,$concattemp) === false ) {
            $errorMessage = "Could not generate a concatenated PDF document.";
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        } else {
            $this->logger->info("[{$dsid}] PDF document cloned successfully.");
        }

        // Default Control Group is M
        // Build Fedora object PDF datastream.
        $datastream = $this->fedoraObj->constructDatastream($dsid);

        // Set various PDF datastream values.
        $datastream->label = 'PDF Datastream';
        $datastream->mimeType = 'application/pdf';
        $datastream->checksumType = 'SHA-256';

        // Set datastream content to be PDF file. Ex: /tmp/processed/file_name_1234/concatted.PDF
        $datastream->setContentFromFile($concattemp);
        $this->logger->info("[{$dsid}] Selecting file for datastream:");
        $this->logger->info("[{$dsid}]    {$concattemp}");

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the FULL_TEXT datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamFULLTEXT() {
        $dsid = "FULL_TEXT";
        $this->logger->info("[{$dsid}] Generating datastream.");

        // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
        $source = $this->WORKING_DIR . "/" . $this->FILE_ETD;

        // Assign FULL_TEXT document to ETD file's directory.
        $fttemp = $this->WORKING_DIR . "/fulltext.txt";

        // Use pdftotext (PDF to Text) to generate FULL_TEXT document.
        // Execute 'pdftotext' command and check return code.
        $command = "{$this->executable_pdftotext} {$source} {$fttemp}";
        exec($command, $output, $return);
        $this->logger->info("[{$dsid}] Running 'pdftotext' command.");
        // pdftotext returns 0 on success.
        if ( $return == false ) {
            $this->logger->info("[{$dsid}] datastream generated successfully.");
        } else {
            $errorMessage = "FULL_TEXT document creation failed. " . $return;
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Build Fedora object FULL_TEXT datastream.
        $datastream = $this->fedoraObj->constructDatastream($dsid);

        // Set various FULL_TEXT datastream values.
        $datastream->label = 'FULL_TEXT';
        $datastream->mimeType = 'text/plain';

        // Read in the full-text document that was just generated.
        // INFO: file_get_contents() The function returns the read data or false on failure.
        $fulltext = file_get_contents($fttemp);

        // Check if file read failed.
        if ( $fulltext === false ) {
            $errorMessage = "Could not read in file: ". $fttemp;
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Strip out junky characters that mess up SOLR.
        $replacement = '';
        // INFO: preg_replace() Returns an array if the subject parameter is an array, or a string otherwise.
        $sanitized = preg_replace('/[\x00-\x1f]/', $replacement, $fulltext);

        // In the slim chance preg_replace returns an empty string.
        if ( $sanitized === '' ) {
            $errorMessage = "preg_replace failed to return valid sanitized FULL_TEXT string. String has length of 0.";
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Set FULL_TEXT datastream to be sanitized version of full-text document.
        $datastream->setContentFromString($sanitized);
        $this->logger->info("[{$dsid}] Selecting file for datastream:");
        $this->logger->info("[{$dsid}]    {$fttemp}");

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the TN datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamTN() {
        $dsid = "TN";
        $this->logger->info("[{$dsid}] Generating (thumbnail) datastream.");

        // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
        $source = $this->WORKING_DIR . "/" . $this->FILE_ETD;

        // Use convert (from ImageMagick tool suite) to generate TN document.
        // Execute 'convert' command and check return code.
        $command = "{$this->executable_convert} {$source} -quality 75 -resize 200x200 -colorspace RGB -flatten {$this->WORKING_DIR}/thumbnail.jpg";
        exec($command, $output, $return);
        $this->logger->info("[{$dsid}] Running 'convert' command to build TN document.");
        // convert returns 0 on success.
        if ( $return == false ) {
            $this->logger->info("[{$dsid}] Datastream generated successfully.");
        } else {
            $errorMessage = "TN document creation failed. " . $return;
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Build Fedora object TN datastream.
        $datastream = $this->fedoraObj->constructDatastream($dsid);

        // Set various TN datastream values.
        $datastream->label = 'TN';
        $datastream->mimeType = 'image/jpeg';

        // Set TN datastream to be the generated thumbnail image.
        $datastream->setContentFromFile($this->WORKING_DIR . "//thumbnail.jpg");
        $this->logger->info("[{$dsid}] Selecting file for datastream: thumbnail.jpg");

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the PREVIEW datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamPREVIEW() {
        $dsid = "PREVIEW";
        $this->logger->info("[{$dsid}] Generating datastream.");

        // Get location of original PDF file. Ex: /tmp/processed/file_name_1234/author_name.PDF
        $source = $this->WORKING_DIR . "/" . $this->FILE_ETD;

        // Use convert (from ImageMagick tool suite) to generate PREVIEW document.
        // Execute 'convert' command and check return code.
        $command = "{$this->executable_convert} {$source} -quality 75 -resize 500x700 -colorspace RGB -flatten {$this->WORKING_DIR}/preview.jpg";
        exec($command, $output, $return);
        $this->logger->info("[{$dsid}] Running 'convert' command to build PREVIEW document.");
        // convert returns 0 on success.
        if ( $return == false ) {
            $this->logger->info("[{$dsid}] PREVIEW datastream generated successfully.");
        } else {
            $errorMessage = "PREVIEW document creation failed. " . $return;
            $this->datastreamIngestFailed($errorMessage, $dsid);
            throw new \Exception($errorMessage);
        }

        // Build Fedora object PREVIEW datastream.
        $datastream = $this->fedoraObj->constructDatastream($dsid);

        // Set various PREVIEW datastream values.
        $datastream->label = 'PREVIEW';
        $datastream->mimeType = 'image/jpeg';

        // Set PREVIEW datastream to be the generated preview image.
        $datastream->setContentFromFile($this->WORKING_DIR . "//preview.jpg");
        $this->logger->info("[{$dsid}] Selecting TN datastream to use: preview.jpg");

        try {
            $status = $this->manageIngestDatastream($datastream, $dsid);
        } catch(Exception $e) {
            // Bubble up exception.
            throw $e;
        }

        return $status;
    }

    /**
     * Create the RELS-INT datastream.
     * 
     * @return boolean Success value.
     * 
     * @throws Exception if the datastream ingest failed.
     */
    public function datastreamRELSINT() {
        $dsid = "RELS-INT";
        $this->logger->info("[{$dsid}] Generating datastream.");
        $this->logger->info("[{$dsid}] Reading in custom RELS XSLT file...");

        // $this->OA is either '0' for no OA policy, or some non-zero value.
        $relsint = '';
        $relsFile = "";
        if ( $this->OA === '0' ) {
            // No OA policy.
            $relsFile = "xsl/permRELS-INT.xml";
            $relsint = file_get_contents($relsFile);

            // Check if file read failed.
            if ( $relsint === false ) {
                $errorMessage = "Could not read in file: " . $relsFile;
                $this->datastreamIngestFailed($errorMessage, $dsid);
                throw new \Exception($errorMessage);
            }

            $relsint = str_replace('######', $this->PID, $relsint);

            $this->logger->info("[{$dsid}] No OA policy for ETD: read in: {$relsFile}");
        } else if ( isset($this->EMBARGO) === true ) {
            // Has an OA policy, and an embargo date.
            $relsFile = "xsl/embargoRELS-INT.xml";
            $relsint = file_get_contents($relsFile);

            // Check if file read failed.
            if ( $relsint === false ) {
                $errorMessage = "Could not read in file: " . $relsFile;
                $this->datastreamIngestFailed($errorMessage, $dsid);
                throw new \Exception($errorMessage);
            }

            $relsint = str_replace('######', $this->PID, $relsint);
            $relsint = str_replace('$$$$$$', (string)$this->EMBARGO, $relsint);

            $this->logger->info("[{$dsid}] OA policy found and Embargo date found for ETD: read in: {$relsFile}");
        }

        // TODO: handle case where there is an OA policy and no embargo date?

        // Ingest datastream if we have a XACML policy set.
        // INFO: isset() returns true if var exists and has any value other than null. false otherwise.
        if ( (isset($relsint) === true) && ($relsint !== '') ) {
            $dsid = "RELS-INT";

            // Build Fedora object RELS-INT datastream.
            $datastream = $this->fedoraObj->constructDatastream($dsid);

            // Set various RELS-INT datastream values.
            $datastream->label = 'Fedora Relationship Metadata';
            $datastream->mimeType = 'application/rdf+xml';

            // Set RELS-INT datastream to be the custom XACML policy file read in above.
            $datastream->setContentFromString($relsint);
            $this->logger->info("[{$dsid}] Selecting fire for datastream: {$relsFile}");

            try {
                $status = $this->manageIngestDatastream($datastream, $dsid);
            } catch(Exception $e) {
                // Bubble up exception.
                throw $e;
            }

            return $status;
        }

        return false;
    }
}

?>