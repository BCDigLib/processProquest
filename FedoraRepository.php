<?php declare(strict_types=1);
namespace Processproquest\Repository;

/**
 * Record repository connection template.
 */
interface RecordRepositoryTemplate {
    public function getNextPid(string $nameSpace);
    public function constructObject(string $pid);
    public function getObject(string $pid);
    public function ingestObject(object $fedoraObj);
}

/**
 * Opens a Fedora Repository connection.
 */
class FedoraRepository implements RecordRepositoryTemplate {
    public $connection = null;
    public $api = null;
    public $api_m = null;
    public $repository = null;

    /**
     * Class constructor.
     * 
     * @param string $tuqueLibraryLocation the location of the Tuque library.
     * @param string $url the repository url.
     * @param string $userName the repository user name.
     * @param string $userPassword the repository user password.
     * 
     * @throws Exception if a connection to the Fedora repository can't be made.
     */
    public function __construct(string $tuqueLibraryLocation, string $url, string $userName, string $userPassword) {
        // Check that the Tuque library exists.
        if ( (empty($tuqueLibraryLocation) === true) || (is_dir($tuqueLibraryLocation) === false) ) {
            $errorMessage = "Can't locate the Tuque library: Please check that the [packages]->tuque setting is valid.";
            throw new Exception($errorMessage);
        }

        // Check that we have valid settings.
        if ( (empty($url) === true) || (empty($userName) === true) || (empty($userPassword) === true) ) {
            $errorMessage = "Can't connect to Fedora instance: One or more of the [fedora] settings aren't set or are invalid.";
            throw new Exception($errorMessage);
        }

        // Load Islandora/Fedora Tuque library.
        require_once "{$tuqueLibraryLocation}/RepositoryConnection.php";
        require_once "{$tuqueLibraryLocation}/FedoraApi.php";
        require_once "{$tuqueLibraryLocation}/FedoraApiSerializer.php";
        require_once "{$tuqueLibraryLocation}/Repository.php";
        require_once "{$tuqueLibraryLocation}/RepositoryException.php";
        require_once "{$tuqueLibraryLocation}/FedoraRelationships.php";
        require_once "{$tuqueLibraryLocation}/Cache.php";
        require_once "{$tuqueLibraryLocation}/HttpConnection.php";

        /**
         * Make Fedora repository connection.
         * 
         * INFO: Tuque library exceptions defined here:
         *       https://github.com/Islandora/tuque/blob/7.x-1.7/RepositoryException.php
         * 
         * INFO: Instantiating RepositoryConnection() throws a RepositoryException exception on error.
         */
        try {
            $this->connection = new \RepositoryConnection($url, $userName, $userPassword);
            $this->api = new \FedoraApi($this->connection);
            $this->repository = new \FedoraRepository($this->api, new \simpleCache());
            $this->api_m = $this->repository->api->m;
        } catch(Exception $e) {
            $errorMessage = "Can't connect to Fedora instance: " . $e->getMessage();
            throw new Exception($errorMessage);
        }
    }

    /**
     * Fetch the next PID from the repository.
     * 
     * @param string $nameSpace the namespace prefix.
     * 
     * @return string a PID string.
     */
    public function getNextPid(string $nameSpace) {
        // See: https://github.com/Islandora/tuque/blob/1.x/FedoraApi.php#L952-L963
        $numberOfPIDsToRequest = 1;
        $ret = $this->api_m->getNextPid($namespace, $numberOfPIDsToRequest);

        return $ret;
    }

    /**
     * Construct a repository object with a given PID.
     * 
     * @param string $pid a PID string to initialize a repository object.
     * 
     * @return object a repository object.
     */
    public function constructObject(string $pid) {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L174-L186
        $ret = $this->repository->constructObject($pid);

        return $ret;
    }

    /**
     * Retrieve an object using a PID string.
     * 
     * @param string $pid a PID string to lookup.
     * 
     * @return object a repository object.
     * 
     * @throws Exception if a repository record can't be found by $pid.
     */
    public function getObject(string $pid) {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L309-L323
        // INFO: getObject() throws a RepositoryException exception on error.
        try {
            $ret = $this->repository->getObject($pid);
        } catch(Exception $e) {
            $errorMessage = "Couldn't get an object with this pid: {$pid}. " . $e->getMessage();
            throw new Exception($errorMessage);
        }

        return $ret;
    }

    /**
     * Ingest a repository object.
     * 
     * @param object $fedoraObj a fully formed Fedora DAM object.
     * 
     * @return object the ingested object. 
     */
    public function ingestObject(object $fedoraObj) {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L282-L302
        $ret = $this->repository->ingestObject($fedoraObj);

        return $ret;
    }
}

?>
