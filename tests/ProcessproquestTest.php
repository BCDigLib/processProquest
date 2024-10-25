<?php declare(strict_types=1);
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Use helpers class.
require_once(__DIR__ . "/helpers.php");

#[CoversClass(\Processproquest\Processproquest::class)]
#[UsesClass(\Processproquest\FTP\ProquestFTP::class)]
#[UsesClass(\Processproquest\Repository\FedoraRepository::class)]
#[UsesClass(\Processproquest\Record\FedoraRecord::class)]
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

        $this->helper = new TestHelpers("test");
        $this->configurationArray = $this->helper->readConfigurationFile($configurationFile);
        $this->configurationFile = $this->configurationArray["file"];
        $this->settings = $this->configurationArray["settings"];
        $this->logger = $this->helper->createLogger($this->settings);
        $this->debug = true;
        $this->listOfETDs = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip'];
    }

    protected function tearDown(): void {
        $this->configurationArray = null;
        $this->logger = null;
    }

    #[Test]
    public function setFTPConnection(): void {
        echo "\n[*] This test checks the setFTPConnection() method.\n";

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
    public function setFedoraConnection(): void {
        echo "\n[*] This test checks the setFedoraConnection() method.\n";

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
    public function setDebug(): void {
        echo "\n[*] This test checks the setDebug() method.\n";

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
    public function logIntoFTPServer(): void {
        echo "\n[*] This test checks the logIntoFTPServer() method returns successfully with valid credentials.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        
        // Expect a true value.
        $result = $processObj->logIntoFTPServer();
        echo "Expected: true\n";
        echo "Received: " . ($result ? "true" : "false") . "\n";
        $this->assertTrue($result, "Expected logIntoFTPServer() to return true.");
    }

    // Incomplete.
    // This test throws an exception on setFTPConnection().
    public function logIntoFTPServerConfigEmptyServerValue(): void {
        echo "\n[*] This test checks the logIntoFTPServer() method returns an exception with an empty server URL value.\n";

        // Stop here and mark this test as incomplete.
        $this->markTestIncomplete(
            'This test is incomplete.',
        );

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("server" => ""),
        );
        $newSettings = $this->alterConfigurationSettings($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;

        // Create a ftpConnection object with updated settings.
        $ftpConnection = $this->createFTPConnection($this->settings);
        // $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create Processproquest object using the updated FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($ftpConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $result = $processObj->logIntoFTPServer();
    }

    /**
     * TODO: rewrite this to use a mockFTPConnection.
     */
    #[Test]
    public function scanForETDFilesConfigEmptyLocaldirValue(): void {
        echo "\n[*] This test checks the scanForETDFiles() method returns an exception with an invalid localdir value.\n";

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newSettings = $this->helper->alterConfigurationSettings($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;

        // Create a ftpConnection object with updated settings.
        $ftpConnection = $this->helper->createFTPConnection($this->settings);
        // $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using the updated FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($ftpConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $result = $processObj->scanForETDFiles();
    }

    #[Test]
    public function scanForETDFiles(): void {
        echo "\n[*] This test checks the scanForETDFiles() method returns a list of valid ETD zip files.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        $fileArray = $processObj->scanForETDFiles();

        echo "\nExpected: ";
        print_r($this->helper->listOfETDs);

        echo "\nReceived: ";
        print_r($fileArray);
        echo "\n";

        $this->assertTrue($this->helper->arrays_are_similar($fileArray, $this->listOfETDs), "Expected the two arrays to match.");
    }

    #[Test]
    public function scanForETDFilesEmptyFetchdirFTPProperty(): void {
        echo "\n[*] This test checks the scanForETDFiles() method replaces an empty fetchdirFTP property with a default value.\n";

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

        echo "\nExpected: {$expectedValue}";
        echo "\nReceived: {$fetchdirFTPProperty->getValue($processObj)}\n";

        $this->assertEquals($expectedValue, $fetchdirFTPProperty->getValue($processObj));

    }

    #[Test]
    public function scanForETDFilesChangeDirReturnsFalse(): void {
        echo "\n[*] This test checks the scanForETDFiles() method when ProquestFTP->changeDir() returns false.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->getMockBuilder(\Processproquest\FTP\ProquestFTP::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['login', 'getFileList', 'changeDir'])
            ->getMock();

        $mockFTPConnection->method('login')->willReturn(true);
        // Change default return value of changeDir to be false.
        $mockFTPConnection->method('changeDir')->willReturn(false);

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    #[Test]
    public function scanForETDFilesRegexNoMatch(): void {
        echo "\n[*] This test checks the scanForETDFiles() method returns an exception with this->settings['ftp']['localdir'] is empty.\n";

        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newConfigurationArray = $this->helper->alterConfigurationArray($updatedSettings);

        // Create a mock ftpConnection object with an empty initial array of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = new \Processproquest\Processproquest(
            $newConfigurationArray, 
            $this->logger, 
            $this->debug
        );
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    #[Test]
    public function scanForETDFilesNoETDsFound(): void {
        echo "\n[*] This test checks the scanForETDFiles() method returns an exception when there are no ETDs on the FTP server.\n";

        // Create a mock ftpConnection object with an empty initial array of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection([]);

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    #[Test]
    public function createFedoraObjects(): void {
        echo "\n[*] This test checks the createFedoraObjects() method returns an array of FedoraRecord object.\n";

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
        echo "\nChecking class name of FedoraRecord object returned:";
        echo "\nExpected: Processproquest\Record\FedoraRecord";
        echo "\nReceived: {$className}\n";
        $this->assertEquals($className, "Processproquest\Record\FedoraRecord", "Expected the values 'Processproquest\Record\FedoraRecord' and '{$className}' to match.");

        // Check the FedoraRecord object name returned by createFedoraObjects()
        $etdZipFileName = $firstCreatedFedoraRecords->ZIP_FILENAME;
        echo "\nChecking zip filename of FedoraRecord object returned:";
        echo "\nExpected: {$zipFileName}";
        echo "\nReceived: {$etdZipFileName}\n";
        $this->assertEquals($zipFileName, $etdZipFileName, "Expected the values '{$zipFileName}' and '{$etdZipFileName}' to match.");
    }

    #[Test]
    public function createFedoraObject(): void {
        echo "\n[*] This test checks the createFedoraObject() method returns a single FedoraRecord object.\n";

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
        echo "\nChecking class name of FedoraRecord object returned:";
        echo "\nExpected: Processproquest\Record\FedoraRecord";
        echo "\nReceived: {$className}\n";
        $this->assertEquals($className, "Processproquest\Record\FedoraRecord", "Expected the values 'Processproquest\Record\FedoraRecord' and '{$className}' to match.");

        // Check the FedoraRecord object name returned by createFedoraObject()
        $etdZipFileName = $firstCreatedFedoraRecord->ZIP_FILENAME;
        echo "\nChecking zip filename of FedoraRecord object returned:";
        echo "\nExpected: {$zipFileName}";
        echo "\nReceived: {$etdZipFileName}\n";
        $this->assertEquals($zipFileName, $etdZipFileName, "Expected the values '{$zipFileName}' and '{$etdZipFileName}' to match.");
    }

    #[Test]
    public function createFedoraObjectsZero(): void {
        echo "\n[*] This test checks the createFedoraObjects() method returns an exception when there are no ETD zip files to process.\n";

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
        $this->expectException(Exception::class);
        $allFoundETDsProperty->setValue($processObj, $listOfZeroETDFiles);
        $listOfFedoraRecordObjects = $processObj->createFedoraObjects();
    }

    #[Test]
    public function statusCheckWithProcessingErrors(): void {
        echo "\n[*] This test checks the statusCheck() method containing processing errors.\n";

        $errorMessage = "This is an error: WXYZ";

        // Create Processproquest object.
        $processObj = $this->helper->generateProcessproquestObject();

        // Get protected property processingErrors using reflection.
        $processingErrorsProperty = $this->helper->getProtectedProperty('\Processproquest\Processproquest', 'processingErrors');

        // Set the processingErrors property.
        $processingErrorsProperty->setValue($processObj, [$errorMessage]);

        // Get output of statusCheck() method.
        $message = $processObj->statusCheck();

        echo "\nExpected substring: '{$errorMessage}'";
        echo "\nReceived string: '{$message}'\n";

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }

    #[Test]
    public function statusCheckWithSupplements(): void {
        echo "\n[*] This test checks the statusCheck() method containing ETDs with supplemental files.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecord(
            "etdadmin_upload_100000",       // ID
            $this->settings,                // settings
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

        echo "\nRegular expression: '{$expectedString}'";
        echo "\nReceived string   : '{$message}'\n";

        $this->assertMatchesRegularExpression($expectedString, $message, "Expecting the regular expression match '{$expectedString}' in the returned message.");
    }

    #[Test]
    public function statusCheckWithEmbargo(): void {
        echo "\n[*] This test checks the statusCheck() method containing ETDs with an embargo.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecord(
            "etdadmin_upload_100000",       // ID
            $this->settings,                // settings
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

        echo "\nRegular expression 1: '{$expectedString1}'";
        echo "\nRegular expression 2: '{$expectedString2}'";
        echo "\nReceived string     : '{$message}'\n";

        $this->assertMatchesRegularExpression($expectedString1, $message, "Expecting the regular expression match '{$expectedString1}' in the returned message.");
        $this->assertMatchesRegularExpression($expectedString2, $message, "Expecting the regular expression match '{$expectedString2}' in the returned message.");
    }

    #[Test]
    public function statusCheckWithCriticalErrors(): void {
        echo "\n[*] This test checks the statusCheck() method containing ETDs with a critical error.\n";

        $errorMessage = "This is a critical error: WXYZ";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecord(
            "etdadmin_upload_100000",       // ID
            $this->settings,                // settings
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

        echo "\nExpected: '{$errorMessage}'";
        echo "\nReceived: '{$message}'\n";

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }
    
    #[Test]
    public function statusCheckWithNonCriticalErrors(): void {
        echo "\n[*] This test checks the statusCheck() method containing ETDs with a non-critical error.\n";

        $errorMessage = "This is a non-critical error: WXYZ";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create FedoraRecord object.
        $fedoraRecordObject = new \Processproquest\Record\FedoraRecord(
            "etdadmin_upload_100000",       // ID
            $this->settings,                // settings
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

        echo "\nExpected: '{$errorMessage}'";
        echo "\nReceived: '{$message}'\n";

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }

    #[Test]
    public function processFile(): void {
        echo "\n[*] This test checks the processFile() method on a FedoraRecord object.\n";

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
    public function getAllFedoraRecordObjects(): void {
        echo "\n[*] This test checks the getAllFedoraRecordObjects() method returns the allFedoraRecordObjects property.\n";

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

        echo "\nExpected count: 1";
        echo "\nReceived count: " . count($returnedFedoraRecords) . "\n";

        $this->assertEquals(count($returnedFedoraRecords), 1, "Expected one FedoraRecord object to be returned.");
    }

    #[Test]
    public function appendAllFedoraRecordObjects(): void {
        echo "\n[*] This test checks the appendAllFedoraRecordObjects() method updates the allFedoraRecordObjects property.\n";

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

        echo "\nExpected count: 1";
        echo "\nReceived count: " . count($returnedFedoraRecords) . "\n";

        $this->assertEquals(count($returnedFedoraRecords), 1, "Expected one FedoraRecord object to be returned.");
    }

    #[Test]
    public function appendAllFedoraRecordObjectsWrongObjectType(): void {
        echo "\n[*] This test checks the appendAllFedoraRecordObjects() method returns false when passed the wrong object type.\n";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->helper->createMockFedoraConnection();

        // Create a mock ftpConnection object with custom list of ETD files.
        $mockFTPConnection = $this->helper->createMockFTPConnection();

        // Create a mock Object.
        $wrongObjectType = new stdClass();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->helper->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Append the wrong object type using the setter method.
        $returnValue = $processObj->appendAllFedoraRecordObjects($wrongObjectType);

        $this->assertNotTrue($returnValue, "Expecting a false value.");
    }
    
    #[Test]
    public function moveFTPFiles(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns true.\n";

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
        $result = $method->invoke($processObj, "moveFTPFiles");

        $this->assertTrue($result, "Expected moveFTPFiles() to return true.");
    }

    #[Test]
    public function moveFTPFilesFailOnMove(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns an error message on failure.\n";

        // Create a mock ftpConnection object that returns false for moveFile().
        $mockFTPConnection = $this->getMockBuilder(\Processproquest\FTP\ProquestFTP::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['moveFile'])
            ->getMock();
        $mockFTPConnection->method('moveFile')->willReturn(false);

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
        $result = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that NONCRITICAL_ERRORS was updated.
        $noncriticalErrors = $mockFedoraRecord->NONCRITICAL_ERRORS;

        echo "\nErrors Recived: ";
        print_r($noncriticalErrors);
        echo "\n";

        $this->assertTrue(count($noncriticalErrors) > 0, "Expected at least one noncritical error to be reported.");
    }

    #[Test]
    public function moveFTPFilesCorrectFTPFileLocationOnIngest(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns the correct post-process file location.\n";

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
        $result = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        $processdirFTP = $this->settings['ftp']['processdir'];
        //$faildirFTP = $this->settings['ftp']['faildir'];
        //$manualdirFTP = $this->settings['ftp']['manualdir'];

        echo "\nExpected: {$processdirFTP}";
        echo "\nReceived: {$ftpPostprocessLocation}\n";

        $this->assertEquals($processdirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }

    #[Test]
    public function moveFTPFilesCorrectFTPFileLocationHasSupplement(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns the correct post-process file location with supplements.\n";

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
        $result = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        //$processdirFTP = $this->settings['ftp']['processdir'];
        //$faildirFTP = $this->settings['ftp']['faildir'];
        $manualdirFTP = $this->settings['ftp']['manualdir'];

        echo "\nExpected: {$manualdirFTP}";
        echo "\nReceived: {$ftpPostprocessLocation}\n";

        $this->assertEquals($manualdirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }

    #[Test]
    public function moveFTPFilesCorrectFTPFileLocationOnFail(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns the correct post-process file location on failure.\n";

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
        $result = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        //$processdirFTP = $this->settings['ftp']['processdir'];
        $faildirFTP = $this->settings['ftp']['faildir'];
        //$manualdirFTP = $this->settings['ftp']['manualdir'];

        echo "\nExpected: {$faildirFTP}";
        echo "\nReceived: {$ftpPostprocessLocation}\n";

        $this->assertEquals($faildirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }
}