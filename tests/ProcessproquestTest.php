<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../Processproquest.php";
require __DIR__ . "/../FedoraRepository.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class ProcessproquestTest extends TestCase
{
    protected $configurationArray = [];
    protected $configurationFile = null;
    protected $settings = [];
    protected $logger = null;
    protected $ftpConnection = null;
    protected $fedoraConnection = null;
    protected $debug = null;

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
     * Uses reflection to access protected or private class properties.
     * 
     * @param string $className the name of the class.
     * @param string $property the name of the property to access.
     * 
     * @return object $property the reflected class object.
     */
    public static function getProtectedProperty($className, $property) {
        // See https://www.yellowduck.be/posts/test-private-and-protected-properties-using-phpunit

        $reflectedClass = new \ReflectionClass($className);
        $property = $reflectedClass->getProperty($property);
        $property->setAccessible(true);

        return $property;
    }

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->configurationArray = $this->readConfigurationFile($configurationFile);
        $this->configurationFile = $this->configurationArray["file"];
        $this->settings = $this->configurationArray["settings"];
        $this->logger = $this->createLogger($this->settings);
        // $this->ftpConnection = $this->createFTPConnection($this->settings);
        $this->debug = true;
    }

    protected function tearDown(): void {
        $this->configurationArray = null;
        $this->logger = null;
    }

    public function testSetFTPConnection(): void {
        echo "\nThis test checks the setFTPConnection() function.\n";

        // Create ftpConnection object.
        $this->ftpConnection = $this->createFTPConnection($this->settings);

        // Create Processproquest object.
        $processObj = (new \Processproquest\Processproquest($this->configurationArray, $this->logger, $this->debug));
        
        // Assert that the ftpConnection object is null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'ftpConnection');
        $this->assertNull($property->getValue($processObj), "Expected the ftpConnection object to be null.");

        // Set the ftpConnection object.
        $processObj->setFTPConnection($this->ftpConnection);

        // Assert that the ftpConnection object is not null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'ftpConnection');
        $this->assertIsObject($property->getValue($processObj), "Expected the ftpConnection object to exist.");
    }

    public function testSetFedoraConnection(): void {
        echo "\nThis test checks the setFedoraConnection() function.\n";

        // Create fedoraConnection object.
        $this->fedoraConnection = $this->createFedoraConnection($this->settings);

        // Create Processproquest object.
        $processObj = (new \Processproquest\Processproquest($this->configurationArray, $this->logger, $this->debug));
        
        // Assert that the fedoraConnection object is null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'fedoraConnection');
        $this->assertNull($property->getValue($processObj), "Expected the fedoraConnection object to be null.");

        // Set the fedoraConnection object.
        $processObj->setFedoraConnection($this->fedoraConnection);

        // Assert that the fedoraConnection object is not null.
        $property = $this->getProtectedProperty('\Processproquest\Processproquest', 'fedoraConnection');
        $this->assertIsObject($property->getValue($processObj), "Expected the fedoraConnection object to exist.");
    }

    // TODO: delete
    public function testNormalizeString(): void {
        echo "\nThis test checks normalizeString() returns a normalized string.\n";

        // Stop here and mark this test as incomplete.
        $this->markTestIncomplete(
            'This test needs to be rewritten.',
        );

        // $configurationFile = "testConfig.ini";
        // $debug = true;
        // $configurationArray = $this->readConfigurationFile($configurationFile);
        // $logger = new Logger("Processproquest");

        $testString = 'This_ is#a test"string';
        $expectedString = 'This-isa-teststring';

        $method = new ReflectionMethod(
            '\Processproquest\Processproquest', 'normalizeString'
        );
        $method->setAccessible(TRUE);

        $processObj = new \Processproquest\Processproquest(
            $this->configurationArray, 
            $this->logger, $this->debug
        );

        $output = $method->invokeArgs($processObj, array($testString));

        echo "Sent this input:      {$testString}\n";
        echo "Received this output: {$output}\n";
        echo "Expected this output: {$expectedString}\n\n";

        $this->assertSame($expectedString, $output);
    }

    // TODO: delete
    public function testWriteLog(): void {
        echo "\nThis test checks writeLog() returns a formatted log entry.\n";

        // Stop here and mark this test as incomplete.
        $this->markTestIncomplete(
            'This test needs to be rewritten.',
        );

        $testString = 'This is a test string';
        $expectedString = '(invokeArgs) This is a test string';

        $method = new ReflectionMethod( 
            '\Processproquest\Processproquest', 'writeLog'
        );
        $method->setAccessible(TRUE);

        $processObj = new \Processproquest\Processproquest($this->configurationArray, $this->logger, $this->debug);

        $output = $method->invokeArgs($processObj, array($testString));

        echo "Sent this input:      {$testString}\n";
        echo "Received this output: {$output}\n";
        echo "Expected this output: {$expectedString}\n\n";

        $this->assertSame($output, $expectedString);
    }

    public function testLogIntoFTPServer(): void {
        echo "\nThis test checks the LogIntoFTPServer() function returns successfully with valid credentials.\n";

        // Create ftpConnection object.
        $this->ftpConnection = $this->createFTPConnection($this->settings);

        // Create Processproquest object.
        $processObj = (new \Processproquest\Processproquest($this->configurationArray, $this->logger, $this->debug))
                            ->setFTPConnection($this->ftpConnection);
        
        // Expect a true value.
        $return = $processObj->LogIntoFTPServer();
        echo "Expected: true\n";
        echo "Returned: " . ($return ? "true" : "false") . "\n";
        $this->assertSame($return, true);
    }

    public function testLogIntoFTPServerConfigEmptyServerValue(): void {
        echo "\nThis test checks the LogIntoFTPServer() function returns an exception with an empty server URL value.\n";

        // Stop here and mark this test as incomplete.
        $this->markTestIncomplete(
            'This test needs to be rewritten.',
        );

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("server" => ""),
        );
        $newSettings = $this->alterConfigArray($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;

        // Create ftpConnection object with updated settings.
        // TODO: this causes an exception from ProquestFTP, and triggers an error in this test.
        $this->ftpConnection = $this->createFTPConnection($this->settings);

        // Create Processproquest object.
        $processObj = (new \Processproquest\Processproquest($this->configurationArray, $this->logger, $this->debug))
                            ->setFTPConnection($this->ftpConnection);

        // Expect an exception.
        $this->expectException(Exception::class);
        $return = $processObj->LogIntoFTPServer();
    }

    public function testGetFilesConfigEmptyLocaldirValue(): void {
        echo "\nThis test checks the getFiles() function returns an exception with an invalid localdir value.\n";

        // Replace [ftp] "server" key with an empty string.
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );
        $newSettings = $this->alterConfigArray($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;

        // Create ftpConnection object with updated settings.
        $this->ftpConnection = $this->createFTPConnection($this->settings);

        // Create Processproquest object.
        $processObj = (new \Processproquest\Processproquest($this->configurationArray, $this->logger, $this->debug))
                            ->setFTPConnection($this->ftpConnection);
        
        // Expect an exception.
        $this->expectException(Exception::class);
        $return = $processObj->scanForETDFiles();
    }
}