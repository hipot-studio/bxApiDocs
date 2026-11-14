<?php

namespace Bitrix\Crm\Service\Operation;

use Bitrix\Crm\Automation\Helper;
use Bitrix\Crm\Automation\Starter;
use Bitrix\Crm\Integration\BizProc\Starter\CrmStarter;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\DocumentDto;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\RunDataDto;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Operation;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\EventManager;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\Result;
use CCrmBizProcEventType;

final class TransactionWrapper
{
	private readonly Connection $connection;
	private bool $committed = false;

	public function __construct(
		private readonly Operation $operation,
	)
	{
		$this->connection = Application::getConnection();
	}

	/**
	 * Launches the operation, properly wrapped in transaction.
	 *
	 * @return Result
	 */
	public function launch(): Result
	{
		if ($this->operation instanceof Operation\Delete)
		{
			return $this->launchWholeOperationInTransaction();
		}

		return $this->launchOperationInTransactionAndAutomationAfterIt();
	}

	/**
	 * Whether the transaction of the operation is committed. Set right after the commit, before the callbacks,
	 * business processes and robots that run after it, so an exception from them comes with true.
	 *
	 * @return bool
	 */
	public function isCommitted(): bool
	{
		return $this->committed;
	}

	public function launchWithCallbacks(
		callable $launcher,
		callable $onRollback,
		callable $afterCommit,
	): Result
	{
		return $this->launchOperationInTransactionAndAutomationAfterItWithCallbacks(
			$launcher,
			$onRollback,
			$afterCommit,
		);
	}

	private function launchWholeOperationInTransaction(): Result
	{
		$result = $this->launchInTransaction($this->operation->launch(...));
		if ($result->isSuccess())
		{
			$this->connection->commitTransaction();
			$this->committed = true;
		}
		else
		{
			$this->rollbackTransactionSafely();
		}

		return $result;
	}

	private function launchOperationInTransactionAndAutomationAfterIt(): Result
	{
		$isBizProcEnabled = $this->operation->isBizProcEnabled();
		$isAutomationEnabled = $this->operation->isAutomationEnabled();

		$this->operation
			->disableBizProc()
			->disableAutomation()
		;

		$result = $this->launchInTransaction($this->operation->launch(...));
		if (!$result->isSuccess())
		{
			$this->rollbackTransactionSafely();

			return $result;
		}

		$this->connection->commitTransaction();
		$this->committed = true;

		if ($isBizProcEnabled)
		{
			$this->runBizProc();
		}
		if ($isAutomationEnabled)
		{
			$this->runAutomation();
		}

		return $result;
	}

	private function launchOperationInTransactionAndAutomationAfterItWithCallbacks(
		callable $launcher,
		callable $onRollback,
		callable $afterCommit,
	): Result
	{
		$isBizProcEnabled = $this->operation->isBizProcEnabled();
		$isAutomationEnabled = $this->operation->isAutomationEnabled();

		$this->operation
			->disableBizProc()
			->disableAutomation()
		;

		$result = $this->launchInTransaction($launcher, $onRollback);
		if (!$result->isSuccess())
		{
			$this->rollbackTransactionSafely();
			$onRollback();

			return $result;
		}

		$this->connection->commitTransaction();
		$this->committed = true;
		$afterCommit();

		$isRecurringItem = $this->isRecurringItem();
		if (!$isRecurringItem && $isBizProcEnabled)
		{
			$this->runBizProc();
		}
		if (!$isRecurringItem && $isAutomationEnabled)
		{
			$this->runAutomation();
		}

		return $result;
	}

	/**
	 * Starts a transaction and runs the launcher in it. The transaction is left open for the caller to finish,
	 * unless the launcher throws: then it is rolled back and the exception goes up to the caller.
	 */
	private function launchInTransaction(callable $launcher, ?callable $onRollback = null): Result
	{
		$this->connection->startTransaction();

		try
		{
			return $launcher();
		}
		catch (\Throwable $throwable)
		{
			$this->rollbackTransactionSafely();
			if ($onRollback !== null)
			{
				$onRollback();
			}

			throw $throwable;
		}
	}

	private function rollbackTransactionSafely(): void
	{
		try
		{
			$this->connection->rollbackTransaction();
		}
		catch (\Bitrix\Main\DB\TransactionException)
		{
			// MySQL rolls back to a savepoint and reports nested rollback as an exception.
		}
	}

	private function isRecurringItem(): bool
	{
		$factory = Container::getInstance()->getFactory($this->operation->getItem()->getEntityTypeId());

		return $factory?->isRecurringSupported() && $this->operation->getItem()->getIsRecurring();
	}

