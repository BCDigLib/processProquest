<?php declare(strict_types=1);
namespace Processproquest\Repository;

class RepositoryProcessorException extends \Exception {};
class RepositoryProcessorServiceException extends \Exception {};

/**
 * Repository processor interface.
 */
interface RepositoryInterface {
    public function getNextPid(string $nameSpace): string;
    public function constructObject(string $pid, bool $create_uuid = FALSE): object;
    public function ingestObject(object $fedoraObj): object;
    public function getObject(string $pid): object;
}

/**
 * Repository processor service interface.
 */
interface RepositoryServiceInterface {
    public function repository_service_getNextPid(string $nameSpace): string;
    public function repository_service_constructObject(string $pid, bool $create_uuid = FALSE): object;
    public function repository_service_ingestObject(object $fedoraObj): object;
    public function repository_service_getObject(string $pid): object;
}

/**
 * A RepositoryServiceInterface Adapter to directly access Tuque library functions.
 * 
 * @codeCoverageIgnore
 */
class FedoraRepositoryServiceAdapter implements RepositoryServiceInterface {
    /**
     * Class constructor.
     * 
     * @param string $tuqueLibraryLocation The location of the Tuque library.
     * @param string $url The repository url.
     * @param string $userName The repository user name.
     * @param string $userPassword The repository user password.
     * 
     * @throws RepositoryProcessorServiceException if a connection to the Fedora repository can't be made.
     */
    public function __construct(string $tuqueLibraryLocation, string $url, string $userName, string $userPassword) {

        // Check that the Tuque library exists.
        if ( (empty($tuqueLibraryLocation) === true) || (is_dir($tuqueLibraryLocation) === false) ) {
            $errorMessage = "Can't locate the Tuque library: Please check that the [packages]->tuque setting is valid.";
            throw new RepositoryProcessorServiceException($errorMessage);
        }

        // Check that we have valid settings.
        if ( (empty($url) === true) || (empty($userName) === true) || (empty($userPassword) === true) ) {
            $errorMessage = "Can't connect to Fedora instance: One or more of the [fedora] settings aren't set or are invalid.";
            throw new RepositoryProcessorServiceException($errorMessage);
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
         * See: https://github.com/Islandora/tuque/blob/7.x-1.7/RepositoryConnection.php#L49-L61
         * See: https://github.com/Islandora/tuque/blob/7.x-1.7/FedoraApi.php#L42-L56
         * See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L158-L162
         * 
         * INFO: Tuque library exceptions defined here:
         *       https://github.com/Islandora/tuque/blob/7.x-1.7/RepositoryException.php
         * 
         * INFO: Instantiating RepositoryConnection() throws a RepositoryException exception on error.
         */
        try {
            $this->connection = new \RepositoryConnection($url, $userName, $userPassword);
            // Instantiate a Tuque library FedoraApi class object.
            $this->api = new \FedoraApi($this->connection);

            // Instantiate a Tuque library FedoraRepository class object.
            $this->repository = new \FedoraRepository($this->api, new \simpleCache());

            $this->api_m = $this->repository->api->m;
        } catch (RepositoryException | Exception $e) {
            $errorMessage = "Can't connect to Fedora instance: " . $e->getMessage();
            throw new RepositoryProcessorServiceException($errorMessage);
        }
    }

    /**
     * Fetches the next PID from the repository.
     * 
     * @param string $nameSpace The namespace prefix.
     * 
     * @return string A PID string.
     */
    public function repository_service_getNextPid(string $nameSpace): string {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/FedoraApi.php#L949-L960
        $numberOfPIDsToRequest = 1;
        $ret = $this->api_m->getNextPid($nameSpace, $numberOfPIDsToRequest);

        return $ret;
    }

    /**
     * Constructs a NewFedoraObject object with a given PID.
     * A NewFedoraObject object is a placeholder until it is fully ingested, 
     * and then a FedoraRecord object is created.
     * 
     * @param string $pid A PID string to initialize a repository object.
     * @param bool $create_uuid Indicates if the objects ID should contain a UUID.
     * 
     * @return object A NewFedoraObject object.
     */
    public function repository_service_constructObject(string $pid, bool $create_uuid = FALSE): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L37 (AbstractRepository class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L174-L186 (FedoraRepositoryWrapper class)
        $ret = $this->repository->constructObject($pid, $create_uuid);

        return $ret;
    }

    /**
     * Ingests a NewFedoraObject object. 
     * This creates a FedoraObject from the passed NewFedoraObject object.
     * 
     * @param object $fedoraObj A NewFedoraObject object.
     * 
     * @return object A FedoraObject object. 
     */
    public function repository_service_ingestObject(object $fedoraObj): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L50 (AbstractRepository class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L282-L302 (FedoraRepositoryWrapper class)
        $ret = $this->repository->ingestObject($fedoraObj);

        return $ret;
    }

    /**
     * Retrieves an existing FedoraRecord object using a PID string.
     * 
     * @param string $pid A PID string to lookup.
     * 
     * @return object A FedoraRecord object.
     * 
     * @throws RepositoryProcessorServiceException if an existing FedoraRecord object can't be found by $pid.
     */
    public function repository_service_getObject(string $pid): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L61 (AbstractRepository class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L309-L323 (FedoraRepositoryWrapper class)
        // INFO: getObject() throws a RepositoryException exception on error.
        try {
            $ret = $this->repository->getObject($pid);
        } catch (RepositoryException | Exception $e) {
            $errorMessage = "Couldn't get a FedoraRecord object with this PID: {$pid}. " . $e->getMessage();
            throw new RepositoryProcessorServiceException($errorMessage);
        }

        return $ret;
    }
}

/**
 * Manages a connection to a Fedora repository.
 */
class FedoraRepositoryWrapper implements RepositoryInterface {

