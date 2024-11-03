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

#[CoversClass(\Processproquest\FTP\ProquestFTP::class)]
#[CoversMethod(\Processproquest\FTP\ProquestFTP::class, "connect")]
#[CoversMethod(\Processproquest\FTP\ProquestFTP::class, "login")]
#[CoversMethod(\Processproquest\FTP\ProquestFTP::class, "moveFile")]
#[CoversMethod(\Processproquest\FTP\ProquestFTP::class, "getFileList")]
#[CoversMethod(\Processproquest\FTP\ProquestFTP::class, "getFile")]
#[CoversMethod(\Processproquest\FTP\ProquestFTP::class, "changeDir")]
final class ProquestFTPTest extends TestCase {

    protected function setUp(): void {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $configurationFile = "testConfig.ini";

        $this->helper = new \Processproquest\test\TestHelpers("test");
        $this->configurationFile = $configurationFile;
        $this->configurationSettings = $this->helper->readConfigurationFile($configurationFile);
        $this->ftpURL = $this->configurationSettings['ftp']['server'];
        $this->fileList = ['etdadmin_upload_100000.zip', 'etdadmin_upload_200000.zip'];
    }

    protected function tearDown(): void {
        \Mockery::close();
        $this->helper = null;
    }

    /**
     * Create a mock FTPService object.
     * 
     * @return object A mock FTPService object.
     */
    protected function generateMockFTPService(): object {
        // Create a mock FTPService object using the FTPServiceInterface interface. 
        $mockFTPService = \Mockery::mock('Processproquest\FTP\FTPServiceInterface')->makePartial();
        $mockFTPService->shouldReceive('ftp_service_getURL')->andReturn($this->ftpURL);
        $mockFTPService->shouldReceive('ftp_service_connect')->andReturn(true);
        $mockFTPService->shouldReceive('ftp_service_login')->andReturn(true);
        $mockFTPService->shouldReceive('ftp_service_moveFile')->andReturn(true);
        $mockFTPService->shouldReceive('ftp_service_getFileList')->andReturn($this->fileList);
        $mockFTPService->shouldReceive('ftp_service_getFile')->andReturn(true);
        $mockFTPService->shouldReceive('ftp_service_changeDir')->andReturn(true);

        return $mockFTPService;
    }

    #[Test]
    #[TestDox('Checks for a failure when passed an FTP service object lacking a url string')]
    public function loginWithEmptyURL(): void {
        // Create a FTPServicePHPAdapter object with an empty url argument.
        $ftpService = new \Processproquest\FTP\FTPServicePHPAdapter("");

        // Expect an exception.
        $this->expectException(\Processproquest\FTP\FTPConnectionException::class);

        // Create a ProquestFTP object.
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($ftpService);
    }

    #[Test]
    #[TestDox('Checks for a thrown exception when the ProquestFTP constructor calls on connect(), which returns false')]
    public function connectFailure(): void {
        // Create a custom mock FTPService object using the FTPServiceInterface interface that 
        // returns false for ftp_service_connect().
        $mockFTPService = \Mockery::mock('Processproquest\FTP\FTPServiceInterface')->makePartial();
        $mockFTPService->shouldReceive('ftp_service_getURL')->andReturn($this->ftpURL);
        $mockFTPService->shouldReceive('ftp_service_connect')->andReturn(false);

        // Expect an exception.
        $this->expectException(\Processproquest\FTP\FTPConnectionException::class);
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($mockFTPService);
    }

    #[Test]
    #[TestDox('Checks the login() method')]
    public function login(): void {
        // Create a mock FTPService object.
        $mockFTPService = $this->generateMockFTPService();

        // Create a ProquestFTP object.
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($mockFTPService);

        $userName = "foo";
        $userPass = "bar";
        $result = $proquestFTPObject->login($userName, $userPass);
        
        $this->assertTrue($result, "Expected login() to return true");
    }

    #[Test]
    #[TestDox('Checks the moveFile() method')]
    public function moveFile(): void {
        // Create a mock FTPService object.
        $mockFTPService = $this->generateMockFTPService();

        // Create a ProquestFTP object.
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($mockFTPService);

        $fileName = "foo";
        $fromDir = "bar";
        $toDir = "baz";
        $result = $proquestFTPObject->moveFile($fileName, $fromDir, $toDir);
        
        $this->assertTrue($result, "Expected moveFile() to return true");
    }

    #[Test]
    #[TestDox('Checks the getFileList() method')]
    public function getFileList(): void {
        // Create a mock FTPService object.
        $mockFTPService = $this->generateMockFTPService();

        // Create a ProquestFTP object.
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($mockFTPService);

        $fileDir = "foo";
        $result = $proquestFTPObject->getFileList($fileDir);
        
        $this->assertEquals($this->fileList, $result, "Expected an array of file names");
    }

    #[Test]
    #[TestDox('Checks the getFile() method')]
    public function getFile(): void {
        // Create a mock FTPService object.
        $mockFTPService = $this->generateMockFTPService();

        // Create a ProquestFTP object.
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($mockFTPService);

        $local_filename = "foo";
        $remote_filename = "bar";
        $result = $proquestFTPObject->getFile($local_filename, $remote_filename);
        
        $this->assertTrue($result, "Expected getFile() to return true");
    }

    #[Test]
    #[TestDox('Checks the changeDir() method')]
    public function changeDir(): void {
        // Create a mock FTPService object.
        $mockFTPService = $this->generateMockFTPService();

        // Create a ProquestFTP object.
        $proquestFTPObject = new \Processproquest\FTP\ProquestFTP($mockFTPService);

        $local_filename = "foo";
        $result = $proquestFTPObject->changeDir($local_filename);
        
        $this->assertTrue($result, "Expected changeDir() to return true");
    }
}