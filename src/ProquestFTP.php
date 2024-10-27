<?php declare(strict_types=1);
namespace Processproquest\FTP;

/**
 * FTP connection interface.
 */
interface FileStorageInterface {
    public function connect(string $url);
    public function login(string $userName, string $userPassword);
    public function moveFile(string $fileName, string $fromDir, string $toDir);
    public function getFileList(string $dir);
    public function getFile(string $filePath, string $fileName);
    public function changeDir(string $dir);
}

/**
 * FTP Service interface.
 */
interface FTPServiceAdapterInterface {
    public function ftp_service_getURL();
    public function ftp_service_connect(string $url);
    public function ftp_service_login(string $userName, string $userPassword);
    public function ftp_service_moveFile(string $fileName, string $fromDir, string $toDir);
    public function ftp_service_getFileList(string $dir);
    public function ftp_service_getFile(string $filePath, string $fileName);
    public function ftp_service_changeDir(string $dir);
}

/**
 * Adapter to connect to the PHP built-in ftp functions.
 * 
 * @codeCoverageIgnore
 */
class FTPServiceAdapter implements FTPServiceAdapterInterface {
    public $ftpConnection = null;
    protected $ftpURL = "";
    static $FTP_PORT = 21;
    static $FTP_TIMEOUT_SEC = 150;

    /**
     * Class constructor.
     * 
     * @param string $url The FTP service url.
     */
    public function __construct(string $url){
        $this->ftpURL = $url;

        if (empty($url) === true) {
            return false;
        }

        $this->ftpConnection = $this->ftp_service_connect($this->ftpURL, self::$FTP_PORT, self::$FTP_TIMEOUT_SEC);
    }

    /**
     * Getter method for ftpURL.
     * 
     * @return string the ftpURL property.
     */
    public function ftp_service_getURL() {
        return $this->ftpURL;
    }

    /**
     * Connect to the FTP server.
     * Uses the built-in ftp_connect() function.
     * 
     * @param string $url The FTP server url.
     * 
     * @return boolean $ret the status.
     */
    public function ftp_service_connect(string $ftpURL) {
        // INFO: ftp_connect() Returns an FTP\Connection instance on success, or false on failure.
        return ftp_connect($ftpURL, self::$FTP_PORT, self::$FTP_TIMEOUT_SEC);
    }

    /**
     * Login to the FTP server. 
     * Uses the built-in ftp_login() function.
     * 
     * @param string $userName the FTP user name.
     * @param string $userPassword the the FTP user password.
     * 
     * @return boolean $ret the status.
     */
    public function ftp_service_login(string $userName, string $userPassword) {
        // INFO: ftp_login() Returns true on success or false on failure. 
        //       If login fails, PHP will also throw a warning.
        // Suppress warning by using @ error control operator.
        return @ftp_login($this->ftpConnection, $userName, $userPassword);
    }

    /** 
     * Move a file.
     * Uses the built-in ftp_rename() function.
     * 
     * @param string $fileName the name of the file to move.
     * @param string $fromDir move the file from this location.
     * @param string $toDir move the file into this location.
     * 
     * @return boolean $ret the status.
     */
    public function ftp_service_moveFile(string $fileName, string $fromDir, string $toDir) {
        $filenameFullFromPath = "{$fromDir}/{$fileName}";
        $filenameFullToPath = "{$toDir}/{$fileName}";

        // INFO: ftp_rename() returns true on success or false on failure.
        return ftp_rename($this->ftpConnection, $filenameFullFromPath, $filenameFullToPath);
    }

    /**
     * Get a file listing.
     * Uses the built-in ftp_nlist() function.
     * 
     * @param string $dir get the file listing from this directory. This be formatted as a regular expression.
     * 
     * @return array $allFiles an array of filename or false on error.
     */
    public function ftp_service_getFileList(string $dir) {
        // INFO: ftp_nlist() Returns an array of filenames from the specified 
        //       directory on success or false on error.
        return ftp_nlist($this->ftpConnection, $dir);
    }

