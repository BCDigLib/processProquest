<?php declare(strict_types=1);
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../src/Processproquest.php";
require __DIR__ . "/../src/FedoraRepository.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

#[CoversClass(\Processproquest\Processproquest::class)]
#[UsesClass(\Processproquest\FTP\ProquestFTP::class)]
#[UsesClass(\Processproquest\Repository\FedoraRepository::class)]
#[UsesClass(\Processproquest\Record\FedoraRecord::class)]
final class ProcessproquestTest extends TestCase {
    protected $configurationArray = [];
    protected $configurationFile = null;
    protected $settings = [];
    protected $logger = null;
    protected $ftpConnection = null;
    protected $fedoraConnection = null;
    protected $debug = null;
    protected $listOfETDs = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip'];

    /**
     * Create a logger object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object the logger object.
     */
    protected function createLogger($configurationSettings){
        // Set up log file location and name.
        $dateFormatLogFile = date("Ymd-His", time());
        $logLocation = $configurationSettings['log']['location'];
        $logFileName = "ingest-" . $dateFormatLogFile . ".txt";
        $debug = true;

        // New Logger instance. Create a new channel called "processProquest".
        $logger = new Logger("processProquest");

        // Default date format is "Y-m-d\TH:i:sP"
        $dateFormatLogger = "Y-m-d H:i:s";

        // Default: "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n"
        if ($debug) {
            $output = "[%datetime%] [DEBUG] %message% %context% %extra%\n";
        } else {
            $output = "[%datetime%] > %message% %context% %extra%\n";
        }

        // Create a log formatter.
        // Passing these arguments:
        //   * ouput string format
        //   * date string format
        //   * allowInlineLineBreaks = true
        //   * ignoreEmptyContextAndExtra = true
        $formatter = new LineFormatter($output, $dateFormatLogger, true, true);

        // Log to file.
        //$fileOutput = new StreamHandler("{$logLocation}{$logFileName}", Level::Debug);
        //$fileOutput->setFormatter($formatter);
        //$logger->pushHandler($fileOutput);

        // Log to console.
        // $consoleOutput = new StreamHandler('php://stdout', Level::Debug);
        // $consoleOutput->setFormatter($formatter);
        // $logger->pushHandler($consoleOutput);

        // Log to /dev/null
        $consoleOutput = new StreamHandler('/dev/null', Level::Debug);
        $consoleOutput->setFormatter($formatter);
        $logger->pushHandler($consoleOutput);

        return $logger;
    }

    /**
     * Read the values from a configuration file.
     * 
     * @param string $configurationFileLocation the location of a configuration file to load.
     * 
     * @return array the configuration file contents.
     */
    protected function readConfigurationFile($configurationFileLocation) {
        // Read in configuration settings.
        $configurationSettings = parse_ini_file($configurationFileLocation, true);

        if (empty($configurationSettings)) {
            return NULL;
        }

        $configurationArray = array(
            "file" => $configurationFileLocation,
            "settings" => $configurationSettings
        );

        return $configurationArray;
    }

    /**
     * Overwrite the configuration settings array with new value(s).
     * This makes and edits and returns a copy of the default settings array.
     * 
     * @param array $updatedSettings the updated setting values.
     * 
     * @return array a settings array copy that includes the new values.
     */
    protected function alterConfigurationSettings($updatedSettings) {
        // $updatedSettings is a nested array in the form 
        // [
        //    "ftp" => ["server" -> "foo", "user" => "bar"],
        //    "fedora" => ["url" => "foo", "username" => "bar"]
        // ]

        // Create a copy of the default settings array.
        $newSettings = $this->settings;

        echo "\n--------\nUpdating configuration settings\n";
        foreach ($updatedSettings as $keyParent => $valueArray) {
            foreach ($valueArray as $keyChild => $value) {
                print "[{$keyParent}]\n{$keyChild} = {$value}\n";
                $newSettings[$keyParent][$keyChild] = $value;
            }
        }
        echo "--------\n\n";

        return $newSettings;
    }

