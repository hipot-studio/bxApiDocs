<?php

namespace Bitrix\Disk\Rest\Service;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\Error\IErrorable;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\File;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\AccessException;
use Bitrix\Main\Result;
use Bitrix\Rest\RestException;

abstract class Base implements IErrorable
{
	const ERROR_REQUIRED_PARAMETER = 'DISK_BASE_SERVICE_22001';

	/** @see \Bitrix\Disk\Internals\RightTable::validateAccessCode() */
	private const ACCESS_CODE_MAX_LENGTH = 50;

	/** @var ErrorCollection */
	protected $errorCollection;
	/** @var string */
	protected $methodName;
	/** @var array */
	protected $methodParams;
	/** @var array */
	protected $params;
	/** @var string */
	protected $start;
	/** @var \CRestServer */
	protected $restServer;
	/** @var int */
	protected $userId;

	/**
	 * Base constructor.
	 * @param   string      $methodName Method name which invokes REST.
	 * @param array         $params     Input params.
	 * @param        string $start      Start position for listing items.
	 * @param \CRestServer  $restServer
	 */
	public function __construct($methodName, array $params, $start, \CRestServer $restServer)
	{
		$this->methodName = $methodName;
		$this->params = $params;
		$this->start = $start;
		$this->restServer = $restServer;
		$this->errorCollection = new ErrorCollection;

		$this->init();
	}

	/**
	 * Initialize service.
	 */
	protected function init()
	{
		global $USER;
		$this->userId = $USER->getId();
	}

	protected function assertCanDownload(File $file): void
	{
		$securityContext = $file->getStorage()?->getCurrentUserSecurityContext();

		if (!$securityContext || !$file->canDownload($securityContext))
		{
			throw new AccessException;
		}
	}

	/**
	 * @return string the fully qualified name of this class.
	 */
	public static function className()
	{
		return get_called_class();
	}

	/**
	 * Executes method.
	 * @return mixed
	 * @throws RestException
	 */
	public function processMethodRequest()
	{
		try
		{
			$binder = new Engine\Binder($this, $this->methodName, array($this->params));

			return $binder->invoke();
		}
		catch (ArgumentException $e)
		{
			throw new RestException(
				"Invalid value of parameter { {$e->getParameter()} }.",
				RestException::ERROR_ARGUMENT
			);
		}

	}

	/**
	 * @param array $inputParams
	 * @param array $required
	 * @return bool
	 */
	protected function checkRequiredInputParams(array $inputParams, array $required)
	{
		foreach ($required as $item)
		{
			if(!isset($inputParams[$item]) || (!$inputParams[$item] && !(is_string($inputParams[$item]) && mb_strlen($inputParams[$item]))))
			{
				$this->errorCollection->add(array(new Error("Error: required parameter {$item}", self::ERROR_REQUIRED_PARAMETER)));
				return false;
			}
		}

		return true;
	}

	/**
	 * Validates rights of a REST request and returns them built of the checked values only.
	 * @param array $rights Rights to set on an object.
	 * @return array Rights ready for the rights manager.
	 * @throws RestException
	 */
	protected function prepareRights(array $rights): array
	{
		$result = $this->validateRights($rights);
		if (!$result->isSuccess())
		{
			throw new RestException($result->getErrorMessages()[0] ?? 'Invalid rights format');
		}

		return $result->getData();
	}

	/**
	 * Checks rights which came from a REST request before they reach the rights manager.
	 *
	 * The contract of a right is ACCESS_CODE, TASK_ID and an optional NEGATIVE. DOMAIN is generated
	 * by the module itself (@see \Bitrix\Disk\RightsManager::getSharingDomain()) and is dropped here.
	 *
	 * @param array $rights Rights to set on an object.
	 * @return Result Rights built of the checked values only, in the data of the result.
	 */
	protected function validateRights(array $rights): Result
	{
		$result = new Result();
		if (empty($rights))
		{
			return $result;
		}

		$preparedRights = [];
		$rightsManager = Driver::getInstance()->getRightsManager();
		foreach ($rights as $index => $right)
		{
			if (!is_array($right))
			{
				return $result->addError(new Error("Invalid format: Right {$index} should be array"));
			}

			if (!isset($right['ACCESS_CODE'], $right['TASK_ID']))
			{
				return $result->addError(
					new Error("Invalid format: Right {$index} should contain ACCESS_CODE and TASK_ID")
				);
			}

			if (!is_string($right['ACCESS_CODE']) || $right['ACCESS_CODE'] === '')
			{
				return $result->addError(
					new Error("Invalid format: Right {$index} should contain ACCESS_CODE as not empty string")
				);
			}

			// The rights are written by a raw insert batch, so the column size is checked here.
			if (mb_strlen($right['ACCESS_CODE']) > self::ACCESS_CODE_MAX_LENGTH)
			{
				return $result->addError(new Error(
					"Invalid format: Right {$index} should contain ACCESS_CODE"
					. ' not longer than ' . self::ACCESS_CODE_MAX_LENGTH . ' characters'
				));
			}

			// A task id reaches the rights manager as an array key, so anything but an integer breaks there.
			// A boolean is filtered out beforehand: filter_var() would turn true into 1.
			$taskId = is_bool($right['TASK_ID']) ? false : filter_var($right['TASK_ID'], FILTER_VALIDATE_INT);
			if ($taskId === false)
			{
				return $result->addError(
					new Error("Invalid format: Right {$index} should contain TASK_ID as integer")
				);
			}

			if ($rightsManager->getTaskById($taskId) === null)
			{
				return $result->addError(
					new Error("Invalid format: Right {$index} should contain known TASK_ID")
				);
			}

			$preparedRight = [
				'ACCESS_CODE' => $right['ACCESS_CODE'],
				'TASK_ID' => $taskId,
			];

			if (isset($right['NEGATIVE']))
			{
				// The column of the negative flag holds 0 or 1 only (@see \Bitrix\Disk\Internals\RightTable).
				// A cast is no option here: a form encoded request carries the flag as a string, and
				// (int)'true' is 0, which turns a refusing right into an allowing one without an error.
				$negative = filter_var($right['NEGATIVE'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if ($negative === null)
				{
					return $result->addError(
						new Error("Invalid format: Right {$index} should contain NEGATIVE as 0 or 1")
					);
				}

				$preparedRight['NEGATIVE'] = (int)$negative;
			}

			$preparedRights[] = $preparedRight;
		}

		return $result->setData($preparedRights);
	}

	/**
	 * Getting array of errors.
	 * @return Error[]
	 */
	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * Getting array of errors with the necessary code.
	 * @param string $code Code of error.
	 * @return Error[]
	 */
	public function getErrorsByCode($code)
	{
		return $this->errorCollection->getErrorsByCode($code);
	}

	/**
	 * Getting once error with the necessary code.
	 * @param string $code Code of error.
	 * @return Error[]
	 */
	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}
}
