<?php declare(strict_types=1);
namespace Processproquest\test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use \Mockery;

// Use TestHelpers class.
require_once(__DIR__ . "/helpers.php");

#[CoversClass(\Processproquest\Processproquest::class)]
#[UsesClass(\Processproquest\FTP\ProquestFTP::class)]
#[UsesClass(\Processproquest\Repository\FedoraRepository::class)]
#[UsesClass(\Processproquest\Record\FedoraRecordProcessor::class)]
#[CoversMethod(\Processproquest\Processproquest::class, "setFTPConnection")]
#[CoversMethod(\Processproquest\Processproquest::class, "setFedoraConnection")]
#[CoversMethod(\Processproquest\Processproquest::class, "setDebug")]
#[CoversMethod(\Processproquest\Processproquest::class, "LogIntoFTPServer")]
#[CoversMethod(\Processproquest\Processproquest::class, "scanForETDFiles")]
#[CoversMethod(\Processproquest\Processproquest::class, "createFedoraObjects")]
#[CoversMethod(\Processproquest\Processproquest::class, "createFedoraObject")]
#[CoversMethod(\Processproquest\Processproquest::class, "statusCheck")]
#[CoversMethod(\Processproquest\Processproquest::class, "processAllFiles")]
#[CoversMethod(\Processproquest\Processproquest::class, "appendAllFedoraRecordObjects")]
#[CoversMethod(\Processproquest\Processproquest::class, "moveFTPFiles")]
final class ProcessproquestTest extends TestCase {

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->helper = new  \Processproquest\test\TestHelpers("test");
        $this->configurationFile = $configurationFile;
        $this->configurationSettings = $this->helper->readConfigurationFile($configurationFile);
        $this->logger = $this->helper->createLogger($this->configurationSettings);
        $this->debug = true;
        $this->listOfETDs = $this->helper->getListOfSampleETDs();
    }

    protected function tearDown(): void {
        $this->helper = null;
        $this->logger = null;
        \Mockery::close();
    }

    #[Test]
    #[TestDox('Checks the setFTPConnection() method')]
    public function setFTPConnection(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();
        
        // Assert that the ftpConnection object is null.
        $property = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'ftpConnection');
        $this->assertNull($property->getValue($processObj), "Expected the ftpConnection object to be null.");

        // Set the ftpConnection object using the mock FTP connection.
        $processObj->setFTPConnection($mockFTPConnection);

        // Assert that the ftpConnection object is not null.
        $property = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'ftpConnection');
        $this->assertIsObject($property->getValue($processObj), "Expected the ftpConnection object to exist.");
    }

    #[Test]
    #[TestDox('Checks the setFedoraConnection() method')]
    public function setFedoraConnection(): void {
        // Create a mock fedoraConnection object.
        $this->fedoraConnection = $this->helper->createMockFedoraConnection();

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();
        
        // Assert that the fedoraConnection object is null.
        $property = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'fedoraConnection');
        $this->assertNull($property->getValue($processObj), "Expected the fedoraConnection object to be null.");

        // Set the fedoraConnection object using the mock Fedora connection.
        $processObj->setFedoraConnection($this->fedoraConnection);

        // Assert that the fedoraConnection object is not null.
        $property = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'fedoraConnection');
        $this->assertIsObject($property->getValue($processObj), "Expected the fedoraConnection object to exist.");
    }

    #[Test]
    #[TestDox('Checks the setDebug() method')]
    public function setDebug(): void {
        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Check that the debug property is set to true.
        $this->assertTrue($processObj->debug, "Expected the default debug property to be true");

        // Set the debug property to false.
        $processObj->setDebug(false);

        // Check that the debug property is set to false.
        $this->assertNotTrue($processObj->debug, "Expected the updated debug property to be false");
    }

    #[Test]
    #[TestDox('Checks the logIntoFTPServer() method returns successfully with valid credentials')]
    public function logIntoFTPServer(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        
        // Expect a true value.
        $result = $processObj->logIntoFTPServer();

        $this->assertTrue($result, "Expected logIntoFTPServer() to return true.");
    }

    #[Test]
    #[TestDox('Checks the logIntoFTPServer() method throws on error on login failure')]
    public function logIntoFTPServerFailure(): void {
        // Create a custom mock ftpConnection object that returns false on login().
        $mockFTPConnection = Mockery::mock(\Processproquest\FTP\ProquestFTP::class)->makePartial();
        $mockFTPConnection->shouldReceive('login')->andReturn(false);

        // Create a Processproquest object using a custom mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        
        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->logIntoFTPServer();
    }

    #[Test]
    #[TestDox('Checks the logIntoFTPServer() method throws on error when there is no user name provided')]
    public function logIntoFTPServerNoUsername(): void {
        // Replace [ftp] "user" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("user" => ""),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a mock ProquestFTP object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using the updated ProquestFTP object.
        $processObj = $this->helper->generateProcessproquestObject($newSettings);
        $processObj->setFTPConnection($mockFTPConnection);
        
        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->logIntoFTPServer();
    }

    // Incomplete.
    // This test throws an exception on setFTPConnection().
    #[TestDox('Checks the logIntoFTPServer() method returns an exception with an empty server URL value')]
    public function logIntoFTPServerConfigEmptyServerValue(): void {
        // Stop here and mark this test as incomplete.
        $this->markTestIncomplete(
            'This test is incomplete.',
        );

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("server" => ""),
        );
        $newSettings = $this->alterConfigurationSettings($updatedSettings);

        // Create a ftpConnection object with updated settings.
        $ftpConnection = $this->createFTPConnection($newSettings);

        // Create Processproquest object using the updated FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($ftpConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->logIntoFTPServer();
    }

    /**
     * TODO: rewrite this to use a mockFTPConnection.
     */
    //#[Test]
    #[TestDox('Checks the scanForETDFiles() method returns an exception with an invalid localdir value')]
    public function scanForETDFilesConfigEmptyLocaldirValue(): void {
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        $url = $newSettings['ftp']['server'];
        $ftpService = new \Processproquest\FTP\FTPServicePHPAdapter($url);

        // Create a ProquestFTP object with updated settings.
        $ftpConnection = $this->helper->createFTPConnection($newSettings);

        // Create a Processproquest object using the updated ProquestFTP object.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($ftpConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->scanForETDFiles();
    }

    #[Test]
    #[TestDox('Checks the scanForETDFiles() method returns a list of valid ETD zip files')]
    public function scanForETDFiles(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        $fileArray = $processObj->scanForETDFiles();

        $this->assertTrue($this->helper->arrays_are_similar($fileArray, $this->listOfETDs), "Expected the two arrays to match.");
    }

    #[Test]
    #[TestDox('Checks the scanForETDFiles() method replaces an empty fetchdirFTP property with a default value')]
    public function scanForETDFilesEmptyFetchdirFTPProperty(): void {
        $expectedValue = "~/";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Get protected property fetchdirFTP using reflection.
        $fetchdirFTPProperty = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'fetchdirFTP');

        // Set fetchdirFTP to be an empty string.
        $fetchdirFTPProperty->setValue($processObj, "");

        $fileArray = $processObj->scanForETDFiles();

        $this->assertEquals($expectedValue, $fetchdirFTPProperty->getValue($processObj));

    }

    #[Test]
    #[TestDox('Checks the scanForETDFiles() method when ProquestFTP->changeDir() returns false')]
    public function scanForETDFilesChangeDirReturnsFalse(): void {
        // Create a custom mock ftpConnection object that returns false for changeDir().
        $mockFTPConnection = Mockery::mock(\Processproquest\FTP\ProquestFTP::class)->makePartial();
        $mockFTPConnection->shouldReceive('changeDir')->andReturn(false);

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    #[Test]
    #[TestDox('Checks the scanForETDFiles() method returns an exception with this->settings[ftp][localdir] is empty')]
    public function scanForETDFilesRegexNoMatch(): void {
        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);

        // Create a mock ftpConnection object with an empty initial array of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject($newSettings);
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    #[Test]
    #[TestDox('Checks the scanForETDFiles() method returns an exception when there are no ETDs on the FTP server')]
    public function scanForETDFilesNoETDsFound(): void {
        // Create a mock ftpConnection object with an empty initial array of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection([]);

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    #[Test]
    #[TestDox('Checks the createFedoraObjects() method returns an array of FedoraRecord object')]
    public function createFedoraObjects(): void {
        // Create array containing a zip filename.
        $zipFileName = "etdadmin_upload_100000.zip";
        $listOfETDFiles = [];
        array_push($listOfETDFiles, $zipFileName);

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Get protected property allFoundETDs using reflection.
        $allFoundETDsProperty = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'allFoundETDs');

        // Set the allFoundETDs property.
        $allFoundETDsProperty->setValue($processObj, $listOfETDFiles);
        $createdFedoraRecords = $processObj->createFedoraObjects();
        $firstCreatedFedoraRecords = $createdFedoraRecords[0];

        // Check the class type for the first object returned by createFedoraObjects()
        $className = get_class($firstCreatedFedoraRecords);
        $this->assertEquals($className, "Processproquest\Record\FedoraRecordProcessor", "Expected the values 'Processproquest\Record\FedoraRecordProcessor' and '{$className}' to match.");

        // Check the FedoraRecordProcessor object name returned by createFedoraObjects()
        $etdZipFileName = $firstCreatedFedoraRecords->ZIP_FILENAME;
        $this->assertEquals($zipFileName, $etdZipFileName, "Expected the values '{$zipFileName}' and '{$etdZipFileName}' to match.");
    }

    #[Test]
    #[TestDox('Checks the createFedoraObject() method returns a single FedoraRecordProcessor object')]
    public function createFedoraObject(): void {
        // A sample zip filename.
        $zipFileName = "etdadmin_upload_100000.zip";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        $createdFedoraRecord = $processObj->createFedoraObject($zipFileName);
        $firstCreatedFedoraRecord = $createdFedoraRecord;

        // Check the class type for the first object returned by createFedoraObject()
        $className = get_class($firstCreatedFedoraRecord);
        $this->assertEquals($className, "Processproquest\Record\FedoraRecordProcessor", "Expected the values 'Processproquest\Record\FedoraRecordProcessor' and '{$className}' to match.");

        // Check the FedoraRecord object name returned by createFedoraObject()
        $etdZipFileName = $firstCreatedFedoraRecord->ZIP_FILENAME;
        $this->assertEquals($zipFileName, $etdZipFileName, "Expected the values '{$zipFileName}' and '{$etdZipFileName}' to match.");
    }

    #[Test]
    #[TestDox('Checks the createFedoraObjects() method returns an exception when there are no ETD zip files to process')]
    public function createFedoraObjectsZero(): void {
        // Create an array.
        $listOfZeroETDFiles = [];

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Get protected property allFoundETDs using reflection.
        $allFoundETDsProperty = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'allFoundETDs');

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $allFoundETDsProperty->setValue($processObj, $listOfZeroETDFiles);
        $listOfFedoraRecordObjects = $processObj->createFedoraObjects();
    }

    #[Test]
    #[TestDox('Checks the statusCheck() method containing processing errors')]
    public function statusCheckWithProcessingErrors(): void {
        $errorMessage = "This is an error: WXYZ";

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Get protected property processingErrors using reflection.
        $processingErrorsProperty = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'processingErrors');

        // Set the processingErrors property.
        $processingErrorsProperty->setValue($processObj, [$errorMessage]);

        // Get output of statusCheck() method.
        $message = $processObj->statusCheck();

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }

    #[Test]
    #[TestDox('Checks the statusCheck() method containing ETDs with supplemental files')]
    public function statusCheckWithSupplements(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecordProcessor(
            "etdadmin_upload_100000",       // ID
            $this->configurationSettings,   // settings
            "etdadmin_upload_100000.zip",   // zip file name
            $mockFedoraConnection,          // Fedora connection object
            $mockFTPConnection,             // FTP connection object
            $this->logger                   // logger object
        );

        $fedoraRecordObject->STATUS = "ingested";
        $fedoraRecordObject->HAS_SUPPLEMENTS = true;
        $fedoraRecordObject->OA_AVAILABLE = true;
        $fedoraRecordObject->HAS_EMBARGO = false;
        $fedoraRecordObject->PID = "bc-ir:9999999";

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() method.
        $message = $processObj->statusCheck();

        $expectedString = '/Has supplements:\s+true/';

        $this->assertMatchesRegularExpression($expectedString, $message, "Expecting the regular expression match '{$expectedString}' in the returned message.");
    }

    #[Test]
    #[TestDox('checks the statusCheck() method containing ETDs with an embargo')]
    public function statusCheckWithEmbargo(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecordProcessor(
            "etdadmin_upload_100000",       // ID
            $this->configurationSettings,   // settings
            "etdadmin_upload_100000.zip",   // zip file name
            $mockFedoraConnection,          // Fedora connection object
            $mockFTPConnection,             // FTP connection object
            $this->logger                   // logger object
        );

        $fedoraRecordObject->STATUS = "ingested";
        $fedoraRecordObject->HAS_SUPPLEMENTS = false;
        $fedoraRecordObject->OA_AVAILABLE = true;
        $fedoraRecordObject->HAS_EMBARGO = true;
        $fedoraRecordObject->EMBARGO_DATE = "indefinite";
        $fedoraRecordObject->PID = "bc-ir:9999999";
        $fedoraRecordObject->AUTHOR = "Foo";
        $fedoraRecordObject->RECORD_URL = "https://foo.bar";
        $fedoraRecordObject->LABEL = "etdadmin_upload_100000";

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() method.
        $message = $processObj->statusCheck();

        // Match with these regular expressions.
        $expectedString1 = '/Has embargo:\s+true/';
        $expectedString2 = '/Embargo date:\s+indefinite/';

        $this->assertMatchesRegularExpression($expectedString1, $message, "Expecting the regular expression match '{$expectedString1}' in the returned message.");
        $this->assertMatchesRegularExpression($expectedString2, $message, "Expecting the regular expression match '{$expectedString2}' in the returned message.");
    }

    #[Test]
    #[TestDox('Checks the statusCheck() method containing ETDs with a critical error')]
    public function statusCheckWithCriticalErrors(): void {
        $errorMessage = "This is a critical error: WXYZ";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecordProcessor(
            "etdadmin_upload_100000",       // ID
            $this->configurationSettings,                // settings
            "etdadmin_upload_100000.zip",   // zip file name
            $mockFedoraConnection,          // Fedora connection object
            $mockFTPConnection,             // FTP connection object
            $this->logger                   // logger object
        );

        $fedoraRecordObject->STATUS = "ingested";
        $fedoraRecordObject->HAS_SUPPLEMENTS = false;
        $fedoraRecordObject->OA_AVAILABLE = true;
        $fedoraRecordObject->HAS_EMBARGO = true;
        $fedoraRecordObject->EMBARGO_DATE = "indefinite";
        $fedoraRecordObject->PID = "bc-ir:9999999";
        $fedoraRecordObject->AUTHOR = "Foo";
        $fedoraRecordObject->RECORD_URL = "https://foo.bar";
        $fedoraRecordObject->LABEL = "etdadmin_upload_100000";
        $fedoraRecordObject->CRITICAL_ERRORS = [$errorMessage];

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() method.
        $message = $processObj->statusCheck();

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }
    
    #[Test]
    #[TestDox('Checks the statusCheck() method containing ETDs with a non-critical error')]
    public function statusCheckWithNonCriticalErrors(): void {
        $errorMessage = "This is a non-critical error: WXYZ";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecordProcessor(
            "etdadmin_upload_100000",       // ID
            $this->configurationSettings,                // settings
            "etdadmin_upload_100000.zip",   // zip file name
            $mockFedoraConnection,          // Fedora connection object
            $mockFTPConnection,             // FTP connection object
            $this->logger                   // logger object
        );

        $fedoraRecordObject->STATUS = "ingested";
        $fedoraRecordObject->HAS_SUPPLEMENTS = false;
        $fedoraRecordObject->OA_AVAILABLE = true;
        $fedoraRecordObject->HAS_EMBARGO = true;
        $fedoraRecordObject->EMBARGO_DATE = "indefinite";
        $fedoraRecordObject->PID = "bc-ir:9999999";
        $fedoraRecordObject->AUTHOR = "Foo";
        $fedoraRecordObject->RECORD_URL = "https://foo.bar";
        $fedoraRecordObject->LABEL = "etdadmin_upload_100000";
        $fedoraRecordObject->NONCRITICAL_ERRORS = [$errorMessage];

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() method.
        $message = $processObj->statusCheck();

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }

    #[Test]
    #[TestDox('Checks the processFile() method on a FedoraRecord object')]
    public function processFile(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object.
        $fedoraRecordObject = $this->helper->createMockFedoraRecord();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        $result = $processObj->processFile($fedoraRecordObject);

        $this->assertTrue($result, "Expected processAllFiles() to return true.");
    }

    #[Test]
    #[TestDox('Checks the processFile() method on a FedoraRecord object to throw an exception on downloadETD()')]
    public function processFileExceptionOnDownloadETD(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object that returns an exception on downloadETD().
        $fedoraRecordObject = Mockery::mock(\Processproquest\Record\FedoraRecordProcessor::class)->makePartial();
        $fedoraRecordObject->shouldReceive('downloadETD')->andThrow(new \ProcessProquest\ProcessingException);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->processFile($fedoraRecordObject);
    }

    #[Test]
    #[TestDox('Checks the processFile() method on a FedoraRecord object to throw an exception on parseETD()')]
    public function processFileExceptionOnParseETD(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object that returns an exception on parseETD().
        $fedoraRecordObject = Mockery::mock(\Processproquest\Record\FedoraRecordProcessor::class)->makePartial();
        $fedoraRecordObject->shouldReceive('downloadETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('parseETD')->andThrow(new \ProcessProquest\ProcessingException);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->processFile($fedoraRecordObject);
    }

    #[Test]
    #[TestDox('Checks the processFile() method on a FedoraRecord object to throw an exception on processETD()')]
    public function processFileExceptionOnProcessETD(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object that returns an exception on parseETD().
        $fedoraRecordObject = Mockery::mock(\Processproquest\Record\FedoraRecordProcessor::class)->makePartial();
        $fedoraRecordObject->shouldReceive('downloadETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('parseETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('processETD')->andThrow(new \ProcessProquest\ProcessingException);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->processFile($fedoraRecordObject);
    }

    #[Test]
    #[TestDox('Checks the processFile() method on a FedoraRecord object to throw an exception on generateDatastreams()')]
    public function processFileExceptionOnGenerateDatastreams(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object that returns an exception on generateDatastreams().
        $fedoraRecordObject = Mockery::mock(\Processproquest\Record\FedoraRecordProcessor::class)->makePartial();
        $fedoraRecordObject->shouldReceive('downloadETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('parseETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('processETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('generateDatastreams')->andThrow(new \ProcessProquest\ProcessingException);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->processFile($fedoraRecordObject);
    }

    #[Test]
    #[TestDox('Checks the processFile() method on a FedoraRecord object to throw an exception on ingestETD()')]
    public function processFileExceptionOnIngestETD(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object that returns an exception on generateDatastreams().
        $fedoraRecordObject = Mockery::mock(\Processproquest\Record\FedoraRecordProcessor::class)->makePartial();
        $fedoraRecordObject->shouldReceive('downloadETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('parseETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('processETD')->andReturn(true);
        $fedoraRecordObject->shouldReceive('generateDatastreams')->andReturn(true);
        $fedoraRecordObject->shouldReceive('ingestETD')->andThrow(new \ProcessProquest\ProcessingException);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Expect an exception.
        $this->expectException(\ProcessProquest\ProcessingException::class);
        $result = $processObj->processFile($fedoraRecordObject);
    }

    #[Test]
    #[TestDox('Checks the getAllFedoraRecordObjects() method returns the allFedoraRecordObjects property')]
    public function getAllFedoraRecordObjects(): void {
        // Create array containing a zip filename.
        $zipFileName = "etdadmin_upload_100000.zip";
        $listOfETDFiles = [];
        array_push($listOfETDFiles, $zipFileName);

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object with custom list of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection($listOfETDFiles);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Get list of scanned ETD files returned by scanForETDFiles() and createFedoraObjects().
        $processObj->scanForETDFiles();
        $createdFedoraRecords = $processObj->createFedoraObjects();

        // Get list of scanned ETD files from getter method.
        $returnedFedoraRecords = $processObj->getAllFedoraRecordObjects();

        $this->assertEquals(count($returnedFedoraRecords), 1, "Expected one FedoraRecord object to be returned.");
    }

    #[Test]
    #[TestDox('Checks the appendAllFedoraRecordObjects() method updates the allFedoraRecordObjects property')]
    public function appendAllFedoraRecordObjects(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->helper->createMockFedoraRecord();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Append a FedoraRecord object using the setter method.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Get list of scanned ETD files from getter method.
        $returnedFedoraRecords = $processObj->getAllFedoraRecordObjects();

        $this->assertEquals(count($returnedFedoraRecords), 1, "Expected one FedoraRecord object to be returned.");
    }

    #[Test]
    #[TestDox('Checks the appendAllFedoraRecordObjects() method returns false when passed the wrong object type')]
    public function appendAllFedoraRecordObjectsWrongObjectType(): void {
        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object with custom list of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock Object.
        $wrongObjectType = new \stdClass();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Append the wrong object type using the setter method.
        $returnValue = $processObj->appendAllFedoraRecordObjects($wrongObjectType);

        $this->assertNotTrue($returnValue, "Expecting a false value.");
    }
    
    #[Test]
    #[TestDox('Checks the moveFTPFiles() method returns true')]
    public function moveFTPFiles(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->helper->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = true;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter method.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->helper->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $result = $method->invoke($processObj);

        $this->assertTrue($result, "Expected moveFTPFiles() to return true.");
    }

    #[Test]
    #[TestDox('Checks the moveFTPFiles() method returns an error message on failure')]
    public function moveFTPFilesFailOnMove(): void {
        // Create a custom mock ftpConnection object that returns false for moveFile().
        $mockFTPConnection = Mockery::mock(\Processproquest\FTP\ProquestFTP::class)->makePartial();
        $mockFTPConnection->shouldReceive('moveFile')->andReturn(false);

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->helper->createMockFedoraRecord();

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter method.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->helper->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $result = $method->invoke($processObj);

        // Check that NONCRITICAL_ERRORS was updated.
        $noncriticalErrors = $mockFedoraRecord->NONCRITICAL_ERRORS;;

        $this->assertTrue(count($noncriticalErrors) > 0, "Expected at least one noncritical error to be reported.");
    }

    #[Test]
    #[TestDox('Checks the moveFTPFiles() method returns the correct post-process file location')]
    public function moveFTPFilesCorrectFTPFileLocationOnIngest(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->helper->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = true;
        $mockFedoraRecord->HAS_SUPPLEMENTS = false;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter method.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->helper->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $result = $method->invoke($processObj);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        $processdirFTP = $this->configurationSettings['ftp']['processdir'];
        //$faildirFTP = $this->configurationSettings['ftp']['faildir'];
        //$manualdirFTP = $this->configurationSettings['ftp']['manualdir'];

        $this->assertEquals($processdirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }

    #[Test]
    #[TestDox('Checks the moveFTPFiles() method returns the correct post-process file location with supplements')]
    public function moveFTPFilesCorrectFTPFileLocationHasSupplement(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->helper->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = false;
        $mockFedoraRecord->HAS_SUPPLEMENTS = true;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter method.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->helper->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $result = $method->invoke($processObj);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        //$processdirFTP = $this->configurationSettings['ftp']['processdir'];
        //$faildirFTP = $this->configurationSettings['ftp']['faildir'];
        $manualdirFTP = $this->configurationSettings['ftp']['manualdir'];

        $this->assertEquals($manualdirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }

    #[Test]
    #[TestDox('Checks the moveFTPFiles() method returns the correct post-process file location on failure')]
    public function moveFTPFilesCorrectFTPFileLocationOnFail(): void {
        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->helper->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = false;
        $mockFedoraRecord->HAS_SUPPLEMENTS = false;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter method.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->helper->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $result = $method->invoke($processObj);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        //$processdirFTP = $this->configurationSettings['ftp']['processdir'];
        $faildirFTP = $this->configurationSettings['ftp']['faildir'];
        //$manualdirFTP = $this->configurationSettings['ftp']['manualdir'];

        $this->assertEquals($faildirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }
}