<?php

error_reporting(E_ALL);

/**
 * Description of newPHPClass
 *
 * @author MEUSEB
 */

require_once '../tuque/RepositoryConnection.php';
require_once '../tuque/FedoraApi.php';
require_once '../tuque/FedoraApiSerializer.php';
require_once '../tuque/Repository.php';
require_once '../tuque/RepositoryException.php';
require_once '../tuque/FedoraRelationships.php';
require_once '../tuque/Cache.php';
require_once '../tuque/HttpConnection.php';
require_once 'proquestFTP.php';

define('ISLANDORA_BC_ROOT_PID', 'bc-ir:GraduateThesesCollection');
define('ISLANDORA_BC_ROOT_PID_EMBARGO', 'bc-ir:GraduateThesesCollectionRestricted');
define('ISLANDORA_BC_XACML_POLICY','POLICY');
define('GRADUATE_THESES','bc-ir:GraduateThesesCollection');
define('GRADUATE_THESES_RESTRICTED','bc-ir:GraduateThesesCollectionRestricted');


class processProquest {

    protected $ftp;
    protected $localFiles;
    public $settings;
    protected $connection;
    protected $api;
    protected $api_m;
    protected $repository;
    protected $toProcess = 0;
    
    public function __construct($config){ 
        $this->settings = parse_ini_file($config, true);
    } 

    /**
     * 
     * @param type $settings
     */
    function initFTP() {
        
        $urlFTP = $this->settings['ftp']['server'];
        $userFTP = $this->settings['ftp']['user'];
        $passwordFTP = $this->settings['ftp']['password'];
        
        $this->ftp = new proquestFTP($urlFTP);
        //set session time out default is 90
        $this->ftp->ftp_set_option(FTP_TIMEOUT_SEC, 30);
        
        $this->ftp->ftp_login($userFTP, $passwordFTP); 

    }
    

    /**
     * 
     * @param type $settings
     */
    function getFiles() {
        
        $fetchdirFTP = $this->settings['ftp']['fetchdir'];
        $localdirFTP = $this->settings['ftp']['localdir'];
        
        if ($fetchdirFTP != "")
        {
            $this->ftp->ftp_chdir($fetchdirFTP);            
        }
        
        $etdFiles = $this->ftp->ftp_nlist("etdadmin_upload*");

        
        foreach ($etdFiles as $filename) {

            $etdDir = $localdirFTP . substr($filename,0,strlen($filename)-4);
            
            mkdir($etdDir, 0755);
            $localFile = $etdDir . "/" .$filename;
            $this->ftp->ftp_get($localFile, $filename, FTP_BINARY);
            
            // Store location
            if(isset($this->localFiles[$etdDir])){$this->localFiles[$etdDir];}
            
            $supplement = 0;
            $ziplisting = zip_open($localFile);
            while ($zip_entry = zip_read($ziplisting)) {
                $file = zip_entry_name($zip_entry);
                                
                if (preg_match('/0016/', $file)) { // ETD or Metadata 0016 is BC code
                    if (substr($file,strlen($file)-3) === 'pdf') {
                        $this->localFiles[$etdDir]['ETD'] = $file;
                    } elseif (substr($file,strlen($file)-3) === 'xml') {
                        $this->localFiles[$etdDir]['METADATA'] = $file;
                    } else {
                        /**
                        * Supplementary files - could be permissions or data
                        * Metadata will contain boolean key for permission in 
                        * DISS_file_descr element
                         * [0] element should always be folder
                        */
                        $this->localFiles[$etdDir]['UNKNOWN'.$supplement] = $file;
                        $supplement++;
                    }
                }
            }
                       
            $zip = new ZipArchive;
                       
            $zip->open($localFile);
            $zip->extractTo($etdDir);
            $zip->close();

        }
    }
    
