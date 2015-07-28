<?php
namespace Phalcon\ApiGenerator\Api\Controllers;

use Phalcon\ApiGenerator\Api\Models\ControllerModelInterface as ModelInterface;
use Phalcon\ApiGenerator\Api\Services\ControllerHelper\Index;
use Phalcon\ApiGenerator\Api\Services\ControllerHelper\Params;
use Phalcon\ApiGenerator\Api\Services\ControllerHelper\Save;
use Phalcon\ApiGenerator\Api\Services\ControllerHelper\Delete;
use Phalcon\ApiGenerator\Api\Services\ControllerHelper\Show;
use Phalcon\ApiGenerator\Api\Services\Acl\AclException;
use Phalcon\ApiGenerator\Api\Services\ControllerHelper\ShowCriteria;
use Phalcon\Http\Request\Exception;
use Phalcon\Mvc\Controller;
use Phalcon\DI;
use Phalcon\Mvc\Model\Resultset\Simple as ResultSet;
use Phalcon\ApiGenerator\Api\Services\Behavior\ValidationException;
use Phalcon\ApiGenerator\Api\Services\Output\OutputInterface;
use Phalcon\ApiGenerator\Api\Services\Acl\AclInterface;

/**
 * Class ControllerBase
 */
abstract class Base extends Controller {
	/** @var  \stdClass */
	private $outputObject;
	/** @var Exception[]  */
	private $errors = array();
	/** @var int Server Status Code */
	private $statusCode;

	const STATUS_CODE_BAD_REQUEST = 400;
	const STATUS_CODE_NOT_FOUND = 404;

	/**
	 * Initializes
	 * @return void
	 */
	public function beforeExecuteRoute() {
		$this->validateServicesDefined();
		$this->getDi()->set('Request', $this->request);
		$this->setStatusCode(200);
		$this->setOutputObject(new \stdClass());
		$this->response->setHeader('Access-Control-Allow-Origin', '*');
		try {
			$this->validateLogin();
		} catch(Exception $e) {
			$this->handleError($e);
			$this->afterExecuteRoute();
			exit;
		}
	}

	/**
	 * Validates to make sure all required services are defined
	 * @return void
	 * @throws \Exception
	 */
	private function validateServicesDefined() {
		if(!$this->getDi()->has('Acl')) {
			throw new \Exception('Service Acl must be defined with a type of: '.AclInterface::class);
		}
		if(!$this->getDi()->has('Output')) {
			throw new \Exception('Service Output must be defined with a type of: '.OutputInterface::class);
		}
	}


	/**
	 * This is used to give an error if you are accessing an action that you are not allowed to, such as if a controller is not creatable or deletable
	 *
	 * @param string $errorMessage
	 *
	 * @return void
	 */
	protected function throwUnAccessibleAction($errorMessage) {
		$this->setErrors(
			[
				new Exception($errorMessage, 405)
			]
		);
	}

	/**
	 * When called, this should return a new entity
	 * @return ModelInterface
	 */
	abstract public function generateEntity();

	/**
	 * Sets HTTP Status Code
	 * @return int
	 */
	public function getStatusCode() {
		return $this->statusCode;
	}

	/**
	 * Returns HTTP Status Code
	 * @param int $statusCode
	 */
	public function setStatusCode($statusCode) {
		$this->statusCode = $statusCode;
	}

	/**
	 * Adds an error
	 *
	 * @param Exception $e
	 *
	 * @return void
	 */
	protected function addError(Exception $e) {
		$errors = $this->getErrors();
		$errors[] = $e;
		$this->setErrors($errors);
	}

	/**
	 * Getter
	 * @return \Phalcon\Http\Request\Exception[]
	 */
	protected function getErrors() {
		return $this->errors;
	}

	/**
	 * Setter
	 * @param \Phalcon\Http\Request\Exception[] $errors
	 */
	protected function setErrors(array $errors) {
		$this->errors = $errors;
	}

	/**
	 * Getter
	 * @return \stdClass
	 */
	protected function getOutputObject() {
		return $this->outputObject;
	}

	/**
	 * Setter
	 * @param \stdClass $outputObject
	 */
	protected function setOutputObject(\stdClass $outputObject) {
		$this->outputObject = $outputObject;
	}

	/**
	 * Gets a list of parameters that are always allowed in the query, aka any tokens or authentication information
	 * @return string[]
	 */
	abstract protected function getQueryWhiteList();

