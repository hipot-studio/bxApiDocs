<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationLock;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internal\Service\SourceGeneration\RetryPolicy;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationUserStatusPublisher;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Result;

final class MailboxSwitchAuthorizationReceiver
{
	public const ERROR_CONTRACT_INVALID = 'MAIL_SWITCH_AUTHORIZATION_CONTRACT_INVALID';

	private const OPERATION_ID_MAX_LENGTH = 64;

	public function __construct(
		private readonly MigrationService $migrationService = new MigrationService(),
		private readonly OperationTracker $operationTracker = new OperationTracker(),
		private readonly MigrationUserStatusPublisher $statusPublisher = new MigrationUserStatusPublisher(),
		private readonly ?Connection $connection = null,
	)
	{
	}

	public function accept(MailboxSwitchAuthorization $authorization): Result
	{
		$operationId = trim($authorization->operationId());
		$mailboxId = $authorization->mailboxId();

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > self::OPERATION_ID_MAX_LENGTH
		)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error(
					'A valid mailbox switch authorization is expected',
					self::ERROR_CONTRACT_INVALID,
				)),
			);
		}

		if (!OperationLock::acquire($mailboxId))
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => $this->migrationService->getStage($mailboxId, $operationId)])
				->addError(new Error(
					'The mailbox migration operation is already running',
					MigrationService::ERROR_OPERATION_BUSY,
				)),
			);
		}

		$connection = $this->connection ?? Application::getConnection();
		$changed = false;
		$tracked = null;
		$result = new Result();
		$transactionStarted = false;
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$locked = (new SettingsWriteCoordinator())->lockWithinTransaction($mailboxId, true, $operationId);
			if (!$locked->isSuccess())
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;

				return RetryPolicy::enrichCommandResult($locked->setData(['stage' => MigrationStage::Legacy]));
			}
			$result = $this->migrationService->authorizeSwitchUnderLock($mailboxId, $operationId);
			$stage = $result->getData()['stage'] ?? MigrationStage::Legacy;
			if (!$result->isSuccess() || !$stage instanceof MigrationStage)
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;

				return RetryPolicy::enrichCommandResult($result);
			}

			$tracked = $this->operationTracker->authorizeSwitchUnderLock($mailboxId, $operationId, $stage);
			if (!($tracked['success'] ?? false))
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;

				return RetryPolicy::enrichCommandResult($result->addError(new Error(
					'The mailbox switch authorization scheduling snapshot is not saved',
					MigrationService::ERROR_SWITCH_AUTHORIZATION_NOT_SAVED,
				)));
			}

			$changed = (bool)($tracked['changed'] ?? false);
			$connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (\Throwable)
		{
			if ($transactionStarted)
			{
				$connection->rollbackTransaction();
			}
			$result = (new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error(
					'The mailbox switch authorization is not saved',
					MigrationService::ERROR_SWITCH_AUTHORIZATION_NOT_SAVED,
				))
			;
		}
		finally
		{
			OperationLock::release($mailboxId);
		}

		if ($result->isSuccess())
		{
			$stage = $result->getData()['stage'];
			if ($changed)
			{
				MigrationMetrics::journalOperation(
					'switch authorization received',
					$mailboxId,
					$operationId,
					(int)($tracked['operation']['GENERATION_ID'] ?? 0),
					$stage->value,
				);
			}
			try
			{
				$published = $this->operationTracker->publishPending(
					$mailboxId,
					$operationId,
					$this->statusPublisher,
				);
			}
			catch (\Throwable)
			{
				$published = false;
			}
			if (!$published)
			{
				MigrationMetrics::journalOperation(
					'switch authorization publication deferred',
					$mailboxId,
					$operationId,
					(int)($tracked['operation']['GENERATION_ID'] ?? 0),
					$stage->value,
					RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
				);
			}
		}

		return RetryPolicy::enrichCommandResult($result);
	}
}
