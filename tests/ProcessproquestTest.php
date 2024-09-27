<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require __DIR__ . "/../Processproquest.php";

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class ProcessproquestTest extends TestCase
{
    // public function testCanBeCreatedFromValidEmail(): void
    // {
    //     $string = 'user@example.com';
    //     $email = Email::fromString($string);
    //     $this->assertSame($string, $email->asString());
    // }

    // public function testCannotBeCreatedFromInvalidEmail(): void
    // {
    //     $this->expectException(InvalidArgumentException::class);
    //     Email::fromString('invalid');
    // }

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
        $consoleOutput = new StreamHandler('php://stdout', Level::Debug);
        $consoleOutput->setFormatter($formatter);
        $logger->pushHandler($consoleOutput);
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

    public function testNormalizeString(): void {
        $configurationFile = "testConfig.ini";
        $debug = true;

        $configurationArray = $this->getConfigFile($configurationFile);

        $logger = new Logger("Processproquest");

        $testString = 'This_ is#a test"string';
        $expectedString = 'This-isa-teststring';

        $method = new ReflectionMethod(
            'Processproquest', 'normalizeString'
        );
        $method->setAccessible(TRUE);

        $processObj = new Processproquest($configurationArray, $logger, $debug);

        $output = $method->invokeArgs($processObj, array($testString));

        echo "\nSent this input:      {$testString}\n";
        echo "Received this output: {$output}\n";
        echo "Expected this output: {$expectedString}\n\n";

        $this->assertSame($expectedString, $output);
    }

    public function testWriteLog(): void {
        $configurationFile = "testConfig.ini";
        $debug = true;

        $configurationArray = $this->getConfigFile($configurationFile);

        $logger = new Logger("Processproquest");

        $testString = 'This is a test string';
        $expectedString = '(invokeArgs) This is a test string';

        $method = new ReflectionMethod( 
            'Processproquest', 'writeLog'
        );
        $method->setAccessible(TRUE);

        $processObj = new Processproquest($configurationArray, $logger, $debug);

        $output = $method->invokeArgs($processObj, array($testString));

        echo "\nSent this input:      {$testString}\n";
        echo "Received this output: {$output}\n";
        echo "Expected this output: {$expectedString}\n\n";

        $this->assertSame($output, $expectedString);
    }
}