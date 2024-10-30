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
     * @return object a FedoraRecord object.
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
        $zipFileName = "etdadmin_upload_100000.zip";

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
        $zipFileName = "etdadmin_upload_100000.zip";

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

        // ETD File name.
        $zipFileName = "etdadmin_upload_100000.zip";

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

        $this->AssertEquals("success", $updatedStatusProperty, "Expected downloadETD() to set the status to 'downloaded'");
    }
}