	/**
	 * This provides a list of the entities
	 * @return void
	 */
	protected function indexAction() {
		try {
			$entity = $this->generateEntity();

			$query = new Index($this->request, $entity, $this->getQueryWhiteList());
			$this->response->setHeader('link', $query->generateLinks());
			/** @var ResultSet $entities */
			$entities = $query->getResultSet();

			$objects = array();
			while($entities->valid()) {
				/** @var ModelInterface $entity */
				$entity = $entities->current();
				$objects[] = $this->generateEntityAction($entity);
				$entities->next();
			}
			$outputObject = $this->getOutputObject();
			$blankEntity = $this->generateEntity();
			$outputObject->{$blankEntity->getEntityName().'s'} = $objects;
			$this->setOutputObject($outputObject);
		} catch(Exception $e) {
			$this->handleError($e);
		} catch(ValidationException $e) {
			$this->handleValidationError($e);
		} catch(AclException $e) {
			$this->handleAclError($e);
		}
	}

	/**
	 * Needed for CORs
	 * @return void
	 */
	public function optionsAction() {
		$this->response->setHeader('Access-Control-Allow-Methods', 'POST, GET, PUT, DELETE, OPTIONS');
		$this->response->setHeader('Access-Control-Allow-Headers', 'X-Requested-With, X-HTTP-Method-Override, Content-Type, Accept');
		$this->setStatusCode(200);
	}

	/**
	 * This is how you create a new entity, returns showAction on the specified entity.
	 * This would be called using the POST method, with the url: v{versionNumber}/{Entities}
	 * This returns the showAction
	 * @return void
	 */
	public function createAction() {
		try {
			$this->setStatusCode(201);
			$entity = $this->createActionInternal();
			$this->showActionInternal($entity);
		} catch(Exception $e) {
			$this->handleError($e);
		} catch(ValidationException $e) {
			$this->handleValidationError($e);
		} catch(AclException $e) {
			$this->handleAclError($e);
		}
	}

	/**
	 * Provides the actual creating of a new entity.
	 * @return ModelInterface
	 */
	protected function createActionInternal() {
		$entity = $this->generateEntity();
		$this->saveEntity($entity, true);
		// since our entity can be manipulated after saving, we need to find it again, just in case.
		return $entity->findFirst($entity->getId());
	}

	/**
	 * Looks up an individual entity
	 * This would be called using the GET method, with the url: v{versionNumber}/{Entities}/{entityId}
	 *
	 * @return void
	 */
	public function showAction() {
		try {
			if(sizeOf($this->dispatcher->getParams())==0) {
				throw new Exception('Invalid Entity Id Passed In', 400);
			}
			$entity = $this->validateEntityId($this->dispatcher->getParam(0));
			$this->showActionInternal($entity);
		} catch(Exception $e) {
			$this->handleError($e);
		} catch(ValidationException $e) {
			$this->handleValidationError($e);
		} catch(AclException $e) {
			$this->handleAclError($e);
		}
	}

	/**
	 * Looks up an individual entity
	 *
	 * @param ModelInterface $entity
	 *
	 * @return void
	 */
	protected function showActionInternal(ModelInterface $entity) {
		$outputObject = $this->getOutputObject();
		$outputObject->{$entity->getEntityName()} = $this->generateEntityAction($entity);
		$this->setOutputObject($outputObject);
	}

	/**
	 * Generates the output of an entity
	 *
	 * @param ModelInterface $entity
	 *
	 * @return \stdClass
	 */
	private function generateEntityAction(ModelInterface $entity) {
		$show = new Show($this->request, $entity);
		$showCriteria = new ShowCriteria($this->request);
		return $show->generate($showCriteria->getField());
	}


	/**
	 * Updates an individual entity
	 * This would be called using the PUT method, with the url: v{versionNumber}/{Entities}/{entityId}
	 * This returns the showAction
	 *
	 * @return void
	 */
	public function updateAction() {
		try {
			if(sizeOf($this->dispatcher->getParams())==0) {
				throw new Exception('Invalid Entity Id Passed In', 400);
			}
			$entity = $this->validateEntityId($this->dispatcher->getParam(0));
			$isChanged = $this->updateActionInternal($entity);
			if($isChanged) {
				$this->showActionInternal($entity->findFirst($entity->getId()));
			}
		} catch(Exception $e) {
			$this->handleError($e);
		} catch(ValidationException $e) {
			$this->handleValidationError($e);
		} catch(AclException $e) {
			$this->handleAclError($e);
		}
	}

	/**
	 * Finds the post parameters
	 * @param ModelInterface $entity
	 * @return Params
	 */
	protected function findPostParams(ModelInterface $entity) {
		//Entity is passed in so children can access it
		return new Params($this->request);
	}

