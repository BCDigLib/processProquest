<?php declare(strict_types=1);
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../Processproquest.php";
require __DIR__ . "/../FedoraRepository.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

#[CoversClass(\Processproquest\Processproquest::class)]
#[UsesClass(\Processproquest\FTP\ProquestFTP::class)]
#[UsesClass(\Processproquest\Repository\FedoraRepository::class)]
final class ProcessproquestTest extends TestCase
{
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
     * Overwrite the settings array with new value(s).
     * 
     * @param array $updatedSettings the updated setting values.
     * 
     * @return array the settings array including the new values.
     */
    protected function alterConfigArray($updatedSettings) {
        // $updatedSettings is a nested array in the form 
        // [
        //    "ftp" => ["server" -> "foo", "user" => "bar"],
        //    "fedora" => ["url" => "foo", "username" => "bar"]
        // ]
        $foo = $this->settings;

        echo "\n--------\nUpdating configuration settings\n";
        foreach ($updatedSettings as $keyParent => $valueArray) {
            foreach ($valueArray as $keyChild => $value) {
                print "[{$keyParent}]\n{$keyChild} = {$value}\n";
                $this->settings[$keyParent][$keyChild] = $value;
            }
        }
        echo "--------\n\n";

        return $this->settings;
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
     * @return object a mock ProquestFTP object.
     */
    protected function createMockFTPConnection() {
        // TODO: mock up the FTP connection using optional settings.

        // See https://stackoverflow.com/a/61595920
        $mockFTPConnection = $this->getMockBuilder(\Processproquest\FTP\ProquestFTP::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['login', 'getFileList', 'changeDir'])
            ->getMock();

        $mockFTPConnection->method('login')->willReturn(true);
        $mockFTPConnection->method('changeDir')->willReturn(true);

        // TODO: allow custom file listings.
        $mockFTPConnection->method('getFileList')->willReturn($this->listOfETDs);

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
        $this->assertSame($return, true);
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
        $newSettings = $this->alterConfigArray($updatedSettings);
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

    public function testGetFilesConfigEmptyLocaldirValue(): void {
        echo "\n[*] This test checks the getFiles() function returns an exception with an invalid localdir value.\n";

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newSettings = $this->alterConfigArray($updatedSettings);
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

    public function testScanForETDFiles(): void {
        echo "\n[*] This test checks the scanForETDFiles() function returns a list of valid ETD zip files.\n";

        // Create a mock fedoraConnection object.
        $mockFedoraConnection = $this->createMockFedoraConnection();

        // Create a mock ftpConnection object.
        $mockFTPConnection = $this->createMockFTPConnection();

        // Create a Processproquest object using a mock FTP connection, and mock Fedora connection.
        $processObj = $this->generateProcessproquestObject();
        $processObj->setFTPConnection($mockFTPConnection);
        $processObj->setFedoraConnection($mockFedoraConnection);

        $fileArray = $processObj->scanForETDFiles();

        echo "\nExpected: ";
        print_r($this->listOfETDs);

        echo "\nReceived: ";
        print_r($fileArray);

        $this->assertTrue($this->arrays_are_similar($fileArray, $this->listOfETDs));
    }
}