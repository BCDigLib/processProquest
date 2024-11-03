<?php declare(strict_types=1);
namespace Processproquest\Repository;

class PPRepositoryException extends \Exception {};
class PPRepositoryServiceException extends \Exception {};

/**
 * Repository interface.
 */
interface RepositoryInterface {
    public function getNextPid(string $nameSpace): string;
    public function constructObject(string $pid): object;
    public function getObject(string $pid): object;
    public function ingestObject(object $fedoraObj): object;
    public function getDatastream(string $pid): object|bool;
    public function ingestDatastream(object $dataStream): bool;
    public function constructDatastream(string $id, string $control_group = "M"): object;
}

/**
 * Repository service interface.
 */
interface RepositoryServiceInterface {
    public function repository_service_getNextPid(string $nameSpace): string;
    public function repository_service_constructObject(string $pid): object;
    public function repository_service_getObject(string $pid): object;
    public function repository_service_ingestObject(object $fedoraObj): object;
    public function repository_service_getDatastream(string $pid): object|bool;
    public function repository_service_ingestDatastream(object $dataStream): bool;
    public function repository_service_constructDatastream(string $id, string $control_group = "M"): object;
}

/**
 * A RepositoryService Adapter to connect to Fedora functions.
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
     * @throws PPRepositoryServiceException if a connection to the Fedora repository can't be made.
     */
    public function __construct(string $tuqueLibraryLocation, string $url, string $userName, string $userPassword) {
        // Check that the Tuque library exists.
        if ( (empty($tuqueLibraryLocation) === true) || (is_dir($tuqueLibraryLocation) === false) ) {
            $errorMessage = "Can't locate the Tuque library: Please check that the [packages]->tuque setting is valid.";
            throw new PPRepositoryServiceException($errorMessage);
        }

        // Check that we have valid settings.
        if ( (empty($url) === true) || (empty($userName) === true) || (empty($userPassword) === true) ) {
            $errorMessage = "Can't connect to Fedora instance: One or more of the [fedora] settings aren't set or are invalid.";
            throw new PPRepositoryServiceException($errorMessage);
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
        } catch (RepositoryException | Exception $e) {
            $errorMessage = "Can't connect to Fedora instance: " . $e->getMessage();
            throw new PPRepositoryServiceException($errorMessage);
        }
    }

    /**
     * Fetch the next PID from the repository.
     * 
     * @param string $nameSpace The namespace prefix.
     * 
     * @return string A PID string.
     */
    public function repository_service_getNextPid(string $nameSpace): string {
        // See: https://github.com/Islandora/tuque/blob/1.x/FedoraApi.php#L952-L963
        $numberOfPIDsToRequest = 1;
        $ret = $this->api_m->getNextPid($nameSpace, $numberOfPIDsToRequest);

        return $ret;
    }

    /**
     * Construct a repository object with a given PID.
     * 
     * @param string $pid A PID string to initialize a repository object.
     * 
     * @return object A repository object.
     */
    public function repository_service_constructObject(string $pid): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L174-L186
        $ret = $this->repository->constructObject($pid);

        return $ret;
    }

    /**
     * Retrieve an object using a PID string.
     * 
     * @param string $pid A PID string to lookup.
     * 
     * @return object A repository object.
     * 
     * @throws PPRepositoryServiceException if a repository record can't be found by $pid.
     */
    public function repository_service_getObject(string $pid): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L309-L323
        // INFO: getObject() throws a RepositoryException exception on error.
        try {
            $ret = $this->repository->getObject($pid);
        } catch (RepositoryException | Exception $e) {
            $errorMessage = "Couldn't get an object with this pid: {$pid}. " . $e->getMessage();
            throw new PPRepositoryServiceException($errorMessage);
        }

        return $ret;
    }

    /**
     * Ingest a repository object.
     * 
     * @param object $fedoraObj A fully formed Fedora DAM object.
     * 
     * @return object The ingested object. 
     */
    public function repository_service_ingestObject(object $fedoraObj): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L282-L302
        $ret = $this->repository->ingestObject($fedoraObj);

        return $ret;
    }

    /**
     * Get a datastream.
     * 
     * @param string $pid The id of the datastream to retrieve.
     * 
     * @return object|bool Returns FALSE if the datastream could not be found. Otherwise it return
     *                     an instantiated Datastream object. (FedoraDatastream)
     */
    public function repository_service_getDatastream(string $pid): object|bool {
        // See: https://github.com/Islandora/tuque/blob/1.x/Object.php#L593-L600
        $result = $this->repository->getDatastream($pid);

        return $result;
    }

    /**
     * Ingest a datastream.
     * 
     * @param object $dataStream The datastream to ingest
     * 
     * @return bool Return true on success.
     * 
     * @throws PPRepositoryServiceException if the datastream already exists.
     */
    public function repository_service_ingestDatastream(object $dataStream): bool {
        // See: https://github.com/Islandora/tuque/blob/1.x/Object.php#L561-L575
        // INFO: ingestDatastream() throws a DatastreamExistsException exception on error.
        try {
            $result = $this->repository->ingestDatastream();
        } catch (DatastreamExistsException | Exception $e) {
            $errorMessage = "Couldn't get an object with this pid: {$pid}. " . $e->getMessage();
            throw new PPRepositoryServiceException($errorMessage);
        }
        
        return $result;
    }

    /**
     * Construct a datastream.
     * 
     * @param string $id The identifier of the new datastream.
     * @param string $control_group The control group the new datastream will be created in.
     * 
     * @return object an instantiated Datastream object.
     */
    public function repository_service_constructDatastream(string $id, string $control_group = "M"): object {
        // See: https://github.com/Islandora/tuque/blob/1.x/Object.php#L448-L450
        $result = $this->repository->constructDatastream($id, $control_group);

        return $result;
    }
}

