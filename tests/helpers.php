<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../src/Processproquest.php";
require __DIR__ . "/../src/FedoraRepository.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class TestHelpers extends TestCase {
    protected $configurationArray = [];
    protected $configurationFile = null;
    protected $settings = [];
    protected $logger = null;
    protected $ftpConnection = null;
    protected $fedoraConnection = null;
    protected $debug = null;
    //protected $listOfETDs = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip'];

    public function __construct($name) {
        $this->configurationFile = "testConfig.ini";
        $this->configurationArray = $this->readConfigurationFile($this->configurationFile);
        $this->configurationFile = $this->configurationArray["file"];
        $this->settings = $this->configurationArray["settings"];
        $this->logger = $this->createLogger($this->settings);
        $this->debug = true;
    }

    /**
     * Create a logger object.
     * 
     * @param array $configurationSettings the settings to use.
     * 
     * @return object the logger object.
     */
    public function createLogger($configurationSettings){
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
    public function readConfigurationFile($configurationFileLocation) {
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
    public function alterConfigurationSettings($updatedSettings) {
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
    public function alterConfigurationArray($updatedSettings) {
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
    public function createFTPConnection($configurationSettings) {
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
    public function createMockFedoraConnection() {
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
    public function createMockFedoraRecord() {
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
    public function generateProcessproquestObject() {
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