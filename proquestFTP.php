<?php

//error_reporting(E_ALL);

/**
 * Opens an FTP connection.
 */
class proquestFTP {
    public $conn; 

    // TODO: handle class instanciation with no $url param.

    /**
     * Class constructor.
     * 
     * @param string $url
     */
    public function __construct($url){ 
        $this->conn = ftp_connect($url); 
    } 
    
    /**
     * Overload PHP magic method for this class.
     * 
     * @param string $func
     * @param array $a
     */
    public function __call($func,$a){ 
        if(strstr($func,'ftp_') !== false && function_exists($func)){ 
            array_unshift($a,$this->conn); 
            return call_user_func_array($func,$a); 
        }else{ 
            die("$func is not a valid FTP function"); 
        } 
    } 
}

?>
