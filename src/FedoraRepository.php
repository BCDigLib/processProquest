<?php declare(strict_types=1);
namespace Processproquest\Repository;

class PPRepositoryException extends \Exception {};
class PPRepositoryServiceException extends \Exception {};

/**
 * Repository interface.
 */
interface RepositoryInterface {
    public function getNextPid(string $nameSpace): string;
    public function constructObject(string $pid, bool $create_uuid = FALSE): object;
    public function getObject(string $pid): object;
    public function ingestObject(object $fedoraObj): object;
    public function getDatastream(string $datastreamID): object|bool;
    public function ingestDatastream(object $dataStream): mixed;
    public function constructDatastream(string $id, string $control_group = "M"): object;
}

/**
 * Repository service interface.
 */
interface RepositoryServiceInterface {
    public function repository_service_getNextPid(string $nameSpace): string;
    public function repository_service_constructObject(string $pid, bool $create_uuid = FALSE): object;
    public function repository_service_getObject(string $pid): object;
    public function repository_service_ingestObject(object $fedoraObj): object;
    public function repository_service_getDatastream(string $datastreamID): object|bool;
    public function repository_service_ingestDatastream(object $dataStream): mixed;
    public function repository_service_constructDatastream(string $id, string $control_group = "M"): object;
}

/**
 * A RepositoryService Adapter to connect to Fedora functions.
 * 
 * @codeCoverageIgnore
 */
class FedoraRepositoryServiceAdapter implements RepositoryServiceInterface {

    // Object.php
    // abstract class AbstractFedoraObject extends AbstractObject
    // class NewFedoraObject extends AbstractFedoraObject
    // class FedoraObject extends AbstractFedoraObject
    
    // Datastream.php
    // abstract class AbstractFedoraDatastream extends AbstractDatastream
    // class NewFedoraDatastream extends AbstractFedoraDatastream
    // abstract class AbstractExistingFedoraDatastream extends AbstractFedoraDatastream
    // class FedoraDatastream extends AbstractExistingFedoraDatastream

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
            $this->api = new \FedoraApi($this->connection);
            $this->repository = new \FedoraRepository($this->api, new \simpleCache());
            $this->api_m = $this->repository->api->m;
        } catch (RepositoryException | Exception $e) {
            $errorMessage = "Can't connect to Fedora instance: " . $e->getMessage();
            throw new PPRepositoryServiceException($errorMessage);
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
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L174-L186 (FedoraRepository class)
        $ret = $this->repository->constructObject($pid, $create_uuid);

        return $ret;
    }

    /**
     * Retrieves an existing FedoraRecord object using a PID string.
     * 
     * @param string $pid A PID string to lookup.
     * 
     * @return object A FedoraRecord object.
     * 
     * @throws PPRepositoryServiceException if an existing FedoraRecord object can't be found by $pid.
     */
    public function repository_service_getObject(string $pid): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L61 (AbstractRepository class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L309-L323 (FedoraRepository class)
        // INFO: getObject() throws a RepositoryException exception on error.
        try {
            $ret = $this->repository->getObject($pid);
        } catch (RepositoryException | Exception $e) {
            $errorMessage = "Couldn't get a FedoraRecord object with this PID: {$pid}. " . $e->getMessage();
            throw new PPRepositoryServiceException($errorMessage);
        }

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
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Repository.php#L282-L302 (FedoraRepository class)
        $ret = $this->repository->ingestObject($fedoraObj);

        return $ret;
    }

    /**
     * Gets an AbstractFedoraObject (FedoraRecord|NewFedoraRecord) object's datastream by ID.
     * The AbstractFedoraDatastream type returns depends on the calling AbstractFedoraObject object type.
     * 
     * @param string $datastreamID The ID of the datastream to retrieve.
     * 
     * @return object|bool Returns FALSE if the datastream could not be found. Otherwise it returns
     *                     an instantiated AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     */
    public function repository_service_getDatastream(string $datastreamID): object|bool {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L108 (AbstractObject|AbstractFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L590-L597 (NewFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L735-L743 (FedoraObject class)
        $result = $this->repository->getDatastream($datastreamID);

        return $result;
    }

    /**
     * Adds a datastream to a AbstractFedoraObject (FedoraRecord|NewFedoraRecord) object.
     * The return type depends on the calling AbstractFedoraObject object type.
     * 
     * @param object $dataStream The AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) to ingest
     * 
     * @return mixed Return bool or AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     * 
     * @throws PPRepositoryServiceException if the datastream already exists.
     */
    public function repository_service_ingestDatastream(object $dataStream): mixed {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L139 (AbstractObject|AbstractFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L558-L572 (NewFedoraObject class)
        //      returns true on success; false on error
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L835-L870 (FedoraObject class)
        //      returns FedoraStream object on success; false on error
        // INFO: ingestDatastream() throws a DatastreamExistsException exception on error.
        try {
            $result = $this->repository->ingestDatastream();
        } catch (DatastreamExistsException | Exception $e) {
            $errorMessage = "Couldn't get a NewFedoraDatastream or FedoraObject datastream object with this PID: {$pid}. " . $e->getMessage();
            throw new PPRepositoryServiceException($errorMessage);
        }
        
        return $result;
    }

    /**
     * Constructs a AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     * This object is not ingested until ingestDatastream() is called.
     * The AbstractFedoraDatastream type returns depends on the calling AbstractFedoraObject object type.
     * 
     * @param string $id The identifier of the new datastream.
     * @param string $control_group The control group the new datastream will be created in.
     * 
     * @return object an instantiated AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     */
    public function repository_service_constructDatastream(string $id, string $control_group = "M"): object {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L134 (AbstractObject|AbstractFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L448-L450 (NewFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L828-L830 (FedoraObject class)
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
     * @throws PPRepositoryException if an existing FedoraRecord object can't be found by $pid.
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

    /**
     * Gets an AbstractFedoraObject (FedoraRecord|NewFedoraRecord) object's datastream by ID.
     * The AbstractFedoraDatastream type returns depends on the calling AbstractFedoraObject object type.
     * 
     * @param string $datastreamID The ID of the datastream to retrieve.
     * 
     * @return object|bool Returns FALSE if the datastream could not be found. Otherwise it returns
     *                     an instantiated AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     */
    public function getDatastream(string $datastreamID): object|bool {
        $result = $this->service->repository_service_getDatastream($datastreamID);

        return $result;
    }

    /**
     * Adds a datastream to a AbstractFedoraObject (FedoraRecord|NewFedoraRecord) object.
     * The return type depends on the calling AbstractFedoraObject object type.
     * 
     * @param object $dataStream The AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) to ingest
     * 
     * @return mixed Return bool or AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     * 
     * @throws PPRepositoryException if the datastream already exists.
     */
    public function ingestDatastream(object $dataStream): mixed {
        try {
            $result = $this->service->repository_service_ingestDatastream($dataStream);
        } catch(PPRepositoryServiceException $e) {
            throw new PPRepositoryException($e->getMessage());
        }

        return $result;
    }

    /**
     * Constructs a AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     * This object is not ingested until ingestDatastream() is called.
     * The AbstractFedoraDatastream type returns depends on the calling AbstractFedoraObject object type.
     * 
     * @param string $id The identifier of the new datastream.
     * @param string $control_group The control group the new datastream will be created in.
     * 
     * @return object an instantiated AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     */
    public function constructDatastream(string $id, string $control_group = "M"): object {
        $result = $this->service->repository_service_constructDatastream($id, $control_group);

        return $result;
    }
}

?>