    /**
     * Overwrite the configuration array with new settings value(s).
     * This calls alterConfigurationSettings() to update the settings array.
     * This makes and edits and returns a copy of the default configurationArray array.
     * 
     * @param array $updatedSettings the updated setting values.
     * 
     * @return array the configuration array including the new values.
     */
    protected function alterConfigurationArray($updatedSettings) {
        // Create a copy of the default configurationArray array.
        $newConfigurationArray = $this->configurationArray;

        $newSettingsArray = $this->alterConfigurationSettings($updatedSettings);
        $newConfigurationArray["settings"] = $newSettingsArray;

        return $newConfigurationArray;
    }

    /**
     * Create an ftpConnection object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object a ProquestFTP object.
     */
    protected function createFTPConnection($configurationSettings) {
        $urlFTP = $configurationSettings['ftp']['server'];

        // TODO: catch exceptions here?
        $ftpConnection = new \Processproquest\FTP\ProquestFTP($urlFTP);

        return $ftpConnection;
    }

    /**
     * Create a fedoraConnection object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object a FedoraRepository object.
     */
    protected function createFedoraConnection($configurationSettings) {
        $fedoraURL      = $configurationSettings['fedora']['url'];
        $fedoraUsername = $configurationSettings['fedora']['username'];
        $fedoraPassword = $configurationSettings['fedora']['password'];
        $tuqueLocation  = $configurationSettings['packages']['tuque'];

        $fedoraConnection = new \Processproquest\Repository\FedoraRepository($tuqueLocation, $fedoraURL, $fedoraUsername, $fedoraPassword);

        return $fedoraConnection;
    }

    /**
     * Create a mock ftpConnection object.
     * 
     * @param array $listofETDs an optional array of ETD file names.
     * 
     * @return object a mock ProquestFTP object.
     */
    protected function createMockFTPConnection(array $listOfETDs = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip']) {
        // See https://stackoverflow.com/a/61595920
        $mockFTPConnection = $this->getMockBuilder(\Processproquest\FTP\ProquestFTP::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['login', 'moveFile', 'getFileList', 'changeDir'])
            ->getMock();
        $mockFTPConnection->method('login')->willReturn(true);
        $mockFTPConnection->method('moveFile')->willReturn(true);
        $mockFTPConnection->method('changeDir')->willReturn(true);
        $mockFTPConnection->method('getFileList')->willReturn($listOfETDs);

        return $mockFTPConnection;
    }

