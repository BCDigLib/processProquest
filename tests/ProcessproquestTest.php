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
        $fileOutput = new StreamHandler("{$logLocation}{$logFileName}", Level::Debug);
        $fileOutput->setFormatter($formatter);
        $logger->pushHandler($fileOutput);

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

    public function testWriteLog(): void {
        $configurationFile = "testConfig.ini";
        $debug = true;

        $configurationArray = $this->getConfigFile($configurationFile);

        $logger = new Logger("Processproquest");

        $process = new Processproquest($configurationArray, $debug, $logger);

        $string = 'This is a test string';

        $method = new ReflectionMethod(
            'Processproquest', 'writeLog'
        );
   
        $method->setAccessible(TRUE);

        // Error: Object of type ReflectionMethod is not callable
        $output = $method($string);

        $this->assertSame($string, $output);
    }
}