<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\VibecodePort;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Vibecodeconnector\Internal\Exception\OrgStructureUnavailableException;
use Bitrix\Vibecodeconnector\Internal\Integration\HumanResources\OrgStructureGateway;

class Structure extends Base
{
	private const TYPE_DEPARTMENT = 'DEPARTMENT';
	private const LIST_DEFAULT_LIMIT = 100;
	private const LIST_MAX_LIMIT = 500;
	private const MATCH_CODES_LIMIT = 50;

	public function listAction(
		int $bitrixUserId = 0,
		string $type = self::TYPE_DEPARTMENT,
		int $limit = self::LIST_DEFAULT_LIMIT,
		int $offset = 0,
	): Response\AjaxJson
	{
		if ($type !== self::TYPE_DEPARTMENT)
		{
			$this->addError(new Error('Unsupported structure type', 'STRUCTURE_INVALID_REQUEST'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		if ($bitrixUserId <= 0 || $limit <= 0 || $limit > self::LIST_MAX_LIMIT || $offset < 0)
		{
			$this->addError(new Error('Invalid request parameters', 'STRUCTURE_INVALID_REQUEST'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		if (!$this->portalUserExists($bitrixUserId))
		{
			$this->addError(new Error('Portal user not found', 'STRUCTURE_USER_NOT_FOUND'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		try
		{
			return Response\AjaxJson::createSuccess([
				'nodes' => $this->getOrgStructureGateway()->listDepartments($bitrixUserId, $limit, $offset),
			]);
		}
		catch (OrgStructureUnavailableException $exception)
		{
			$this->addError(new Error($exception->getMessage(), (string)$exception->getErrorCode()));
		}
		catch (\Throwable $e)
		{
			$this->logFailure($e);
			$this->addError(new Error('Unable to read the org structure', 'STRUCTURE_LIST_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * @param list<string> $accessCodes
	 */
	public function matchAction(int $bitrixUserId = 0, array $accessCodes = []): Response\AjaxJson
	{
		if ($bitrixUserId <= 0 || $accessCodes === [])
		{
			$this->addError(new Error('User id and access codes are required', 'STRUCTURE_INVALID_REQUEST'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		if (count($accessCodes) > self::MATCH_CODES_LIMIT)
		{
			$this->addError(new Error('Too many access codes requested', 'STRUCTURE_LIMIT_EXCEEDED'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		try
		{
			$result = $this->getOrgStructureGateway()->matchDepartments($bitrixUserId, $accessCodes);

			return Response\AjaxJson::createSuccess([
				'matched' => $result['matched'],
				'unresolved' => $result['unresolved'],
			]);
		}
		catch (OrgStructureUnavailableException $exception)
		{
			$this->addError(new Error($exception->getMessage(), (string)$exception->getErrorCode()));
		}
		catch (\Throwable $e)
		{
			$this->logFailure($e);
			$this->addError(new Error('Unable to match the org structure', 'STRUCTURE_MATCH_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	protected function portalUserExists(int $userId): bool
	{
		return Main\UserTable::query()
			->setSelect(['ID'])
			->where('ID', $userId)
			->setLimit(1)
			->fetch() !== false
		;
	}

	protected function getOrgStructureGateway(): OrgStructureGateway
	{
		return ServiceLocator::getInstance()->get(OrgStructureGateway::class);
	}

	private function logFailure(\Throwable $e): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(
			$e,
			ExceptionHandlerLog::CAUGHT_EXCEPTION,
		);
	}
}