    /**
     * Create a mock fedoraConnection object.
     * 
     * @return object a mock FedoraRepository object.
     */
    protected function createMockFedoraConnection() {
        // See https://stackoverflow.com/a/61595920
        $mockFedoraConnection = $this->getMockBuilder(\Processproquest\Repository\FedoraRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getNextPid', 'constructObject', 'getObject', 'ingestObject'])
            ->getMock();

        $genericObject = new stdClass();

        $mockFedoraConnection->method('getNextPid')->willReturn("bc-ir:9999999");
        $mockFedoraConnection->method('constructObject')->willReturn($genericObject);
        $mockFedoraConnection->method('getObject')->willReturn($genericObject);
        $mockFedoraConnection->method('ingestObject')->willReturn($genericObject);

        return $mockFedoraConnection;
    }

    /**
     * Create a mock FedoraRecord object.
     * 
     * @return object a mock FedoraRecord object.
     */
    protected function createMockFedoraRecord() {
        $mockFedoraRecord = $this->getMockBuilder(\Processproquest\Record\FedoraRecord::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['downloadETD', 'parseETD', 'processETD', 'generateDatastreams', 'ingestETD'])
            ->getMock();

        $mockFedoraRecord->method('downloadETD')->willReturn(true);
        $mockFedoraRecord->method('parseETD')->willReturn(true);
        $mockFedoraRecord->method('processETD')->willReturn(true);
        $mockFedoraRecord->method('generateDatastreams')->willReturn(true);
        $mockFedoraRecord->method('ingestETD')->willReturn(true);

        return $mockFedoraRecord;
    }

    /**
     * Generate a new Processproquest object.
     * 
     * @return object a Processproquest object.
     */
    protected function generateProcessproquestObject() {
        $newObj = new \Processproquest\Processproquest(
                            $this->configurationArray, 
                            $this->logger, 
                            $this->debug
                        );

        return $newObj;
    }

    /**
     * Uses reflection to access protected or private class properties.
     * 
     * @param string $className the name of the class.
     * @param string $property the name of the property to access.
     * 
     * @return object $property the reflected class object.
     */
    protected static function getProtectedProperty($className, $property) {
        // See https://www.yellowduck.be/posts/test-private-and-protected-properties-using-phpunit

        $reflectedClass = new \ReflectionClass($className);
        $property = $reflectedClass->getProperty($property);
        $property->setAccessible(true);

        return $property;
    }

    /**
     * Uses reflection to access protected or private class methods.
     * 
     * @param string $className the name of the class.
     * @param string $methodName the name of the method to access.
     * 
     * @return object $method the reflected class method.
     */
    protected static function getProtectedMethod($className, $methodName) {
        $reflectedClass = new ReflectionClass($className);
        $method = $reflectedClass->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }

    /**
     * Determine if two associative arrays are similar.
     *
     * Both arrays must have the same indexes with identical values
     * without respect to key ordering.
     * 
     * Copied from: https://stackoverflow.com/a/3843768
     * 
     * @param array $a The first array.
     * @param array $b The second array.
     * 
     * @return bool Do the arrays match.
     */
    protected function arrays_are_similar($a, $b) {
        // If the indexes don't match, return immediately.
        if (count(array_diff_assoc($a, $b))) {
            return false;
        }

        // We know that the indexes, but maybe not values, match.
        // Compare the values between the two arrays.
        foreach($a as $k => $v) {
            if ($v !== $b[$k]) {
                return false;
            }
        }

        // We have identical indexes, and no unequal values.
        return true;
    }

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->configurationArray = $this->readConfigurationFile($configurationFile);
        $this->configurationFile = $this->configurationArray["file"];
        $this->settings = $this->configurationArray["settings"];
        $this->logger = $this->createLogger($this->settings);
        $this->debug = true;
    }

    protected function tearDown(): void {
        $this->configurationArray = null;
        $this->logger = null;
    }

    /**
     * @covers ::setFTPConnection
     */
    public function testSetFTPConnection(): void {
        echo "\n[*] This test checks the setFTPConnection() function.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create Processproquest object.
        $processObj = $this->generateProcessproquestObject();
        
        // Assert that the ftpConnection object is null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'ftpConnection');
        $this->assertNull($property->getValue($processObj), "Expected the ftpConnection object to be null.");

        // Set the ftpConnection object using the mock FTP connection.
        $processObj->setFTPConnection($mockFTPConnection);

        // Assert that the ftpConnection object is not null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'ftpConnection');
        $this->assertIsObject($property->getValue($processObj), "Expected the ftpConnection object to exist.");
    }

    /**
     * @covers ::setFedoraConnection
     */
    public function testSetFedoraConnection(): void {
        echo "\n[*] This test checks the setFedoraConnection() function.\n";

        // Create a mock fedoraConnection object.
        $this->fedoraConnection = $this->createMockFedoraConnection();

        // Create Processproquest object.
        $processObj = $this->generateProcessproquestObject();
        
        // Assert that the fedoraConnection object is null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'fedoraConnection');
        $this->assertNull($property->getValue($processObj), "Expected the fedoraConnection object to be null.");

        // Set the fedoraConnection object using the mock Fedora connection.
        $processObj->setFedoraConnection($this->fedoraConnection);

        // Assert that the fedoraConnection object is not null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'fedoraConnection');
        $this->assertIsObject($property->getValue($processObj), "Expected the fedoraConnection object to exist.");
    }

