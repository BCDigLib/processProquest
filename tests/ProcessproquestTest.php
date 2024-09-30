<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../Processproquest.php";
//require __DIR__ . "/../ProquestFTP.php";

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
    protected $debug = null;

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

    protected function getConfigFile($configurationFile) {
        // Read in configuration settings.
        $configurationSettings = parse_ini_file($configurationFile, true);

        if (empty($configurationSettings)) {
            return NULL;
        }

        $configurationArray = array(
            "file" => $configurationFile,
            "settings" => $configurationSettings
        );

        return $configurationArray;
    }

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

    protected function createFTPConnection($configurationSettings) {
        $urlFTP = $configurationSettings['ftp']['server'];
        $ftpConnection = new ProquestFTP($urlFTP);

        return $ftpConnection;
    }

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->configurationArray = $this->getConfigFile($configurationFile);
        $this->configurationFile = $this->configurationArray["file"];
        $this->settings = $this->configurationArray["settings"];
        $this->logger = $this->createLogger($this->settings);
        $this->ftpConnection = $this->createFTPConnection($this->settings);
        $this->debug = true;
    }

    protected function tearDown(): void {
        $this->configurationArray = null;
        $this->logger = null;
    }

    public function testNormalizeString(): void {
        echo "\nThis test checks normalizeString() returns a normalized string.\n";

        // $configurationFile = "testConfig.ini";
        // $debug = true;
        // $configurationArray = $this->getConfigFile($configurationFile);
        // $logger = new Logger("Processproquest");

        $testString = 'This_ is#a test"string';
        $expectedString = 'This-isa-teststring';

        $method = new ReflectionMethod(
            'Processproquest', 'normalizeString'
        );
        $method->setAccessible(TRUE);

        $processObj = new Processproquest(
            $this->configurationArray, 
            $this->logger, $this->debug
        );

        $output = $method->invokeArgs($processObj, array($testString));

        echo "Sent this input:      {$testString}\n";
        echo "Received this output: {$output}\n";
        echo "Expected this output: {$expectedString}\n\n";

        $this->assertSame($expectedString, $output);
    }

    public function testWriteLog(): void {
        echo "\nThis test checks writeLog() returns a formatted log entry.\n";

        $testString = 'This is a test string';
        $expectedString = '(invokeArgs) This is a test string';

        $method = new ReflectionMethod( 
            'Processproquest', 'writeLog'
        );
        $method->setAccessible(TRUE);

        $processObj = new Processproquest($this->configurationArray, $this->logger, $this->debug);

        $output = $method->invokeArgs($processObj, array($testString));

        echo "Sent this input:      {$testString}\n";
        echo "Received this output: {$output}\n";
        echo "Expected this output: {$expectedString}\n\n";

        $this->assertSame($output, $expectedString);
    }

    public function testInitFTP(): void {
        echo "\nThis test checks initFTP() returns successfully.\n";
        $processObj = (new Processproquest($this->configurationArray, $this->logger, $this->debug))
                            ->setFTPConnection($this->ftpConnection);
        $return = $processObj->initFTP();
        echo "Expected: true\n";
        echo "Returned: " . ($return ? "true" : "false") . "\n";
        $this->assertSame($return, true);
    }

    public function testInitFTPConfigEmptyServerValue(): void {
        echo "\nThis test checks initFTP() returns an exception.\n";

        // Replace [ftp] "server" key with an empty string
        $updatedSettings = array(
            "ftp" => array("server" => ""),
        );

        $newSettings = $this->alterConfigArray($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;
        $processObj = (new Processproquest($this->configurationArray, $this->logger, $this->debug))
                            ->setFTPConnection($this->ftpConnection);
        $this->expectException(Exception::class);

        // This should return an exception.
        $return = $processObj->initFTP();
    }

    public function testGetFilesConfigEmptyLocaldirValue(): void {
        echo "\nThis test checks getFiles() returns an exception.\n";

        // Replace [ftp] "server" key with an empty string
        $updatedSettings = array(
            "ftp" => array("localdir" => ""),
        );

        $newSettings = $this->alterConfigArray($updatedSettings);
        $this->configurationArray["settings"] = $newSettings;
        $processObj = (new Processproquest($this->configurationArray, $this->logger, $this->debug))
                            ->setFTPConnection($this->ftpConnection);
        $this->expectException(Exception::class);

        // This should return an exception.
        $return = $processObj->getFiles();
    }
}