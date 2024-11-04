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

#[CoversClass(\Processproquest\Repository\FedoraRepository::class)]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "getNextPid")]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "constructObject")]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "ingestObject")]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "getObject")]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "constructDatastream")]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "ingestDatastream")]
#[CoversMethod(\Processproquest\Repository\FedoraRepository::class, "getDatastream")]
final class FedoraRepositoryTest extends TestCase {

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->helper = new \Processproquest\test\TestHelpers("test");
        $this->configurationFile = $configurationFile;
        $this->configurationSettings = $this->helper->readConfigurationFile($configurationFile);
        $this->nameSpace = "bc-ir";
        $this->nextPIDNumber = "123456789";

        // Create a mock AbstractFedoraObject (FedoraObject|NewFedoraObject) object.
        $this->mockAbstractFedoraObject = $this->helper->generateMockAbstractFedoraObject();

        // Create a mock AbstractFedoraDatastream (FedoraDatastream|NewFedoraDatastream) object.
        $this->mockAbstractFedoraDatastream = $this->helper->generateMockAbstractFedoraDatastream();
    }

    protected function tearDown(): void {
        \Mockery::close();
        $this->helper = null;
        $this->mockAbstractFedoraObject = null;
        $this->mockAbstractFedoraDatastream = null;
    }

    /**
     * Create a mock RepositoryService object.
     * 
     * TODO: move to helpers.php
     * 
     * @return object A mock RepositoryService object.
     */
    protected function generateMockRepositoryService(): object {
        // Create a mock RepositoryService object using the RepositoryServiceInterface interface. 
        $mockRepositoryService = \Mockery::mock('Processproquest\Repository\RepositoryServiceInterface')->makePartial();
        $mockRepositoryService->shouldReceive('repository_service_getNextPid')->andReturnUsing(
            function($nameSpace){
                return "{$nameSpace}:{$this->nextPIDNumber}";
            }
        );
        $mockRepositoryService->shouldReceive('repository_service_constructObject')->andReturn($this->mockAbstractFedoraObject);
        $mockRepositoryService->shouldReceive('repository_service_ingestObject')->andReturnArg(0);
        $mockRepositoryService->shouldReceive('repository_service_getObject')->andReturn($this->mockAbstractFedoraObject);
        $mockRepositoryService->shouldReceive('repository_service_constructDatastream')->andReturn($this->mockAbstractFedoraDatastream);
        $mockRepositoryService->shouldReceive('repository_service_ingestDatastream')->andReturn(true);
        $mockRepositoryService->shouldReceive('repository_service_getDatastream')->andReturn($this->mockAbstractFedoraDatastream);

        return $mockRepositoryService;
    }

    #[Test]
    #[TestDox('Checks the getNextPid() method')]
    public function getNextPid(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $result = $proquestFTPObject->getNextPid($this->nameSpace);
        $expectedResult = "{$this->nameSpace}:{$this->nextPIDNumber}";
        
        $this->assertEquals($result, $expectedResult, "Expected getNextPid() to return the string {$expectedResult}");
    }

    #[Test]
    #[TestDox('Checks the constructObject() method')]
    public function constructObject(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $pid = "{$this->nameSpace}:{$this->nextPIDNumber}";
        $result = $proquestFTPObject->constructObject($pid);
        
        $this->assertIsObject($result, "Expected constructObject() to return an object");
    }

    #[Test]
    #[TestDox('Checks the getObject() method')]
    public function getObject(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $pid = "bc-ir:{$this->nextPIDNumber}";
        //$repoObject = $proquestFTPObject->constructObject($pid);
        $result = $proquestFTPObject->getObject($pid);
        
        $this->assertIsObject($result, "Expected getObject() to return an object");
    }

    #[Test]
    #[TestDox('Checks the getObject() method bubbles up an exception')]
    public function getObjectBubbleException(): void {
        // Create a mock RepositoryService object using the RepositoryServiceInterface interface.
        // Set repository_service_ingestDatastream() to return an exception.
        $mockRepositoryService = \Mockery::mock('Processproquest\Repository\RepositoryServiceInterface')->makePartial();
        $mockRepositoryService->shouldReceive('repository_service_getNextPid')->andReturnUsing(
            function($nameSpace){
                return "{$nameSpace}:{$this->nextPIDNumber}";
            }
        );
        $mockRepositoryService->shouldReceive('repository_service_constructObject')->andReturn($this->mockAbstractFedoraObject);
        $mockRepositoryService->shouldReceive('repository_service_ingestObject')->andReturnArg(0);
        $mockRepositoryService->shouldReceive('repository_service_getObject')->once()->andThrow(new \Processproquest\Repository\PPRepositoryServiceException("FOO"));
        $mockRepositoryService->shouldReceive('repository_service_constructDatastream')->andReturn($this->mockAbstractFedoraDatastream);
        $mockRepositoryService->shouldReceive('repository_service_ingestDatastream')->andReturn(true);
        $mockRepositoryService->shouldReceive('repository_service_getDatastream')->andReturn($this->mockAbstractFedoraDatastream);

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $pid = "bc-ir:{$this->nextPIDNumber}";
        // $repoObject = $proquestFTPObject->constructObject($pid);

        // Expect an exception. getObject() throws \Processproquest\Repository\PPRepositoryException
        $this->expectException(\Processproquest\Repository\PPRepositoryException::class);
        $result = $proquestFTPObject->getObject($pid);
    }

    #[Test]
    #[TestDox('Checks the ingestObject() method')]
    public function ingestObject(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $pid = "bc-ir:{$this->nextPIDNumber}";
        $repoObject = $proquestFTPObject->constructObject($pid);
        $result = $proquestFTPObject->ingestObject($repoObject);
        
        $this->assertIsObject($result, "Expected ingestObject() to return an object");
    }

    #[Test]
    #[TestDox('Checks the getDatastream() method')]
    public function getDatastream(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        //$pid = "bc-ir:{$this->nextPIDNumber}";
        $datastreamID = "ARCHIVE";
        // $repoObject = $proquestFTPObject->constructObject($pid);
        $result = $proquestFTPObject->getDatastream($datastreamID);
        
        $this->assertIsObject($result, "Expected getDatastream() to return an object");
    }

    #[Test]
    #[TestDox('Checks the ingestDatastream() method')]
    public function ingestDatastream(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a mock Object.
        $genericObject = new \stdClass();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $pid = "bc-ir:{$this->nextPIDNumber}";
        // $repoObject = $proquestFTPObject->constructObject($pid);
        $result = $proquestFTPObject->ingestDatastream($genericObject);
        
        $this->assertTrue($result, "Expected ingestDatastream() to return true");
    }

    #[Test]
    #[TestDox('Checks the ingestDatastream() method bubbles up an exception')]
    public function ingestDatastreamBubbleException(): void {
        // Create a mock Object.
        $genericObject = new \stdClass();

        // Create a mock RepositoryService object using the RepositoryServiceInterface interface.
        // Set repository_service_ingestDatastream() to return an exception.
        $mockRepositoryService = \Mockery::mock('Processproquest\Repository\RepositoryServiceInterface')->makePartial();
        $mockRepositoryService->shouldReceive('repository_service_getNextPid')->andReturnUsing(
            function($nameSpace){
                return "{$nameSpace}:{$this->nextPIDNumber}";
            }
        );
        $mockRepositoryService->shouldReceive('repository_service_constructObject')->andReturn($this->mockAbstractFedoraObject);
        $mockRepositoryService->shouldReceive('repository_service_ingestObject')->andReturnArg(0);
        $mockRepositoryService->shouldReceive('repository_service_getObject')->andReturn($this->mockAbstractFedoraObject);
        $mockRepositoryService->shouldReceive('repository_service_constructDatastream')->andReturn($this->mockAbstractFedoraDatastream);
        $mockRepositoryService->shouldReceive('repository_service_ingestDatastream')->once()->andThrow(new \Processproquest\Repository\PPRepositoryServiceException("FOO"));
        $mockRepositoryService->shouldReceive('repository_service_getDatastream')->andReturn($this->mockAbstractFedoraDatastream);

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $pid = "bc-ir:{$this->nextPIDNumber}";
        // $repoObject = $proquestFTPObject->constructObject($pid);

        // Expect an exception. ingestDatastream() throws \Processproquest\Repository\PPRepositoryException
        $this->expectException(\Processproquest\Repository\PPRepositoryException::class);
        $result = $proquestFTPObject->ingestDatastream($genericObject);
    }

    #[Test]
    #[TestDox('Checks the constructDatastream() method')]
    public function constructDatastream(): void {
        // Create a mock RepositoryService object.
        $mockRepositoryService = $this->generateMockRepositoryService();

        // Create a FedoraRepository object.
        $proquestFTPObject = new \Processproquest\Repository\FedoraRepository($mockRepositoryService);

        $id = "ARCHIVE";
        # $control_group = "M";
        $result = $proquestFTPObject->constructDatastream($id);
        
        $this->assertIsObject($result, "Expected constructDatastream() to return an object");
    }
    
}