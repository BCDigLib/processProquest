<?php declare(strict_types=1);
namespace Processproquest\test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use \Mockery;

use phpmock\mockery\PHPMockery;
use phpmock\phpunit\PHPMock;

// Use TestHelpers class.
require_once(__DIR__ . "/helpers.php");

#[CoversClass(\Processproquest\Record\FedoraRecord::class)]
#[CoversMethod(\Processproquest\Record\FedoraRecord::class, "setStatus")]
final class FedoraRecordTest extends TestCase {

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->helper = new \Processproquest\test\TestHelpers("test");
        $this->configurationFile = $configurationFile;
        $this->configurationSettings = $this->helper->readConfigurationFile($configurationFile);
        $this->nameSpace = "bc-ir";
        $this->nextPIDNumber = "123456789";

        $this->logger = $this->helper->createLogger($this->configurationSettings);
        $this->fedoraConnection = $this->helper->createMockFedoraConnection($this->configurationSettings);
        $this->ftpConnection = $this->helper->createMockFTPConnection();
    }

    protected function tearDown(): void {
        \Mockery::close();
        $this->helper = null;
    }

    /**
     * Create a generic FedoraRecord object.
     * 
     * @param array $customSettings Optional array of settings.
     * 
     * @return object a FedoraRecord object.
     */
    protected function createFedoraRecordObject($customSettings = []) {
        if (empty($customSettings)) {
            $customSettings = $this->configurationSettings;
        }
        // Create a FedoraRecord object.
        $fedoraRecord = new \Processproquest\Record\FedoraRecord(
                                "foo",
                                $customSettings,
                                "zipfilename",
                                $this->fedoraConnection,
                                $this->ftpConnection,
                                $this->logger
                            );

        return $fedoraRecord;
    }

    #[Test]
    #[TestDox('Checks the setStatus() method')]
    public function setStatus(): void {
        // Create a FedoraRecord object.
        $fedoraRecord = $this->createFedoraRecordObject();

        // Get the current status value.
        $status = $fedoraRecord->STATUS;

        // Update status value.
        $newStatusValue = "hello";
        $fedoraRecord->setStatus("hello");

        // Get the updated status value.
        $result = $fedoraRecord->STATUS;
        
        $this->assertEquals($newStatusValue, $result, "Expected setStatus() to set the value of STATUS to be {$newStatusValue}");
    }
    
}