	/**
	 * @see Operation::runBizProc() - copy-paste, except for the fields collected by prepareFieldsToCompare()
	 */
	private function runBizProc(): void
	{
		$bizProcEventType = null;
		if ($this->operation instanceof Operation\Add)
		{
			$bizProcEventType = \CCrmBizProcEventType::Create;
		}
		elseif ($this->operation instanceof Operation\Update)
		{
			$bizProcEventType = \CCrmBizProcEventType::Edit;
		}

		if ($bizProcEventType === null)
		{
			return;
		}

		$request = Application::getInstance()->getContext()->getRequest();
		$data = $request->getPost('data');
		$workflowParameters = $data['bizproc_parameters'] ?? null;

		$starter = null;
		try
		{
			$starter = new CrmStarter(new DocumentDto(
				$this->operation->getItem()->getEntityTypeId(),
				$this->operation->getItem()->getId()
			));
		}
		catch (ArgumentException $exception)
		{}

		if ($starter)
		{
			$scope = (
				$this->operation->getContext()->getScope() === Context::SCOPE_AUTOMATION
					? CrmStarter::AUTOMATION_SCOPE
					: ''
			)
			;
			if ($this->operation->getContext()->getScope() === Context::SCOPE_REST)
			{
				$scope = CrmStarter::REST_SCOPE;
			}

			[$actualFields, $previousFields] = $this->prepareFieldsToCompare();

			$starter->runProcess(
				new RunDataDto(
					actualFields: $actualFields,
					previousFields: $previousFields,
					userId: $this->operation->resolveAutomationInitiatorUserId(),
					parameters: is_array($workflowParameters) || is_string($workflowParameters) ? $workflowParameters : null,
					scope: $scope,
					categoryId: $this->operation->getItem()->isCategoriesSupported()
						? $this->operation->getItem()->getCategoryId()
						: null,
				),
				$bizProcEventType,
			);
		}
	}

	/**
	 * Saved and previous values of the item to compare, or nulls when there is nothing to compare with.
	 *
	 * An update aborted as a no-op keeps no item before save, but its diff is known and empty, not unknown:
	 * unlike Operation::runBizProc(), the wrapper is reached by such an update.
	 *
	 * @return array{0: array|null, 1: array|null}
	 */
	private function prepareFieldsToCompare(): array
	{
		$itemBeforeSave = $this->operation->getItemBeforeSave();
		if (!$itemBeforeSave)
		{
			return $this->operation instanceof Operation\Update ? [[], []] : [null, null];
		}

		return [
			Helper::prepareCompatibleData(
				$itemBeforeSave->getEntityTypeId(),
				$itemBeforeSave->getCompatibleData(Values::CURRENT)
			),
			Helper::prepareCompatibleData(
				$itemBeforeSave->getEntityTypeId(),
				$itemBeforeSave->getCompatibleData(Values::ACTUAL)
			),
		];
	}

	/**
	 * @see Operation::runAutomation() - copy-paste
	 */
	private function runAutomation(): void
	{
		try
		{
			$starter = new CrmStarter(new DocumentDto(
				$this->operation->getItem()->getEntityTypeId(),
				$this->operation->getItem()->getId()
			));
		}
		catch (ArgumentException $exception)
		{
			return;
		}

		$scope = (
			$this->operation->getContext()->getScope() === Context::SCOPE_AUTOMATION
				? CrmStarter::AUTOMATION_SCOPE
				: ''
		);
		if ($this->operation->getContext()->getScope() === Context::SCOPE_REST)
		{
			$scope = CrmStarter::REST_SCOPE;
		}

		$userId = $this->operation->resolveAutomationInitiatorUserId();

		$eventType = $this->operation->getItem()->getEntityEventName('OnAfterUpdate');
		$eventId = EventManager::getInstance()->addEventHandler(
			'crm',
			$eventType,
			[$this->operation, 'updateItemFromUpdateEvent']
		);

		if ($this->operation instanceof Operation\Add)
		{
			$starter->runAutomation(new RunDataDto(userId: $userId, scope: $scope), CCrmBizProcEventType::Create);
		}
		elseif (
			$this->operation instanceof Operation\Update
			// maybe the item wasn't changed and the operation was aborted
			&& $this->operation->getItemBeforeSave()
		)
		{
			$starter->runAutomation(
				new RunDataDto(
					actualFields: Helper::prepareCompatibleData(
						$this->operation->getItemBeforeSave()->getEntityTypeId(),
						$this->operation->getItemBeforeSave()->getCompatibleData(Values::CURRENT)
					),
					previousFields: Helper::prepareCompatibleData(
						$this->operation->getItemBeforeSave()->getEntityTypeId(),
						$this->operation->getItemBeforeSave()->getCompatibleData(Values::ACTUAL)
					),
					userId: $userId,
					scope: $scope
				),
				CCrmBizProcEventType::Edit
			);
		}

		EventManager::getInstance()->removeEventHandler('crm', $eventType, $eventId);
	}
}