    /**
     * 
     */
    function processFiles() {

        $xslt = new xsltProcessor;
        $proquestxslt = new DOMDocument();
        $proquestxslt->load($this->settings['xslt']['xslt']);
        $xslt->importStyleSheet($proquestxslt);
        
        $label = new xsltProcessor;
        $labelxslt = new DOMDocument();
        $labelxslt->load($this->settings['xslt']['label']);
        $label->importStyleSheet($labelxslt);
        
        foreach ($this->localFiles as $directory => $submission) {
            /**
             * Generate MODS Metadata first
             * Done for every submission regardless of permissions
             */
            
            $metadata = new DOMDocument();
            $metadata->load($directory . '//' . $submission['METADATA']);

            $xpath = new DOMXpath($metadata);

            // Get Permissions 
            $oaElements = $xpath->query($this->settings['xslt']['oa']);
            if ($oaElements->length === 0 )
            {
                $openaccess = 0;
            } elseif ($oaElements->item(0)->C14N() === '0') {
                $openaccess = 0;
            } else {
                $openaccess = $oaElements->item(0)->C14N();
            }

            $this->localFiles[$directory]['OA'] = $openaccess;

            $embargo = 0;
            $emElements = $xpath->query($this->settings['xslt']['embargo']);
            if ($emElements->item(0) ) {
                $embargo = $emElements->item(0)->C14N(); 
                $embargo = str_replace(" ","T",$embargo);
                $embargo = $embargo . "Z";
                $this->localFiles[$directory]['EMBARGO'] = $embargo;
            }

            if ($openaccess === $embargo)
            {
                $embargo = 'indefinite';
                $this->localFiles[$directory]['EMBARGO'] = $embargo;
            }
            
            // Load to DOM so we can extract data from MODS
            // Pass PID to XSLT so we can add handle value to MODS
            $pid = $this->api_m->getNextPid($this->settings['fedora']['namespace'], 1);
            
            $this->localFiles[$directory]['PID'] = $pid;
            $xslt->setParameter('mods', 'handle', $pid);
            $mods = $xslt->transformToDoc($metadata );
            
            // Use mods:titleInfo for Fedora Label
            $fedoraLabel = $label->transformToXml($mods);
            $this->localFiles[$directory]['LABEL'] = $fedoraLabel;
            
            $xpathAuthor = new DOMXpath($mods);
            $authorElements = $xpathAuthor->query($this->settings['xslt']['creator']);      
            $author = $authorElements->item(0)->C14N();
            
            $normalizedAuthor = str_replace(array(" ",",","'",".","&apos;"), array("-","","","",""), $author);
            // TO DO: Need to add unicode replacements
            
            // Placeholders
            $this->localFiles[$directory]['FULLTEXT'] = $normalizedAuthor . ".txt";
            
            // Rename Proquest PDF to BC standard and update file lookup
            rename($directory . "/". $submission['ETD'] , $directory . "/" . $normalizedAuthor . ".pdf");
            $this->localFiles[$directory]['ETD'] = $normalizedAuthor . ".pdf";
            
            // Save MODS and add to lookup
            $mods->save($directory . "/" . $normalizedAuthor . ".xml");
            $this->localFiles[$directory]['MODS'] = $normalizedAuthor . ".xml";
            
            // Check for supplemental files
            // UNKNOWN0 in lookup should mean there are other files
            // also, Proquest MD will have DISS_attachment
            // ($this->localFiles[$directory]['UNKNOWN0']) or
            $suppxpath = new DOMXpath($metadata);

            $suElements = $suppxpath->query($this->settings['xslt']['supplement']);
            if ($suElements->item(0) ) {
                $this->localFiles[$directory]['PROCESS'] = "0";
            } else {
                // keep track of how many pids we will need to grab
                $this->localFiles[$directory]['PROCESS'] = "1";
                $this->toProcess++;
            }
        }
    }
    
