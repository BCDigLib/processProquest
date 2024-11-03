<?php declare(strict_types=1);
namespace Processproquest\test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use \Mockery;

use phpmock\mockery\PHPMockery;
use phpmock\phpunit\PHPMock;

// Use TestHelpers class.
require_once(__DIR__ . "/helpers.php");

#[CoversClass(\Processproquest\Record\FedoraRecord::class)]
#[CoversMethod(\Processproquest\Record\FedoraRecord::class, "setStatus")]
final class FedoraRecordTest extends TestCase {

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->helper = new \Processproquest\test\TestHelpers("test");
        $this->configurationFile = $configurationFile;
        $this->configurationSettings = $this->helper->readConfigurationFile($configurationFile);
        $this->nameSpace = "bc-ir";
        $this->nextPIDNumber = "123456789";
        $this->mockPID = "{$this->nameSpace}:{$this->nextPIDNumber}";

        $this->logger = $this->helper->createLogger($this->configurationSettings);
        $this->fedoraConnection = $this->helper->createMockFedoraConnection($this->configurationSettings);
        $this->ftpConnection = $this->helper->createMockFTPConnection();
    }

    protected function tearDown(): void {
        \Mockery::close();
        $this->helper = null;
    }

    /**
     * Create a generic FedoraRecord object.
     * 
     * @param string $zipFileName The file name of a ETD zip file.
     * @param array $customSettings Optional array of settings.
     * 
     * @return object A FedoraRecord object.
     */
    protected function createFedoraRecordObject($zipFileName, $customSettings = []) {
        if (empty($customSettings)) {
            $customSettings = $this->configurationSettings;
        }
        // Create a FedoraRecord object.
        $fr = new \Processproquest\Record\FedoraRecord(
                        $zipFileName,               // ETD short name
                        $customSettings,            // settings array
                        $zipFileName,               // name of ETD zip file
                        $this->fedoraConnection,    // mock FedoraRepository object
                        $this->ftpConnection,       // mock ProquestFTP object
                        $this->logger               // logger object
                    );

        return $fr;
    }

    #[Test]
    #[TestDox('Checks the getProperty() method')]
    public function getProperty(): void {
        // Create a FedoraRecord object.
        $fedoraRecord = $this->createFedoraRecordObject("foo.zip");

        // Get the current status value.
        $status = $fedoraRecord->getProperty("STATUS");

        // Update status value.
        $newStatusValue = "hello";
        $fedoraRecord->setStatus("hello");

        // Get the updated status value.
        $result = $fedoraRecord->getProperty("STATUS");
        
        $this->assertEquals($newStatusValue, $result, "Expected getProperty() to set the value of STATUS to be {$newStatusValue}");
    }

    #[Test]
    #[TestDox('Checks the getProperty() method for a non-existent property')]
    public function getPropertyNonExistentProperty(): void {
        // Create a FedoraRecord object.
        $fedoraRecord = $this->createFedoraRecordObject("foo.zip");

        // Get the value of a non-existent property.
        $result = $fedoraRecord->getProperty("FOOBAR");
        
        $this->assertNull($result, "Expected getProperty() to return null on a non-existent property");
    }

    #[Test]
    #[TestDox('Checks the setStatus() method')]
    public function setStatus(): void {
        // Create a FedoraRecord object.
        $fedoraRecord = $this->createFedoraRecordObject("foo.zip");

        // Get the current status value.
        $status = $fedoraRecord->getProperty("STATUS");

        // Update status value.
        $newStatusValue = "hello";
        $fedoraRecord->setStatus("hello");

        // Get the updated status value.
        $result = $fedoraRecord->getProperty("STATUS");
        
        $this->assertEquals($newStatusValue, $result, "Expected setStatus() to set the value of STATUS to be {$newStatusValue}");
    }
    
    #[Test]
    #[TestDox('Checks the setFTPPostprocessLocation() method')]
    public function setFTPPostprocessLocation(): void {
        // Create a FedoraRecord object.
        $fedoraRecord = $this->createFedoraRecordObject("foo.zip");

        // Get the current FTP_POSTPROCESS_LOCATION value.
        $status = $fedoraRecord->getProperty("FTP_POSTPROCESS_LOCATION");

        // Update FTP_POSTPROCESS_LOCATION value.
        $newValue = "hello";
        $fedoraRecord->setFTPPostprocessLocation("hello");

        // Get the updated FTP_POSTPROCESS_LOCATION value.
        $result = $fedoraRecord->getProperty("FTP_POSTPROCESS_LOCATION");
        
        $this->assertEquals($newValue, $result, "Expected setFTPPostprocessLocation() to set the value of FTP_POSTPROCESS_LOCATION to be {$newValue}");
    }

    #[Test]
    #[TestDox('Checks the downloadETD() method')]
    public function downloadETD(): void {
        // Process file
        // $process->processFile($fedoraRecord);
        //      $fedoraRecordObj->downloadETD();  <--- this test
        //          creates workingdir directory
        //          fetches file from FTP server
        //      parseETD()
        //      processETD()
        //      generateDatastreams()
        //      ingestETD()

        // ETD File name.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $result = $fedoraRecord->downloadETD();
        
        $this->AssertTrue($result, "Expected downloadETD() to return true");

        $updatedStatusProperty = $fedoraRecord->getProperty("STATUS");

        $this->AssertEquals("downloaded", $updatedStatusProperty, "Expected downloadETD() to set the status to 'downloaded'");
    }

    #[Test]
    #[TestDox('Checks the downloadETD() method throws an exception when it fails to download the file')]
    public function downloadETDFailToDownloadFile(): void {
        // ETD File name.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "fetchdir" key with the path to tests/files/.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will return false. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->andReturn(false);

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        // Expect an exception.
        $this->expectException(\Exception::class);
        $result = $fedoraRecord->downloadETD();
    }

    #[Test]
    #[TestDox('Checks the parseETD() method')]
    public function parseETD(): void {
        // Process file
        // $process->processFile($fedoraRecord);
        //      $fedoraRecordObj->downloadETD();
        //      parseETD()  <--- this test
        //          Expand zip file contents into local directory
        //          Check for supplementary files in each ETD zip file.
        //      processETD()
        //      generateDatastreams()
        //      ingestETD()

        // etdadmin_upload_001_normal.zip is a well formatted zip file.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $result = $fedoraRecord->parseETD();
        
        $this->AssertTrue($result, "Expected parseETD() to return true");

        $updatedStatusProperty = $fedoraRecord->getProperty("STATUS");

        $this->AssertEquals("success", $updatedStatusProperty, "Expected parseETD() to set the status to 'downloaded'");
    }

    #[Test]
    #[TestDox('Checks the parseETD() method for a zip file with supplemental files')]
    public function parseETDWithSupplementalFiles(): void {
        // etdadmin_upload_003_supplemental.zip contains supplemental files.
        $zipFileName = "etdadmin_upload_003_supplemental.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $result = $fedoraRecord->parseETD();
        
        // This method should return false when it encounters a zip file with supplemental files.
        $this->AssertNotTrue($result, "Expected parseETD() to return false");

        $updatedStatusProperty = $fedoraRecord->getProperty("STATUS");

        $this->AssertEquals("skipped", $updatedStatusProperty, "Expected parseETD() to set the status to 'skipped'");
    }

    #[Test]
    #[TestDox('Checks the parseETD() method throws an exception when it attempts to open a bad zip file')]
    public function parseETDBadZipFile(): void {
        // not_a_real_zip_file.zip is a text file labeled as a zip file.
        $zipFileName = "not_a_real_zip_file.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();

        // Expect an exception.
        $this->expectException(\Processproquest\Record\RecordProcessingException::class);
        $result = $fedoraRecord->parseETD();
    }

    #[Test]
    #[TestDox('Checks the parseETD() method throws an exception when it attempts to open an empty zip file')]
    public function parseETDEmptyZipFile(): void {
        // etdadmin_upload_004_empty.zip is an empty zip file.
        $zipFileName = "etdadmin_upload_004_empty.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();

        // Expect an exception.
        $this->expectException(\Exception::class);
        $result = $fedoraRecord->parseETD();
    }

    #[Test]
    #[TestDox('Checks the parseETD() method throws an exception when files lack the 0016 BC identifier string')]
    public function parseETDZipFilesWithMalformedNames(): void {
        // etdadmin_upload_005_bad_id.zip is a clone of etdadmin_upload_001_normal.zip 
        // but its files lack the 0016 BC identifier string.
        $zipFileName = "etdadmin_upload_005_bad_id.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();

        // Expect an exception.
        $this->expectException(\Exception::class);
        $result = $fedoraRecord->parseETD();
    }

    #[Test]
    #[TestDox('Checks the parseETD() method throws an exception when missing a XML file')]
    public function parseETDNoXMLZipFile(): void {
        // etdadmin_upload_006_no_xml.zip is missing a XML file.
        $zipFileName = "etdadmin_upload_006_no_xml.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();

        // Expect an exception.
        $this->expectException(\Exception::class);
        $result = $fedoraRecord->parseETD();
    }

    #[Test]
    #[TestDox('Checks the parseETD() method throws an exception when missing a PDF file')]
    public function parseETDNoPDFZipFile(): void {
        // etdadmin_upload_007_no_pdf.zip is missing a PDF file.
        $zipFileName = "etdadmin_upload_007_no_pdf.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();

        // Expect an exception.
        $this->expectException(\Exception::class);
        $result = $fedoraRecord->parseETD();
    }

    #[Test]
    #[TestDox('Checks the processETD() method')]
    public function processETD(): void {
        // Process file
        // $process->processFile($fedoraRecord);
        //      $fedoraRecordObj->downloadETD();
        //      parseETD()
        //      processETD()  <--- this test
        //          This will generate:
        //          *  - OA permissions.
        //          *  - Embargo settings.
        //          *  - MODS metadata.
        //          *  - PID, title, author values.
        //      generateDatastreams()
        //      ingestETD()

        // etdadmin_upload_001_normal.zip is a well formatted zip file.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        // Additionally:
        // Replace [script] "debug" key with false.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
            "script" => array("debug" => false),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock FedoraRepository connection object using the RepositoryInterface interface.
        // Set getNextPid() to return a known value.
        $mockFedoraRepositoryConnection = Mockery::mock(\Processproquest\Repository\RepositoryInterface::class)->makePartial();
        $mockFedoraRepositoryConnection->shouldReceive('getNextPid')->andReturn($this->mockPID);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $mockFedoraRepositoryConnection,// mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $result = $fedoraRecord->processETD();
        
        $this->assertTrue($result, "Expected processETD() to return true");

        // Check that the status has been set to "processed"
        $updatedStatusProperty = $fedoraRecord->getProperty("STATUS");
        $this->assertEquals("processed", $updatedStatusProperty, "Expected processETD() to set the status to 'processed'");

        // Check that the returned PID is that same as $this->mockPID
        $updatedPID = $fedoraRecord->getProperty("PID");
        $this->assertEquals($this->mockPID, $updatedPID, "Expected processETD() to set the PID to {$this->mockPID}");

        // TODO: check that HAS_EMBARGO is false.
        // TODO: check that OA_AVAILABLE is true.
        // TODO: check that HAS_SUPPLEMENTS is false.
    }

    #[Test]
    #[TestDox('Checks the processETD() method with an embargoed record')]
    public function processETDWithEmbargo(): void {
        // etdadmin_upload_002_embargoed.zip contains an embargoed record.
        $zipFileName = "etdadmin_upload_002_embargoed.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock FedoraRepository connection object using the RepositoryInterface interface.
        // Set getNextPid() to return a known value.
        $mockFedoraRepositoryConnection = Mockery::mock(\Processproquest\Repository\RepositoryInterface::class)->makePartial();
        $mockFedoraRepositoryConnection->shouldReceive('getNextPid')->andReturn($this->mockPID);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $mockFedoraRepositoryConnection,// mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $result = $fedoraRecord->processETD();
        
        $this->assertTrue($result, "Expected processETD() to return true");

        // Check that the status has been set to "processed"
        $updatedStatusProperty = $fedoraRecord->getProperty("STATUS");
        $this->assertEquals("processed", $updatedStatusProperty, "Expected processETD() to set the status to 'processed'");

        // Check that this record has a HAS_EMBARGO value of true
        $hasEmbargo = $fedoraRecord->getProperty("HAS_EMBARGO");
        $this->assertTrue($hasEmbargo, "Expected this record to have the property HAS_EMBARGO to be true");
    }

    #[Test]
    #[TestDox('Checks the processETD() method with supplemental files returns false')]
    public function processETDWithSupplemental(): void {
        // etdadmin_upload_003_supplemental.zip is embargoed.
        $zipFileName = "etdadmin_upload_003_supplemental.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $result = $fedoraRecord->processETD();
        
        $this->AssertNotTrue($result, "Expected processETD() to return false");

        // TODO: check that HAS_SUPPLEMENTS is true.
    }

    #[Test]
    #[TestDox('Checks the processETD() method throws an exception when missing xslt file')]
    public function processETDExceptionMissingXSLTFile(): void {
        // etdadmin_upload_001_normal.zip is a well formatted zip file.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        // Additionally:
        // Replace [xslt] "xslt" key with a non-existent file location.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
            "xslt" => array("xslt" => "/opt/BC-Islandora-Implementation/MetadataCrosswalks/Proquest/Proquest_MODS-Foo.xsl"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        // Expect an exception.
        $this->expectException(\Exception::class);

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();

        // Suppress warning by using @ error control operator.
        $result = @$fedoraRecord->processETD();
    }

    #[Test]
    #[TestDox('Checks the processETD() method throws an exception when missing label file')]
    public function processETDExceptionMissingLabelFile(): void {
        // etdadmin_upload_001_normal.zip is a well formatted zip file.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        // Additionally:
        // Replace [xslt] "label" key with a non-existent file location.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
            "xslt" => array("label" => "xsl/getLabel-Foo.xsl"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        // Expect an exception.
        $this->expectException(\Exception::class);

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();

        // Suppress warning by using @ error control operator.
        $result = @$fedoraRecord->processETD();
    }

    #[Test]
    #[TestDox('Checks the processETD() method has an indefinite embargo date when there is no OA agreement')]
    public function processETDIndefiniteEmbargo(): void {
        // etdadmin_upload_008_no_oa.zip doesn't have an OA agreement.
        $zipFileName = "etdadmin_upload_008_no_oa.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $this->fedoraConnection,        // mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $result = $fedoraRecord->processETD();

        $embargoDate = $fedoraRecord->getProperty("EMBARGO_DATE");

        $this->assertEquals("indefinite", $embargoDate, "Expected processETD() to set the EMBARGO_DATE to 'indefiniete'");
    }

    // TODO: overload $this->FILE_METADATA with empty string to trigger an exception around line 448.
    // TODO: overload $this->FILE_METADATA with the location of an empty file to trigger an exception around line 596.
    // TODO: overload $this->settings['xslt']['creator'] with a bad XPath value to force a false value around line 613.
    // TODO: find how to force XSLTProcessor::importStylesheet() to return false (lines 408, 435)

    #[Test]
    #[TestDox('Checks the generateDatastreams() method with a record with supplements')]
    public function generateDatastreamsWithEmbargo(): void {
        // etdadmin_upload_003_supplemental.zip contains supplements.
        $zipFileName = "etdadmin_upload_003_supplemental.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a custom mock FedoraRepository connection object using the RepositoryInterface interface.
        // Set getNextPid() to return a known value.
        $mockFedoraRepositoryConnection = Mockery::mock(\Processproquest\Repository\RepositoryInterface::class)->makePartial();
        $mockFedoraRepositoryConnection->shouldReceive('getNextPid')->andReturn($this->mockPID);

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $mockFedoraRepositoryConnection,// mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $fedoraRecord->processETD();
        $result = $fedoraRecord->generateDatastreams();
        
        $this->assertNotTrue($result, "Expected generateDatastreams() to return false");
    }

    #[Test]
    #[TestDox('Checks the generateDatastreams() method returns an Exception when the record can\'t be found')]
    public function generateDatastreamsGetObjectFail(): void {
        // etdadmin_upload_001_normal.zip is a valid ETD file.
        $zipFileName = "etdadmin_upload_001_normal.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        $genericObject = new \stdClass();

        // Create a custom mock FedoraRepository connection object using the RepositoryInterface interface.
        // Set getNextPid() to return a known value.
        // Set constructObject() to return a generic object.
        // Set getObject() to throw an exception.
        $mockFedoraRepositoryConnection = Mockery::mock(\Processproquest\Repository\RepositoryInterface::class)->makePartial();
        $mockFedoraRepositoryConnection->shouldReceive('getNextPid')->andReturn($this->mockPID);
        $mockFedoraRepositoryConnection->shouldReceive('constructObject')->andReturn($genericObject);
        $mockFedoraRepositoryConnection->shouldReceive('getObject')->once()->andThrow(new \Processproquest\Repository\PPRepositoryException("FOO"));

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $mockFedoraRepositoryConnection,// mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $fedoraRecord->processETD();

        // Expect an exception.
        $this->expectException(\Processproquest\Record\RecordProcessingException::class);
        $fedoraRecord->generateDatastreams();
    }

    #[Test]
    #[TestDox('Checks the generateDatastreams() method returns an Exception when the record ISLANDORA_BC_ROOT_PID_EMBARGO can\'t be found')]
    public function generateDatastreamsGetObjectFail2(): void {
        // etdadmin_upload_002_embargoed.zip has an embargo.
        $zipFileName = "etdadmin_upload_002_embargoed.zip";

        // ETD shortname.
        $etdShortName = substr($zipFileName,0,strlen($zipFileName)-4);

        // We will tell the mock ProquestFTP class to look for files in the tests/files/ directory.
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("fetchdir" => __DIR__ . "/files/"),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        $genericObject = new \stdClass();

        // Create a custom mock FedoraRepository connection object using the RepositoryInterface interface.
        // Set getNextPid() to return a known value.
        // Set constructObject() to return a generic object.
        // Set getObject() to throw an exception.
        $mockFedoraRepositoryConnection = Mockery::mock(\Processproquest\Repository\RepositoryInterface::class)->makePartial();
        $mockFedoraRepositoryConnection->shouldReceive('getNextPid')->andReturn($this->mockPID);
        $mockFedoraRepositoryConnection->shouldReceive('constructObject')->andReturn($genericObject);
        $mockFedoraRepositoryConnection->shouldReceive('getObject')->andReturnUsing(
            function () {
                static $counter = 0;
    
                switch ($counter++) {
                    case 0:
                        return new \stdClass();
                        break;
                    case 1:
                        throw new \Processproquest\Repository\PPRepositoryException("FOO");
                        break;
                    default:
                        return new \stdClass();
                        break;
                }
            }
        );

        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        // The getFile() method will directly copy the file into the working directory and pass that command's result back. 
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('getFile')->once()->andReturnUsing(
            function($local_filename, $remote_filename) {
                // Return the copy() function's return value.
                return copy($remote_filename, $local_filename);
            }
        );

        // Create a custom FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                $etdShortName,                  // ETD short name
                                $newSettings,                   // custom settings array
                                $zipFileName,                   // name of ETD zip file
                                $mockFedoraRepositoryConnection,// mock FedoraRepository object
                                $mockProquestFTPConnection,     // custom mock ProquestFTP object
                                $this->logger                   // logger object
                            );

        $fedoraRecord->downloadETD();
        $fedoraRecord->parseETD();
        $fedoraRecord->processETD();

        // Expect an exception on the second call to getObject()
        $this->expectException(\Processproquest\Record\RecordProcessingException::class);
        $fedoraRecord->generateDatastreams();
    }
}