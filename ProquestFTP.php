<?php

//error_reporting(E_ALL);

/**
 * FTP connection template.
 */
interface FTPTemplate {
    public function login(string $userName, string $userPassword);
    public function moveFile(string $fileName, string $fromDir, string $toDir);
    public function getFileList(string $dir);
    public function getFile(string $filePath, string $fileName);
    public function changeDir(string $dir);
}

/**
 * Opens an FTP connection.
 */
class ProquestFTP implements FTPTemplate {
    public $conn = null;
    static $FTP_PORT = 21;
    static $FTP_TIMEOUT_SEC = 150;

    /**
     * Class constructor.
     * 
     * @param string $url the FTP url.
     */
    public function __construct(string $url){
        // INFO: ftp_connect() Returns an FTP\Connection instance on success, or false on failure.
        $this->conn = ftp_connect($url, self::$FTP_PORT, SELF::$FTP_TIMEOUT_SEC);
    }
    
    /**
     * Overload PHP magic method for this class.
     * 
     * @param string $func the name of the function to call.
     * @param array $a an array of arguments.
     * 
     * @return the return value of the callback, or false on error.
     */
    public function __call(string $func, array $a) { 
        if(strstr($func,'ftp_') !== false && function_exists($func)){ 
            array_unshift($a,$this->conn);
            return call_user_func_array($func,$a);
        } else {
            // TODO: handle this error.
            die("{$func} is not a valid FTP function.");
        }
    }

    /**
     * Login to the FTP server.
     * 
     * @param string $userName the FTP user name.
     * @param string $userPassword the the FTP user password.
     * 
     * @return boolean $return the status.
     */
    public function login(string $userName, string $userPassword) {
        // INFO: ftp_login() Returns true on success or false on failure. 
        //       If login fails, PHP will also throw a warning.
        $return = ftp_login($this->conn, $userName, $userPassword);
        return $return;
    }

    /** 
     * Move a file.
     * 
     * @param string $fileName the name of the file to move.
     * @param string $fromDir move the file from this location.
     * @param string $toDir move the file into this location.
     * 
     * @return boolean $return the status.
     */
    public function moveFile(string $fileName, string $fromDir, string $toDir) {
        // INFO: ftp_rename() returns true on success or false on failure.
        $filenameFullPath = "{$fromDir}/{$fileName}";
        $return = ftp_rename($this->conn, $filenameFullPath, $toDir);
        return $return;
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
     * @return boolean $return the status.
     */
    public function getFile(string $filePath, string $fileName) {
        // INFO: ftp_get() Returns true on success or false on failure.
        $return = ftp_get($this->conn, $filePath, $fileName, FTP_BINARY);
        return $return;
    }

    /**
     * Change directories on the FTP server.
     * 
     * @param string $dir the directory to change into.
     * 
     * @return boolean $return the status.
     */
    public function changeDir(string $dir) {
        // INFO: ftp_chdir() Returns true on success or false on failure. 
        //       If changing directory fails, PHP will also throw a warning.
        $return = ftp_chdir($this->conn, $dir);
        
        return $return;
    }
}

?>