	/**
	 * Saves an entity (either creating or updating)
	 *
	 * @param ModelInterface $entity
	 * @param bool           $isCreating
	 *
	 * @return bool if anything was changed
	 */
	private function saveEntity(ModelInterface $entity, $isCreating) {
		$save = new Save($this->request, $entity, $isCreating);
		return $save->process($this->findPostParams($entity));
	}

	/**
	 * Updates an entity
	 *
	 * @param ModelInterface $entity
	 *
	 * @return bool
	 */
	protected function updateActionInternal(ModelInterface $entity) {
		$isChanged = $this->saveEntity($entity, false);
		if(!$isChanged) {
			$this->setStatusCode(304); //Nothing is changed
		}
		return $isChanged;
	}

	/**
	 * Deletes an individual entity
	 *
	 * @return void
	 */
	public function deleteAction() {
		try {
			if(sizeOf($this->dispatcher->getParams())==0) {
				throw new Exception('Invalid Entity Id Passed In', 400);
			}
			$entity = $this->validateEntityId($this->dispatcher->getParam(0));
			$this->deleteActionInternal($entity);
		} catch(Exception $e) {
			$this->handleError($e);
		} catch(ValidationException $e) {
			$this->handleValidationError($e);
		} catch(AclException $e) {
			$this->handleAclError($e);
		}
	}

	/**
	 * Gets the Access Control Layer
	 * @return AclInterface
	 * @throws \Exception
	 */
	public function getAcl() {
		$returnVar = $this->getDi()->get('Acl');
		if(!($returnVar instanceof AclInterface)) {
			throw new \Exception('The Acl must implement: '.AclInterface::class);
		}
		return $returnVar;
	}

	/**
	 * Default delete action for our entities.
	 *
	 * @param ModelInterface $entity
	 *
	 * @return void
	 */
	protected function deleteActionInternal(ModelInterface $entity) {
		$this->setStatusCode(204);
		$delete = new Delete($entity);
		$delete->process($this->getAcl());
	}

	/**
	 * This validates an entity id, and looks up the entity associated with it
	 *
	 * @param int $entityId
	 *
	 * @return ModelInterface
	 * @throws Exception
	 */
	private function validateEntityId($entityId) {
		if(!is_numeric($entityId)) {
			throw new Exception('Invalid Entity Id: Must be numeric', 400);
		}
		return $this->lookUpEntity($entityId);
	}

	/**
	 * This looks up an entity based off of the entity id
	 *
	 * @param int $entityId
	 *
	 * @throws Exception - If unable to find the entity, return a 404 to the user.
	 *
	 * @return ModelInterface
	 */
	protected function lookUpEntity($entityId) {
		$entity = $this->generateEntity();
		$entityInstance = $entity->findFirst($entityId);
		if($entityInstance===false) {
			throw new Exception("Invalid Entity Id: Entity not found.", 404);
		}
		return $entityInstance;
	}

	/**
	 * Executed after it is routed
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	public function afterExecuteRoute() {
		/** @var \Phalcon\ApiGenerator\Api\Services\Output\OutputInterface $output */
		$output = $this->getDI()->get('Output');
		if(!($output instanceof OutputInterface)) {
			throw new \Exception('The Output must implement: '.OutputInterface::class);
		}
		$object = $this->getOutputObject();
		$errors = $this->getErrors();
		$code   = $this->getStatusCode();

		if(!empty($errors)) {
			$outputErrors = array();
			foreach($errors as $error) {
				$outputErrors[] = $error->getMessage();
				if($error->getCode()!=0) {
					$code = $error->getCode();
				}
			}
			$object->errors = $outputErrors;
		}
		$object->statusCode = $code;
		$this->response->setStatusCode($code, 'Check Document Body For More Details');
		$output->output($object, $this->response);
	}

	/**
	 * Adds the error message
	 *
	 * @param Exception $e
	 *
	 * @return void
	 */
	protected function handleError(Exception $e) {
		$this->addError($e);
	}

	/**
	 * Handles exceptions from validations
	 *
	 * @param ValidationException $e
	 * @param int                 $errorCode
	 *
	 * @return void
	 */
	protected function handleValidationError(ValidationException $e, $errorCode=409) {
		$entity = $e->getEntity();
		foreach($entity->getMessages() as $message) {
			$this->addError(new Exception($message->getMessage(), $errorCode));
		}
	}

	/**
	 * Handles exceptions from acl
	 *
	 * @param AclException $e
	 *
	 * @return void
	 */
	protected function handleAclError(AclException $e) {
		$entity = $e->getEntity();
		foreach($entity->getMessages() as $message) {
			$this->addError(new Exception($message->getMessage(), 401));
		}
	}

	/**
	 * Validates that they have a valid login
	 * @return void
	 * @throws Exception
	 */
	abstract protected function validateLogin();

}