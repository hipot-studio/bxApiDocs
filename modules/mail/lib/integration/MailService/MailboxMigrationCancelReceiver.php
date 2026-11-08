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
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class MailboxMigrationCancelReceiver
{
	public const ERROR_CONTRACT_INVALID = 'MAIL_MIGRATION_CANCEL_CONTRACT_INVALID';

	private const OPERATION_ID_MAX_LENGTH = 64;

	public function __construct(
		private readonly MigrationService $migrationService = new MigrationService(),
		private readonly OperationTracker $operationTracker = new OperationTracker(),
		private readonly MigrationUserStatusPublisher $statusPublisher = new MigrationUserStatusPublisher(),
	)
	{
	}

	public function accept(MailboxMigrationCancel $cancel): Result
	{
		$operationId = trim($cancel->operationId());
		$mailboxId = $cancel->mailboxId();

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > self::OPERATION_ID_MAX_LENGTH
		)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error(
					'A valid mailbox migration cancellation is expected',
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

		$connection = Application::getConnection();
		$result = new Result();
		$tracked = null;
		$changed = false;
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
			$known = $this->operationTracker->findWithinTransaction($mailboxId, $operationId);
			if ((string)($known['EXEC_STATE'] ?? '') === MigrationOperationTable::STATE_CANCELLED)
			{
				$stage = MigrationStage::tryFrom((string)$known['STAGE']) ?? MigrationStage::Legacy;
				$tracked = ['success' => true, 'changed' => false, 'operation' => $known];
				$result->setData(['stage' => $stage]);
				$connection->commitTransaction();
				$transactionStarted = false;
			}
			else
			{
				$result = $this->migrationService->requestCancellationUnderLock($mailboxId, $operationId);
				$stage = $result->getData()['stage'] ?? MigrationStage::Legacy;
				$generationId = (int)($result->getData()['generationId'] ?? 0);
				if (!$result->isSuccess() || !$stage instanceof MigrationStage || $generationId <= 0)
				{
					$connection->rollbackTransaction();
					$transactionStarted = false;

					return RetryPolicy::enrichCommandResult($result);
				}

				$tracked = $this->operationTracker->requestCancellationUnderLock(
					$mailboxId,
					$operationId,
					$generationId,
					$stage,
					(bool)($result->getData()['switchWasAuthorized'] ?? false),
				);
				if (!($tracked['success'] ?? false))
				{
					$connection->rollbackTransaction();
					$transactionStarted = false;

					return RetryPolicy::enrichCommandResult($result->addError(new Error(
						'The migration cancellation scheduling snapshot is not saved',
						MigrationService::ERROR_CANCEL_NOT_SAVED,
					)));
				}

				$changed = (bool)($tracked['changed'] ?? false);
				$result->setData(['stage' => $stage]);
				$connection->commitTransaction();
				$transactionStarted = false;
			}
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
					'The migration cancellation request is not saved',
					MigrationService::ERROR_CANCEL_NOT_SAVED,
				))
			;
		}
		finally
		{
			OperationLock::release($mailboxId);
		}

		if ($result->isSuccess() && is_array($tracked))
		{
			$stage = $result->getData()['stage'];
			if ($changed)
			{
				MigrationMetrics::journalOperation(
					'cancellation requested',
					$mailboxId,
					$operationId,
					(int)($tracked['operation']['GENERATION_ID'] ?? 0),
					$stage->value,
				);
			}
			$this->publishPending(
				$mailboxId,
				$operationId,
				(int)($tracked['operation']['GENERATION_ID'] ?? 0),
				$stage,
			);
		}

		return RetryPolicy::enrichCommandResult($result);
	}

	private function publishPending(
		int $mailboxId,
		string $operationId,
		int $generationId,
		MigrationStage $stage,
	): void
	{
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
				'cancellation publication deferred',
				$mailboxId,
				$operationId,
				$generationId,
				$stage->value,
				RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
			);
		}
	}
}