    /**
     * Get a file.
     * Uses the built-in ftp_get() function.
     * 
     * @param string $local_filename the name of the local file to write to.
     * @param string $remote_filename the name of the remote file to get.
     * 
     * @return boolean $ret the status.
     */
    public function ftp_service_getFile(string $local_filename, string $remote_filename) {
        // INFO: ftp_get() Returns true on success or false on failure.
        return ftp_get($this->ftpConnection, $local_filename, $remote_filename, FTP_BINARY);
    }

    /**
     * Change directories on the FTP server.
     * Uses the built-in ftp_chdir() function.
     * 
     * @param string $dir the directory to change into.
     * 
     * @return boolean $ret the status.
     */
    public function ftp_service_changeDir(string $dir) {
        // INFO: ftp_chdir() Returns true on success or false on failure. 
        //       If changing directory fails, PHP will also throw a warning.
        // Suppress warning by using @ error control operator.
        return @ftp_chdir($this->ftpConnection, $dir);
    }
}


/**
 * Manages an FTP connection.
 */
class ProquestFTP implements FileStorageInterface {
    private $ftpConnection = null;
    private $service = null;
    private $ftpURL = "";

    /**
     * Class constructor.
     * 
     * @param string $url The FTP url.
     * @param object $service An FTP service adapter.
     * 
     * @throws Exception if a connection to the FTP server can't be made.
     */
    public function __construct($service){
        $this->service = $service;
        $this->ftpURL = $this->service->ftp_service_getURL();

        if ( (empty($this->ftpURL) === true) ) {
            // Can't connect with an empty URL value.
            $errorMessage = "Can't connect to FTP server: The [ftp] 'url' setting isn't set or is incorrect.";
            throw new \Exception($errorMessage);
        }

        // Connect to the FTP server. 
        $this->ftpConnection = $this->connect($this->ftpURL);

        if ($this->ftpConnection === false) {
            // Can't connect with an empty URL value.
            $errorMessage = "Can't connect to FTP server: Check your [ftp] settings or see if the FTP server is available.";
            throw new \Exception($errorMessage);
        }
    }

    /**
     * Connect to the FTP server.
     * 
     * @param string $url The FTP server url.
     * 
     * @return boolean $ret the status.
     */
    public function connect(string $url) {
        $result = $this->service->ftp_service_connect($url);

        return $result;
    }

    /**
     * Login to the FTP server.
     * 
     * @param string $userName the FTP user name.
     * @param string $userPassword the the FTP user password.
     * 
     * @return boolean $ret the status.
     */
    public function login(string $userName, string $userPassword) {
        $result = $this->service->ftp_service_login($userName, $userPassword);

        return $result;
    }

    /** 
     * Move a file.
     * 
     * @param string $fileName the name of the file to move.
     * @param string $fromDir move the file from this location.
     * @param string $toDir move the file into this location.
     * 
     * @return boolean $ret the status.
     */
    public function moveFile(string $fileName, string $fromDir, string $toDir) {
        $result = $this->service->ftp_service_moveFile($fileName, $fromDir, $toDir);

        return $result;
    }

    /**
     * Get a file listing.
     * 
     * @param string $dir get the file listing from this directory. This be formatted as a regular expression.
     * 
     * @return array $allFiles an array of filename or false on error.
     */
    public function getFileList(string $dir) {
        $allFiles = $this->service->ftp_service_getFileList($dir);

        return $allFiles;
    }

    /**
     * Get a file.
     * 
     * @param string $local_filename the name of the local file to write to.
     * @param string $remote_filename the name of the remote file to get.
     * 
     * @return boolean $ret the status.
     */
    public function getFile(string $local_filename, string $remote_filename) {
        $result = $this->service->ftp_service_getFile($local_filename, $remote_filename);

        return $result;
    }

    /**
     * Change directories on the FTP server.
     * 
     * @param string $dir the directory to change into.
     * 
     * @return boolean $ret the status.
     */
    public function changeDir(string $dir) {
        $result = $this->service->ftp_service_changeDir($dir);

        return $result;
    }
}

?>