    /**
     * @covers ::setDebug
     */
    public function testSetDebug(): void {
        echo "\n[*] This test checks the setDebug() function.\n";

        // Create Processproquest object.
        $processObj = $this->generateProcessproquestObject();

        // Check that the debug property is set to true.
        $this->assertTrue($processObj->debug, "Expected the default debug property to be true");

        // Set the debug property to false.
        $processObj->setDebug(false);

        // Check that the debug property is set to false.
        $this->assertNotTrue($processObj->debug, "Expected the updated debug property to be false");
    }

    /**
     * @covers ::LogIntoFTPServer
     */
    public function testLogIntoFTPServer(): void {
        echo "\n[*] This test checks the LogIntoFTPServer() function returns successfully with valid credentials.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        
        // Expect a true value.
        $return = $processObj->LogIntoFTPServer();
        echo "Expected: true\n";
        echo "Received: " . ($return ? "true" : "false") . "\n";
        $this->assertTrue($return, "Expected LogIntoFTPServer() to return true.");
    }

    // Incomplete.
    // This test throws an exception on setFTPConnection().
    public function testLogIntoFTPServerConfigEmptyServerValue(): void {
        echo "\n[*] This test checks the LogIntoFTPServer() function returns an exception with an empty server URL value.\n";

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
        // $mockFTPConnection = $this->createMockFTPConnection();

        // Create Processproquest object using the updated FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($ftpConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $return = $processObj->LogIntoFTPServer();
    }

