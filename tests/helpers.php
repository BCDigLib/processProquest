<?php declare(strict_types=1);
namespace Processproquest\test;

use PHPUnit\Framework\TestCase;
use \Mockery;

require __DIR__ . "/../src/Processproquest.php";
require __DIR__ . "/../src/FedoraRepository.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class TestHelpers extends TestCase {
    protected $configurationSettings = [];
    protected $configurationFile = null;
    protected $settings = [];
    protected $logger = null;
    protected $ftpConnection = null;
    protected $fedoraConnection = null;
    protected $debug = null;
    //protected $listOfETDs = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip'];

    // TODO: pass configurationFile as an argument
    public function __construct($name) {
        $this->configurationFile = "testConfig.ini";
        $this->configurationSettings = $this->readConfigurationFile($this->configurationFile);
        $this->logger = $this->createLogger($this->configurationSettings);
        $this->debug = true;
    }

    /**
     * Create a logger object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object the logger object.
     */
    public function createLogger($customConfigurationSettings){
        // Set up log file location and name.
        $dateFormatLogFile = date("Ymd-His", time());
        $logLocation = $customConfigurationSettings['log']['location'];
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
    public function readConfigurationFile($configurationFileLocation) {
        // Read in configuration settings.
        $configurationSettings = parse_ini_file($configurationFileLocation, true);

        if (empty($configurationSettings)) {
            return NULL;
        }

        return $configurationSettings;
    }

    /**
     * Overwrite the configuration settings array with new value(s).
     * This makes and edits and returns a copy of the default settings array.
     * 
     * @param array $updatedSettings the updated setting values.
     * 
     * @return array a settings array copy that includes the new values.
     */
    public function alterConfigurationSettings($updatedSettings) {
        // $updatedSettings is a nested array in the form 
        // [
        //    "ftp" => ["server" -> "foo", "user" => "bar"],
        //    "fedora" => ["url" => "foo", "username" => "bar"]
        // ]

        // Create a copy of the default settings array.
        $newSettings = $this->configurationSettings;

        foreach ($updatedSettings as $keyParent => $valueArray) {
            foreach ($valueArray as $keyChild => $value) {
                $newSettings[$keyParent][$keyChild] = $value;
            }
        }

        return $newSettings;
    }

    /**
     * Create an ftpConnection object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object a ProquestFTP object.
     */
    public function createFTPConnection($configurationSettings) {
        $urlFTP = $configurationSettings['ftp']['server'];

        // Create a FTPServicePHPAdapter object that uses PHP built-in functions.
        $ftpService = new \Processproquest\FTP\FTPServicePHPAdapter($urlFTP);

        // TODO: catch exceptions here?
        $ftpConnection = new \Processproquest\FTP\ProquestFTP($ftpService);

        return $ftpConnection;
    }

    /**
     * Create a fedoraConnection object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object a FedoraRepository object.
     */
    public function createFedoraConnection($configurationSettings) {
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
    public function createMockFTPConnection(array $listOfETDs = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip']) {
        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('login')->andReturn(true);
        $mockProquestFTPConnection->shouldReceive('moveFile')->andReturn(true);
        $mockProquestFTPConnection->shouldReceive('changeDir')->andReturn(true);
        $mockProquestFTPConnection->shouldReceive('getFileList')->andReturn($listOfETDs);

        return $mockProquestFTPConnection;
    }

    /**
     * Create a mock FedoraRepository object.
     * 
     * @return object a mock FedoraRepository object.
     */
    public function createMockFedoraConnection() {
        $genericObject = new \stdClass();

        // Create a custom mock FedoraRepository connection object using the RecordRepositoryInterface interface.
        $mockFedoraRepositoryConnection = Mockery::mock(\Processproquest\Repository\RecordRepositoryInterface::class)->makePartial();
        $mockFedoraRepositoryConnection->shouldReceive('getNextPid')->andReturn("bc-ir:9999999");
        $mockFedoraRepositoryConnection->shouldReceive('constructObject')->andReturn($genericObject);
        $mockFedoraRepositoryConnection->shouldReceive('getObject')->andReturn($genericObject);
        $mockFedoraRepositoryConnection->shouldReceive('ingestObject')->andReturn($genericObject);

        return $mockFedoraRepositoryConnection;
    }

    /**
     * Create a mock FedoraRecord object.
     * 
     * @return object a mock FedoraRecord object.
     */
    public function createMockFedoraRecord() {
        $mockFedoraRecord = Mockery::mock(\Processproquest\Record\FedoraRecord::class)->makePartial();
        $mockFedoraRecord->shouldReceive('downloadETD')->andReturn(true);
        $mockFedoraRecord->shouldReceive('parseETD')->andReturn(true);
        $mockFedoraRecord->shouldReceive('processETD')->andReturn(true);
        $mockFedoraRecord->shouldReceive('generateDatastreams')->andReturn(true);
        $mockFedoraRecord->shouldReceive('ingestETD')->andReturn(true);

        return $mockFedoraRecord;
    }

    /**
     * Generate a new Processproquest object.
     * 
     * @param array $customSettings Optional array of settings.
     * 
     * @return object a Processproquest object.
     */
    public function generateProcessproquestObject($customSettings = []) {
        if (empty($customSettings)) {
            $customSettings = $this->configurationSettings;
        }
        $newObj = new \Processproquest\Processproquest(
                            $this->configurationFile,
                            $customSettings, 
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
    public static function getProtectedProperty($className, $property) {
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
    public static function getProtectedMethod($className, $methodName) {
        $reflectedClass = new \ReflectionClass($className);
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
    public function arrays_are_similar($a, $b) {
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
}