<?php declare(strict_types=1);
namespace Processproquest\Repository;

class RepositoryRecordProcessorException extends \Exception {};
class RepositoryRecordProcessorServiceException extends \Exception {};

/**
 * Repository record processor interface.
 */
interface RepositoryRecordProcessorInterface {
    public function constructDatastream(string $id, string $control_group = "M"): object;
    public function ingestDatastream(object $dataStream): mixed;
    public function getDatastream(string $datastreamID): object|bool;
}

/**
 * Repository record processor service interface.
 */
interface RepositoryRecordProcessorServiceInterface {
    public function repository_service_constructDatastream(string $id, string $control_group = "M"): object;
    public function repository_service_ingestDatastream(object $dataStream): mixed;
    public function repository_service_getDatastream(string $datastreamID): object|bool;
}

/**
 * A RepositoryRecordProcessorServiceInterface Adapter to directly access Tuque library functions.
 * 
 * @codeCoverageIgnore
 */
class FedoraRepositoryRecordProcessorServiceAdapter implements RepositoryRecordProcessorServiceInterface {

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
     * @throws RepositoryServiceException if a connection to the Fedora repository can't be made.
     */
    public function __construct(string $tuqueLibraryLocation, string $url, string $userName, string $userPassword) {
        // Check that the Tuque library exists.
        if ( (empty($tuqueLibraryLocation) === true) || (is_dir($tuqueLibraryLocation) === false) ) {
            $errorMessage = "Can't locate the Tuque library: Please check that the [packages]->tuque setting is valid.";
            throw new RepositoryServiceException($errorMessage);
        }

        // Check that we have valid settings.
        if ( (empty($url) === true) || (empty($userName) === true) || (empty($userPassword) === true) ) {
            $errorMessage = "Can't connect to Fedora instance: One or more of the [fedora] settings aren't set or are invalid.";
            throw new RepositoryServiceException($errorMessage);
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
            throw new RepositoryServiceException($errorMessage);
        }
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

    /**
     * Adds a datastream to a AbstractFedoraObject (FedoraRecord|NewFedoraRecord) object.
     * The return type depends on the calling AbstractFedoraObject object type.
     * 
     * @param object $dataStream The AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) to ingest
     * 
     * @return mixed Return bool or AbstractFedoraDatastream (NewFedoraDatastream|FedoraDatastream) object.
     * 
     * @throws RepositoryServiceException if the datastream already exists.
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
            throw new RepositoryServiceException($errorMessage);
        }
        
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
    public function repository_service_getDatastream(string $datastreamID): object|bool {
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L108 (AbstractObject|AbstractFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L590-L597 (NewFedoraObject class)
        // See: https://github.com/Islandora/tuque/blob/7.x-1.7/Object.php#L735-L743 (FedoraObject class)
        $result = $this->repository->getDatastream($datastreamID);

        return $result;
    }
}

/**
 * Manages a connection to a Fedora repository.
 */
class FedoraRepositoryRecordProcessor implements RepositoryRecordProcessorInterface {

    /**
     * Class constructor.
     * 
     * @param object $service The RepositoryService object.
     */
    public function __construct(object $service) {
        $this->service = $service;
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
     * @throws RepositoryWrapperException if the datastream already exists.
     */
    public function ingestDatastream(object $dataStream): mixed {
        try {
            $result = $this->service->repository_service_ingestDatastream($dataStream);
        } catch(RepositoryServiceException $e) {
            throw new RepositoryWrapperException($e->getMessage());
        }

        return $result;
    }
}

?>