    function initFedoraConnection() {
                
        $this->connection = new RepositoryConnection($this->settings['fedora']['url'],
                                                     $this->settings['fedora']['username'],
                                                     $this->settings['fedora']['password']);        
        
        $this->api = new FedoraApi($this->connection);
        $this->repository = new FedoraRepository($this->api, new simpleCache());
        
        $this->api_m = $this->repository->api->m; //  Management API.        
        
    }
    /**
     * 
     */
    function ingest() {
        
        $pidcount = 0;
        $fop = '../../modules/boston_college/data/fop/cfg.xml';
        $message = "The following ETDs were ingested\n\n";
        
        foreach ($this->localFiles as $directory => $submission) {
                        
            if ($this->localFiles[$directory]['PROCESS'] === '1') {
                // Still Load - but notify admin about supp files
            }

                $object = $this->repository->constructObject($this->localFiles[$directory]['PID']);    

                $object->label = $this->localFiles[$directory]['LABEL'];
                
                $object->owner = 'fedoraAdmin';
                /**
                 * Generate RELS-EXT
                 */


                // POLICY Get Parent POLICY to add to all ingested records
                $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID);

                $collection = GRADUATE_THESES;
                if (isset($this->localFiles[$directory]['EMBARGO']))
                {
                    $collection = GRADUATE_THESES_RESTRICTED;
                    $parentObject = $this->repository->getObject(ISLANDORA_BC_ROOT_PID_EMBARGO);
                }
                $object->models = array('bc-ir:graduateETDCModel');
                $object->relationships->add(FEDORA_RELS_EXT_URI, 
                                            'isMemberOfCollection',
                                            $collection);

                $object->checksumType = 'SHA-256';
                
                $object->state = 'I';

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
                exec($command);
                
                $this->localFiles[$directory]['SPLASH'] = 'splash.pdf';
                
                /**
                 * Load Splash to PDF if under embargo
                 */

               $executable = '/usr/bin/pdftk';
                    
               $concattemp = $directory . "/concatted.pdf";
               $pdf = $directory . "//" . $this->localFiles[$directory]['ETD'];
                    
               $command = "$executable $splashtemp $pdf cat output $concattemp";
               exec($command);
                    
               $datastream->label = 'PDF Datastream';
               $datastream->mimeType = 'application/pdf';
               $datastream->setContentFromFile($concattemp);
                    
               $datastream->checksumType = 'SHA-256';
                    
               $object->ingestDatastream($datastream);

      
                /**
                 * FULL_TEXT
                 */   
                $dsid = "FULL_TEXT";
                
                $source = $directory . "/" . $this->localFiles[$directory]['ETD'];
                    
                $executable = '/usr/bin/pdftotext';
                $fttemp = $directory . "/fulltext.txt";
                
                $command = "$executable $source $fttemp";
                exec($command);
               
                $datastream = $object->constructDatastream($dsid);
                             
                $datastream->label = 'FULL_TEXT';
                $datastream->mimeType = 'text/plain';
                
                // Read in FT and strip junky characters that mess up SOLR
                $fulltext = file_get_contents($fttemp);
                
                $replacement = '';
                $sanitized = preg_replace('/[\x00-\x1f]/', $replacement, $fulltext);
                
                $datastream->setContentFromString($sanitized);
                
                $object->ingestDatastream($datastream);
                
                /**
                 * TN
                 */   
                $dsid = "TN";
                
                $source = $directory . "/" . $this->localFiles[$directory]['ETD'] . "[0]";
                    
                $executable = '/usr/bin/convert';

                $command = "$executable $source -quality 75 -resize 200x200 -colorspace RGB -flatten " . $directory . "/thumbnail.jpg";
                exec($command);
               
                $datastream = $object->constructDatastream($dsid);
                             
                $datastream->label = 'TN';
                $datastream->mimeType = 'image/jpeg';
                $datastream->setContentFromFile($directory . "//thumbnail.jpg");
                
                $object->ingestDatastream($datastream);
                
                /**
                 * PREVIEW
                 */   
                $dsid = "PREVIEW";
                
                $source = $directory . "/" . $this->localFiles[$directory]['ETD'] . "[0]";
                    
                $executable = '/usr/bin/convert';

                $command = "$executable $source -quality 75 -resize 500x700 -colorspace RGB -flatten " . $directory . "/preview.jpg";
                exec($command);
               
                $datastream = $object->constructDatastream($dsid);
                             
                $datastream->label = 'PREVIEW';
                $datastream->mimeType = 'image/jpeg';
                $datastream->setContentFromFile($directory . "//preview.jpg");
                
                $object->ingestDatastream($datastream);                
                
                // POLICY
                $object->ingestDatastream($policy);  
                
                /**
                * Check if OA
                * Set Embargo is there is one
                * Permanent?
                */

                //$relsint = '';
                if ($submission['OA'] === 0) {
                    $relsint =  file_get_contents('xsl/permRELS-INT.xml');
                    $relsint = str_replace('######', $submission['PID'], $relsint);

                } elseif (isset($submission['EMBARGO'])) {
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
                }

                $this->repository->ingestObject($object);

                $pidcount++;
                $message .= $submission['PID'] . "\t";
                if (isset($submission['EMBARGO']))
                {
                    $message .= "EMBARGO UNTIL: " . $submission['EMBARGO'] . "\t";
                } else{
                    $message .= "NO EMBARGO" . "\t";
                }
                $message .= $submission['LABEL'] . "\n";

                
        }
        mail($this->settings['notify']['email'],"Message from processProquest",$message);
    }

}
?>