/**
 * Manages a connection to a Fedora repository.
 */
class FedoraRepository implements RepositoryInterface {

    /**
     * Class constructor.
     * 
     * @param object $service The RepositoryService object.
     */
    public function __construct(object $service) {
        $this->service = $service;
    }

    /**
     * Fetch the next PID from the repository.
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
     * Construct a repository object with a given PID.
     * 
     * @param string $pid A PID string to initialize a repository object.
     * 
     * @return object A repository object.
     */
    public function constructObject(string $pid): object {
        $result = $this->service->repository_service_constructObject($pid);

        return $result;
    }

    /**
     * Retrieve an object using a PID string.
     * 
     * @param string $pid A PID string to lookup.
     * 
     * @return object A repository object.
     * 
     * @throws PPRepositoryException if a repository record can't be found by $pid.
     */
    public function getObject(string $pid): object {
        try {
            $result = $this->service->repository_service_getObject($pid);
        } catch(PPRepositoryServiceException $e) {
            throw new PPRepositoryException($e->getMessage());
        }

        return $result;
    }

    /**
     * Ingest a repository object.
     * 
     * @param object $fedoraObj A fully formed Fedora DAM object.
     * 
     * @return object The ingested object. 
     */
    public function ingestObject(object $fedoraObj): object {
        $result = $this->service->repository_service_ingestObject($fedoraObj);

        return $result;
    }

    /**
     * Get a datastream.
     * 
     * @param string $pid The id of the datastream to retrieve.
     * 
     * @return object|bool Returns FALSE if the datastream could not be found. Otherwise it return
     *                     an instantiated Datastream object.
     */
    public function getDatastream(string $pid): object|bool {
        $result = $this->service->repository_service_getDatastream($pid);

        return $result;
    }

    /**
     * Ingest a datastream.
     * 
     * @param object $dataStream The datastream to ingest
     * 
     * @return bool Return true on success.
     * 
     * @throws PPRepositoryException if the datastream already exists.
     */
    public function ingestDatastream(object $dataStream): bool {
        try {
            $result = $this->service->repository_service_ingestDatastream($dataStream);
        } catch(PPRepositoryServiceException $e) {
            throw new PPRepositoryException($e->getMessage());
        }

        return $result;
    }

    /**
     * Construct a datastream.
     * 
     * @param string $id The identifier of the new datastream.
     * @param string $control_group The control group the new datastream will be created in.
     * 
     * @return object an instantiated Datastream object.
     */
    public function constructDatastream(string $id, string $control_group = "M"): object {
        $result = $this->service->repository_service_constructDatastream($id, $control_group);

        return $result;
    }
}

?>
