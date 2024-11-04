<?php declare(strict_types=1);
namespace Processproquest\test;

use PHPUnit\Framework\TestCase;
use \Mockery;

require __DIR__ . "/../src/Processproquest.php";
require __DIR__ . "/../src/RepositoryProcessor.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class TestHelpers extends TestCase {
    protected $configurationSettings = Array();
    protected $configurationFile = null;
    protected $settings = Array();
    protected $logger = null;
    protected $ftpConnection = null;
    protected $fedoraConnection = null;
    protected $debug = null;
    protected $listOfETDs = Array();
    protected $mockAbstractFedoraObject = null;
    protected $mockAbstractFedoraDatastream = null;

    // TODO: pass configurationFile as an argument
    public function __construct($name) {
        $this->configurationFile = "testConfig.ini";
        $this->configurationSettings = $this->readConfigurationFile($this->configurationFile);
        $this->logger = $this->createLogger($this->configurationSettings);
        $this->debug = true;
        $this->listOfETDs = ['etdadmin_upload_001_normal.zip', 'etdadmin_upload_002_embargoed.zip'];
        $this->mockAbstractFedoraObject = $this->generateMockAbstractFedoraObject();
        $this->mockAbstractFedoraDatastream = $this->generateMockAbstractFedoraDatastream();
    }

    /**
     * Getter method to get $listOfSampleETDs.
     * 
     * @return array $listOfETDs.
     */
    public function getListOfSampleETDs() {
        return $this->listOfETDs;
    }

    /**
     * Create a logger object.
     * 
     * @param array $configurationSettings The settings to use.
     * 
     * @return object The logger object.
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
     * @param string $configurationFileLocation The location of a configuration file to load.
     * 
     * @return array The configuration file contents.
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
     * @param array $updatedSettings The updated setting values.
     * 
     * @return array A settings array copy that includes the new values.
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
     * @param array $configurationSettings The settings to use.
     * 
     * @return object A ProquestFTP object.
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
     * @param array $configurationSettings The settings to use.
     * 
     * @return object A FedoraRepositoryProcessor object.
     */
    public function createFedoraConnection($configurationSettings) {
        $fedoraURL      = $configurationSettings['fedora']['url'];
        $fedoraUsername = $configurationSettings['fedora']['username'];
        $fedoraPassword = $configurationSettings['fedora']['password'];
        $tuqueLocation  = $configurationSettings['packages']['tuque'];

        // Create FedoraRepositoryProcessorServiceAdapter object.
        $repositoryService = new \Processproquest\Repository\FedoraRepositoryProcessorServiceAdapter($tuqueLocation, $fedoraURL, $fedoraUsername, $fedoraPassword);

        // Create FedoraRepositoryProcessor using the FedoraRepositoryProcessorServiceAdapter object.
        $fedoraConnection = new \Processproquest\Repository\FedoraRepositoryProcessor($repositoryService);

        return $fedoraConnection;
    }

    /**
     * Create a mock ftpConnection object.
     * 
     * @param array $listofETDs An optional array of ETD file names.
     * 
     * @return object A mock ProquestFTP object.
     */
    public function createMockFTPConnection(array $listOfETDs = ['etdadmin_upload_001_normal.zip', 'etdadmin_upload_002_embargoed.zip']) {
        // Create a custom mock ProquestFTP connection object using the FileStorageInterface interface.
        $mockProquestFTPConnection = Mockery::mock(\Processproquest\FTP\FileStorageInterface::class)->makePartial();
        $mockProquestFTPConnection->shouldReceive('login')->andReturn(true);
        $mockProquestFTPConnection->shouldReceive('moveFile')->andReturn(true);
        $mockProquestFTPConnection->shouldReceive('changeDir')->andReturn(true);
        $mockProquestFTPConnection->shouldReceive('getFileList')->andReturn($listOfETDs);

        return $mockProquestFTPConnection;
    }

    /**
     * Create a mock FedoraRepositoryProcessor object.
     * 
     * @return object A mock FedoraRepositoryProcessor object.
     */
    public function createMockFedoraConnection() {
        // Create a custom mock FedoraRepositoryProcessor connection object using the RepositoryProcessorInterface interface.
        $mockFedoraRepositoryProcessorConnection = Mockery::mock(\Processproquest\Repository\RepositoryProcessorInterface::class)->makePartial();
        $mockFedoraRepositoryProcessorConnection->shouldReceive('getNextPid')->andReturn("bc-ir:9999999");
        $mockFedoraRepositoryProcessorConnection->shouldReceive('constructObject')->andReturn($this->mockAbstractFedoraObject);
        $mockFedoraRepositoryProcessorConnection->shouldReceive('ingestObject')->andReturnArg(0);
        $mockFedoraRepositoryProcessorConnection->shouldReceive('getObject')->andReturn($this->mockAbstractFedoraObject);
        $mockFedoraRepositoryProcessorConnection->shouldReceive('constructDatastream')->andReturn($this->mockAbstractFedoraDatastream);
        $mockFedoraRepositoryProcessorConnection->shouldReceive('ingestDatastream')->andReturn(true);
        $mockFedoraRepositoryProcessorConnection->shouldReceive('getDatastream')->andReturn($this->mockAbstractFedoraDatastream);

        return $mockFedoraRepositoryProcessorConnection;
    }

    /**
     * Create a mock FedoraRecordProcessor object.
     * 
     * @return object A mock FedoraRecordProcessor object.
     */
    public function createMockFedoraRecordProcessor() {
        $mockFedoraRecordProcessor = Mockery::mock(\Processproquest\Record\FedoraRecordProcessor::class)->makePartial();
        $mockFedoraRecordProcessor->shouldReceive('downloadETD')->andReturn(true);
        $mockFedoraRecordProcessor->shouldReceive('parseETD')->andReturn(true);
        $mockFedoraRecordProcessor->shouldReceive('processETD')->andReturn(true);
        $mockFedoraRecordProcessor->shouldReceive('generateDatastreams')->andReturn(true);
        $mockFedoraRecordProcessor->shouldReceive('ingestETD')->andReturn(true);

        return $mockFedoraRecordProcessor;
    }

    /**
     * Create a mock AbstractFedoraDatastream (FedoraDatastream|NewFedoraDatastream) object.
     * 
     * @return object A mock AbstractFedoraDatastream object.
     */
    public function generateMockAbstractFedoraDatastream() {
        $mockAbstractFedoraDatastreamObject = Mockery::mock('AbstractFedoraDatastream')->makePartial();
        $mockAbstractFedoraDatastreamObject->shouldReceive("setContentFromFile")->andReturn(null);

        return $mockAbstractFedoraDatastreamObject;
    }

    /**
     * Create a mock AbstractFedoraObject (FedoraObject|NewFedoraObject) object.
     * 
     * @return object A mock AbstractFedoraObject object.
     */
    public function generateMockAbstractFedoraObject() {
        // Create a mock FedoraRelsExt object.
        $mockFedoraRelsExtObject = Mockery::mock('FedoraRelsExt')->makePartial();
        // See: https://github.com/Islandora/tuque/blob/1.x/FedoraRelationships.php#L628-L630
        $mockFedoraRelsExtObject->shouldReceive("add")->andReturn(null);

        $mockAbstractFedoraObject = Mockery::mock('AbstractFedoraObject')->makePartial();
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L228
        $mockAbstractFedoraObject->relationships = $mockFedoraRelsExtObject;

        return $mockAbstractFedoraObject;
    }

    /**
     * Generate a new Processproquest object.
     * 
     * @param array $customSettings Optional array of settings.
     * 
     * @return object A Processproquest object.
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
     * @param string $className The name of the class.
     * @param string $property The name of the property to access.
     * 
     * @return object The reflected class object.
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
     * @param string $className The name of the class.
     * @param string $methodName The name of the method to access.
     * 
     * @return object The reflected class method.
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