    /**
     * Class constructor.
     * 
     * @param object $service The RepositoryService object.
     */
    public function __construct(object $service) {
        $this->service = $service;
    }

    /**
     * Fetches the next PID from the repository.
     * 
     * @param string $nameSpace The namespace prefix.
     * 
     * @return string A PID string.
     */
    public function getNextPid(string $nameSpace): string {
        $result = $this->service->repository_service_getNextPid($nameSpace);

        return $result;
    }

    /**
     * Constructs a NewFedoraObject object with a given PID.
     * A NewFedoraObject object is a placeholder until it is fully ingested, 
     * and then a FedoraRecord object is created.
     * 
     * @param string $pid A PID string to initialize a repository object.
     * @param bool $create_uuid Indicates if the objects ID should contain a UUID.
     * 
     * @return object A NewFedoraObject object.
     */
    public function constructObject(string $pid, bool $create_uuid = FALSE): object {
        $result = $this->service->repository_service_constructObject($pid, $create_uuid);

        return $result;
    }

    /**
     * Retrieves an existing FedoraRecord object using a PID string.
     * 
     * @param string $pid A PID string to lookup.
     * 
     * @return object A FedoraRecord object.
     * 
     * @throws RepositoryProcessorException if an existing FedoraRecord object can't be found by $pid.
     */
    public function getObject(string $pid): object {
        try {
            $result = $this->service->repository_service_getObject($pid);
        } catch(RepositoryProcessorServiceException $e) {
            throw new RepositoryProcessorException($e->getMessage());
        }

        return $result;
    }

    /**
     * Ingest a NewFedoraObject object. 
     * This creates a FedoraObject from the passed NewFedoraObject object.
     * 
     * @param object $fedoraObj A NewFedoraObject object.
     * 
     * @return object A FedoraObject object. 
     */
    public function ingestObject(object $fedoraObj): object {
        $result = $this->service->repository_service_ingestObject($fedoraObj);

        return $result;
    }
}

?>