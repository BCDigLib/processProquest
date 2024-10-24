<?php declare(strict_types=1);
namespace Processproquest\FTP;

/**
 * FTP connection template.
 */
interface FileStorageTemplate {
    public function login(string $userName, string $userPassword);
    public function moveFile(string $fileName, string $fromDir, string $toDir);
    public function getFileList(string $dir);
    public function getFile(string $filePath, string $fileName);
    public function changeDir(string $dir);
}

/**
 * Opens an FTP connection.
 */
class ProquestFTP implements FileStorageTemplate {
    public $conn = null;
    static $FTP_PORT = 21;
    static $FTP_TIMEOUT_SEC = 150;

    /**
     * Class constructor.
     * 
     * @param string $url the FTP url.
     * 
     * @throws Exception if a connection to the FTP server can't be made.
     */
    public function __construct(string $url){
        if ( (empty($url) === true) ) {
            // Can't connect with an empty URL value.
            $errorMessage = "Can't connect to FTP server: The [ftp] 'url' setting isn't set or is incorrect.";
            throw new \Exception($errorMessage);
        }

        // INFO: ftp_connect() Returns an FTP\Connection instance on success, or false on failure.
        $this->conn = ftp_connect($url, self::$FTP_PORT, self::$FTP_TIMEOUT_SEC);

        if ($this->conn === false) {
            // Can't connect with an empty URL value.
            $errorMessage = "Can't connect to FTP server: Check your [ftp] settings or see if the FTP server is available.";
            throw new \Exception($errorMessage);
        }
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
        // INFO: ftp_login() Returns true on success or false on failure. 
        //       If login fails, PHP will also throw a warning.
        // Suppress warning by using @ error control operator.
        $ret = @ftp_login($this->conn, $userName, $userPassword);

        return $ret;
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
        // INFO: ftp_rename() returns true on success or false on failure.
        $filenameFullPath = "{$fromDir}/{$fileName}";
        $ret = ftp_rename($this->conn, $filenameFullPath, $toDir);

        return $ret;
    }

    /**
     * Get a file listing.
     * 
     * @param string $dir get the file listing from this directory. This be formatted as a regular expression.
     * 
     * @return mixed $allFiles an array of filename or false on error.
     */
    public function getFileList(string $dir) {
        // INFO: ftp_nlist() Returns an array of filenames from the specified directory on success or false on error.
        $allFiles = ftp_nlist($this->conn, $dir);

        return $allFiles;
    }

    /**
     * Get a file.
     * 
     * @param string $fileName the name of the file to get.
     * @param string $dir the file lives in this directory.
     * 
     * @return boolean $ret the status.
     */
    public function getFile(string $filePath, string $fileName) {
        // INFO: ftp_get() Returns true on success or false on failure.
        $ret = ftp_get($this->conn, $filePath, $fileName, FTP_BINARY);

        return $ret;
    }

    /**
     * Change directories on the FTP server.
     * 
     * @param string $dir the directory to change into.
     * 
     * @return boolean $ret the status.
     */
    public function changeDir(string $dir) {
        // INFO: ftp_chdir() Returns true on success or false on failure. 
        //       If changing directory fails, PHP will also throw a warning.
        // Suppress warning by using @ error control operator.
        $ret = @ftp_chdir($this->conn, $dir);

        return $ret;
    }
}

?>