    /**
     * @covers ::scanForETDFiles
     * 
     * TODO: rewrite this to use a mockFTPConnection.
     */
    public function testScanForETDFilesConfigEmptyLocaldirValue(): void {
        echo "\n[*] This test checks the scanForETDFiles() function returns an exception with an invalid localdir value.\n";

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newSettings = $this->alterConfigurationSettings($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;

        // Create a ftpConnection object with updated settings.
        $ftpConnection = $this->createFTPConnection($this->settings);
        // $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using the updated FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($ftpConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $return = $processObj->scanForETDFiles();
    }

    /**
     * @covers ::scanForETDFiles
     */
    public function testScanForETDFiles(): void {
        echo "\n[*] This test checks the scanForETDFiles() function returns a list of valid ETD zip files.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        $fileArray = $processObj->scanForETDFiles();

        echo "\nExpected: ";
        print_r($this->listOfETDs);

        echo "\nReceived: ";
        print_r($fileArray);
        echo "\n";

        $this->assertTrue($this->arrays_are_similar($fileArray, $this->listOfETDs), "Expected the two arrays to match.");
    }

    /**
     * @covers ::scanForETDFiles
     */
    public function testScanForETDFilesEmptyFetchdirFTPProperty(): void {
        echo "\n[*] This test checks the scanForETDFiles() function replaces an empty fetchdirFTP property with a default value.\n";

        $expectedValue = "~/";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Get protected property fetchdirFTP using reflection.
        $fetchdirFTPProperty = $this->getProtectedProperty('\Processproquest\Processproquest', 'fetchdirFTP');

        // Set fetchdirFTP to be an empty string.
        $fetchdirFTPProperty->setValue($processObj, "");

        $fileArray = $processObj->scanForETDFiles();

        echo "\nExpected: {$expectedValue}";

        echo "\nReceived: {$fetchdirFTPProperty->getValue($processObj)}\n";

        $this->assertEquals($expectedValue, $fetchdirFTPProperty->getValue($processObj));

    }

    /**
     * @covers ::scanForETDFiles
     */
    public function testScanForETDFilesChangeDirReturnsFalse(): void {
        echo "\n[*] This test checks the scanForETDFiles() function when \Processproquest\FTP\ProquestFTP->changeDir() returns false.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->getMockBuilder(\Processproquest\FTP\ProquestFTP::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['login', 'getFileList', 'changeDir'])
            ->getMock();

        $mockFTPConnection->method('login')->willReturn(true);
        // Change default return value of changeDir to be false.
        $mockFTPConnection->method('changeDir')->willReturn(false);

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    /**
     * @covers ::scanForETDFiles
     */
    public function testScanForETDFilesRegexNoMatch(): void {
        echo "\n[*] This test checks the scanForETDFiles() function returns an exception with this->settings['ftp']['localdir'] is empty.\n";

        // Replace [ftp] "localdir" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newConfigurationArray = $this->alterConfigurationArray($updatedSettings);

        // Create a mock ftpConnection object with an empty initial array of ETD files.
        $mockFTPConnection = $this->createMockFTPConnection();

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

    /**
     * @covers ::scanForETDFiles
     */
    public function testScanForETDFilesNoETDsFound(): void {
        echo "\n[*] This test checks the scanForETDFiles() function returns an exception when there are no ETDs on the FTP server.\n";

        // Create a mock ftpConnection object with an empty initial array of ETD files.
        $mockFTPConnection = $this->createMockFTPConnection([]);

        // Create a Processproquest object using a mock FTP connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        //$processObj->setFedoraConnection($mockFedoraConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $fileArray = $processObj->scanForETDFiles();
    }

    /**
     * @covers ::createFedoraObjects
     */
    public function testCreateFedoraObjects(): void {
        echo "\n[*] This test checks the createFedoraObjects() function returns an array of FedoraRecord object.\n";

        // Create array containing a zip filename.
        $zipFileName = "etdadmin_upload_100000.zip";
        $listOfETDFiles = [];
        array_push($listOfETDFiles, $zipFileName);

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Get protected property allFoundETDs using reflection.
        $allFoundETDsProperty = $this->getProtectedProperty('\Processproquest\Processproquest', 'allFoundETDs');

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

    /**
     * @covers ::createFedoraObject
     */
    public function testCreateFedoraObject(): void {
        echo "\n[*] This test checks the createFedoraObject() function returns a single FedoraRecord object.\n";

        // A sample zip filename.
        $zipFileName = "etdadmin_upload_100000.zip";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
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

    /**
     * @covers ::createFedoraObjects
     */
    public function testCreateFedoraObjectsZero(): void {
        echo "\n[*] This test checks the createFedoraObjects() function returns an exception when there are no ETD zip files to process.\n";

        // Create an array.
        $listOfZeroETDFiles = [];

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Get protected property allFoundETDs using reflection.
        $allFoundETDsProperty = $this->getProtectedProperty('\Processproquest\Processproquest', 'allFoundETDs');

        // Expect an exception.
        $this->expectException(Exception::class);
        $allFoundETDsProperty->setValue($processObj, $listOfZeroETDFiles);
        $listOfFedoraRecordObjects = $processObj->createFedoraObjects();
    }

    /**
     * @covers ::statusCheck
     */
    public function testStatusCheckWithProcessingErrors(): void {
        echo "\n[*] This test checks the statusCheck() function containing processing errors.\n";

        $errorMessage = "This is an error: WXYZ";

        // Create Processproquest object.
        $processObj = $this->generateProcessproquestObject();

        // Get protected property processingErrors using reflection.
        $processingErrorsProperty = $this->getProtectedProperty('\Processproquest\Processproquest', 'processingErrors');

        // Set the processingErrors property.
        $processingErrorsProperty->setValue($processObj, [$errorMessage]);

        // Get output of statusCheck() function.
        $message = $processObj->statusCheck();

        echo "\nExpected substring: '{$errorMessage}'";
        echo "\nReceived string: '{$message}'\n";

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }

    /**
     * @covers ::statusCheck
     */
    public function testStatusCheckWithSupplements(): void {
        echo "\n[*] This test checks the statusCheck() function containing ETDs with supplemental files.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

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
        $processObj = $this->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() function.
        $message = $processObj->statusCheck();

        $expectedString = '/Has supplements:\s+true/';

        echo "\nRegular expression: '{$expectedString}'";
        echo "\nReceived string   : '{$message}'\n";

        $this->assertMatchesRegularExpression($expectedString, $message, "Expecting the regular expression match '{$expectedString}' in the returned message.");
    }

    /**
     * @covers ::statusCheck
     */
    public function testStatusCheckWithEmbargo(): void {
        echo "\n[*] This test checks the statusCheck() function containing ETDs with an embargo.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

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
        $processObj = $this->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() function.
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

    /**
     * @covers ::statusCheck
     */
    public function testStatusCheckWithCriticalErrors(): void {
        echo "\n[*] This test checks the statusCheck() function containing ETDs with a critical error.\n";

        $errorMessage = "This is a critical error: WXYZ";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

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
        $processObj = $this->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() function.
        $message = $processObj->statusCheck();

        echo "\nExpected: '{$errorMessage}'";
        echo "\nReceived: '{$message}'\n";

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }
    
    /**
     * @covers ::statusCheck
     */
    public function testStatusCheckWithNonCriticalErrors(): void {
        echo "\n[*] This test checks the statusCheck() function containing ETDs with a non-critical error.\n";

        $errorMessage = "This is a non-critical error: WXYZ";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

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
        $processObj = $this->generateProcessproquestObject();

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        // Get output of statusCheck() function.
        $message = $processObj->statusCheck();

        echo "\nExpected: '{$errorMessage}'";
        echo "\nReceived: '{$message}'\n";

        $this->assertStringContainsStringIgnoringCase($errorMessage, $message, "Expecting the substring '{$errorMessage}' in the returned message.");
    }

    /**
     * @covers ::processAllFiles
     */
    public function testProcessAllFiles(): void {
        echo "\n[*] This test checks the processAllFiles() function on an array of FedoraRecord objects.\n";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create array containing a mock FedoraRecord object.
        $fedoraRecordObject = $this->createMockFedoraRecord();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Append mock FedoraRecord object.
        $processObj->appendAllFedoraRecordObjects($fedoraRecordObject);

        $ret = $processObj->processAllFiles();

        $this->assertTrue($ret, "Expected processAllFiles() to return true.");
    }

    /**
     * @covers ::appendAllFedoraRecordObjects
     */
    public function testGetAllFedoraRecordObjects(): void {
        echo "\n[*] This test checks the getAllFedoraRecordObjects() function returns the allFedoraRecordObjects property.\n";

        // Create array containing a zip filename.
        $zipFileName = "etdadmin_upload_100000.zip";
        $listOfETDFiles = [];
        array_push($listOfETDFiles, $zipFileName);

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object with custom list of ETD files.
        $mockFTPConnection = $this->createMockFTPConnection($listOfETDFiles);

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Get list of scanned ETD files returned by scanForETDFiles() and createFedoraObjects().
        $processObj->scanForETDFiles();
        $createdFedoraRecords = $processObj->createFedoraObjects();

        // Get list of scanned ETD files from getter function.
        $returnedFedoraRecords = $processObj->getAllFedoraRecordObjects();

        echo "\nExpected count: 1";
        echo "\nReceived count: " . count($returnedFedoraRecords) . "\n";

        $this->assertEquals(count($returnedFedoraRecords), 1, "Expected one FedoraRecord object to be returned.");
    }

    /**
     * @covers ::appendAllFedoraRecordObjects
     */
    public function testAppendAllFedoraRecordObjects(): void {
        echo "\n[*] This test checks the appendAllFedoraRecordObjects() function updates the allFedoraRecordObjects property.\n";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->createMockFedoraRecord();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Append a FedoraRecord object using the setter function.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Get list of scanned ETD files from getter function.
        $returnedFedoraRecords = $processObj->getAllFedoraRecordObjects();

        echo "\nExpected count: 1";
        echo "\nReceived count: " . count($returnedFedoraRecords) . "\n";
        // echo "\nClass type    : " . get_class($mockFedoraRecord);

        $this->assertEquals(count($returnedFedoraRecords), 1, "Expected one FedoraRecord object to be returned.");
    }

    /**
     * @covers ::appendAllFedoraRecordObjects
     */
    public function testAppendAllFedoraRecordObjectsWrongObjectType(): void {
        echo "\n[*] This test checks the appendAllFedoraRecordObjects() function returns false when passed the wrong object type.\n";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object with custom list of ETD files.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock Object.
        $wrongObjectType = new stdClass();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        // Append the wrong object type using the setter function.
        $returnValue = $processObj->appendAllFedoraRecordObjects($wrongObjectType);

        $this->assertNotTrue($returnValue, "Expecting a false value.");
    }
    
    /**
     * @covers ::moveFTPFiles
     */
    public function testMoveFTPFiles(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns true.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = true;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter function.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $returnValue = $method->invoke($processObj, "moveFTPFiles");

        $this->assertTrue($returnValue, "Expected moveFTPFiles() to return true.");
    }

    /**
     * @covers ::moveFTPFiles
     */
    public function testMoveFTPFilesFailOnMove(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns an error message on failure.\n";

        // Create a mock ftpConnection object that returns false for moveFile().
        $mockFTPConnection = $this->getMockBuilder(\Processproquest\FTP\ProquestFTP::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['moveFile'])
            ->getMock();
        $mockFTPConnection->method('moveFile')->willReturn(false);

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->createMockFedoraRecord();

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter function.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $returnValue = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that NONCRITICAL_ERRORS was updated.
        $noncriticalErrors = $mockFedoraRecord->NONCRITICAL_ERRORS;

        echo "\nErrors Recived: ";
        print_r($noncriticalErrors);
        echo "\n";

        $this->assertTrue(count($noncriticalErrors) > 0, "Expected at least one noncritical error to be reported.");
    }

    /**
     * @covers ::moveFTPFiles
     */
    public function testMoveFTPFilesCorrectFTPFileLocationOnIngest(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns the correct post-process file location.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = true;
        $mockFedoraRecord->HAS_SUPPLEMENTS = false;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter function.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $returnValue = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        $processdirFTP = $this->settings['ftp']['processdir'];
        //$faildirFTP = $this->settings['ftp']['faildir'];
        //$manualdirFTP = $this->settings['ftp']['manualdir'];

        echo "\nExpected: {$processdirFTP}";
        echo "\nReceived: {$ftpPostprocessLocation}\n";

        $this->assertEquals($processdirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }

    /**
     * @covers ::moveFTPFiles
     */
    public function testMoveFTPFilesCorrectFTPFileLocationHasSupplement(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns the correct post-process file location with supplements.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = false;
        $mockFedoraRecord->HAS_SUPPLEMENTS = true;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter function.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $returnValue = $method->invokeArgs($processObj, ["", "", ""]);

        // Check that FTP_POSTPROCESS_LOCATION was updated correctly.
        $ftpPostprocessLocation = $mockFedoraRecord->FTP_POSTPROCESS_LOCATION;

        //$processdirFTP = $this->settings['ftp']['processdir'];
        //$faildirFTP = $this->settings['ftp']['faildir'];
        $manualdirFTP = $this->settings['ftp']['manualdir'];

        echo "\nExpected: {$manualdirFTP}";
        echo "\nReceived: {$ftpPostprocessLocation}\n";

        $this->assertEquals($manualdirFTP, $ftpPostprocessLocation, "Expected both paths to be equal.");
    }

    /**
     * @covers ::moveFTPFiles
     */
    public function testMoveFTPFilesCorrectFTPFileLocationOnFail(): void {
        echo "\n[*] This test checks the moveFTPFiles() method returns the correct post-process file location on failure.\n";

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a mock FedoraRecord.
        $mockFedoraRecord = $this->createMockFedoraRecord();
        $mockFedoraRecord->INGESTED = false;
        $mockFedoraRecord->HAS_SUPPLEMENTS = false;

        // Create a Processproquest object using a mock FTP connection, and set debug to false.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setDebug(false);

        // Append a FedoraRecord object using the setter function.
        $processObj->appendAllFedoraRecordObjects($mockFedoraRecord, true);

        // Use reflection to call on private method moveFTPFiles().
        $method = $this->getProtectedMethod("Processproquest\Processproquest", "moveFTPFiles");
        $returnValue = $method->invokeArgs($processObj, ["", "", ""]);

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