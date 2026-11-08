<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Internal\Service\SourceGeneration\ConnectionSnapshot;
use Bitrix\Mail\Internal\Service\SourceGeneration\ConnectionSnapshotValidator;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internal\Service\SourceGeneration\RetryPolicy;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ReadyMailboxReceiver
{
	public const ERROR_CONTRACT_INVALID = 'MAIL_READY_MAILBOX_CONTRACT_INVALID';
	public const ERROR_MAILBOX_NOT_FOUND = 'MAIL_READY_MAILBOX_NOT_FOUND';
	public const ERROR_MAILBOX_INACTIVE = 'MAIL_READY_MAILBOX_INACTIVE';

	private const OPERATION_ID_MAX_LENGTH = 64;

	public function __construct(
		private readonly MigrationService $migrationService = new MigrationService(),
		private readonly ConnectionSnapshotValidator $snapshotValidator = new ConnectionSnapshotValidator(),
		private readonly OperationTracker $operationTracker = new OperationTracker(),
	)
	{
	}

	public function accept(ReadyMailbox $readyMailbox): Result
	{
		$operationId = trim($readyMailbox->operationId());
		$mailboxId = $readyMailbox->mailboxId();

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > self::OPERATION_ID_MAX_LENGTH
		)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('A valid ready mailbox contract is expected', self::ERROR_CONTRACT_INVALID))
			);
		}

		$mailbox = MailboxTable::getList([
			'select' => ['ID', 'ACTIVE', 'LID'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();

		if (!$mailbox)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('The mailbox is not found', self::ERROR_MAILBOX_NOT_FOUND))
			);
		}

		/*
			The deletion of a mailbox leaves its row behind and only takes the active mark away,
			so the existence above tells nothing about a mailbox that is still served. A source
			that merely fails to answer never touches that mark - such a mailbox is exactly the
			one a hand-over is made for, and it goes on.
		*/
		if ((string)$mailbox['ACTIVE'] !== MailboxStatus::Active->value)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('The mailbox is not active', self::ERROR_MAILBOX_INACTIVE))
			);
		}

		$knownOperation = $this->operationTracker->find($mailboxId, $operationId);
		if ($knownOperation !== null)
		{
			$knownStage = MigrationStage::tryFrom((string)$knownOperation['STAGE']) ?? MigrationStage::Legacy;
			if ((string)$knownOperation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLED)
			{
				return RetryPolicy::enrichCommandResult(
					(new Result())->setData(['stage' => $knownStage]),
				);
			}

			if (
				(string)$knownOperation['CANCEL_REQUESTED'] === 'Y'
				|| (string)$knownOperation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLING
			)
			{
				return RetryPolicy::enrichCommandResult((new Result())
					->setData(['stage' => $knownStage])
					->addError(new Error(
						'The migration operation is being cancelled',
						MigrationService::ERROR_CANCEL_REQUESTED,
					))
				);
			}
		}

		$validated = $this->snapshotValidator->validate($readyMailbox->connectionSnapshot());
		if (!$validated->isSuccess())
		{
			return RetryPolicy::enrichCommandResult($validated->setData(['stage' => MigrationStage::Legacy]));
		}

		$snapshot = $validated->getData()[ConnectionSnapshotValidator::RESULT_SNAPSHOT] ?? null;
		if (!$snapshot instanceof ConnectionSnapshot)
		{
			return RetryPolicy::enrichCommandResult((new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('A valid ready mailbox contract is expected', self::ERROR_CONTRACT_INVALID))
			);
		}

		$resolvedServiceId = $this->resolveServiceId($mailbox, $snapshot);
		if ($resolvedServiceId !== (int)$snapshot->imap()['SERVICE_ID'])
		{
			$snapshot = new ConnectionSnapshot(
				$snapshot->email(),
				array_merge($snapshot->imap(), ['SERVICE_ID' => $resolvedServiceId]),
				$snapshot->smtp(),
			);
		}

		$result = $this->migrationService->acceptSnapshot(
			$mailboxId,
			$operationId,
			$snapshot,
			trim($readyMailbox->migratorService()),
		);
		if (!$result->isSuccess())
		{
			return RetryPolicy::enrichCommandResult($result);
		}

		$generation = MailboxSourceGenerationTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => $operationId,
			],
			'limit' => 1,
		])->fetch();
		$stage = $result->getData()['stage'] ?? MigrationStage::Legacy;

		if (
			!$stage instanceof MigrationStage
			|| !$generation
			|| !$this->operationTracker->acceptSnapshot(
				$mailboxId,
				$operationId,
				(int)$generation['ID'],
				$stage,
				trim($readyMailbox->migratorService()),
			)
		)
		{
			$result->addError(new Error(
				'The migration operation scheduling snapshot is not saved',
				MigrationService::ERROR_SNAPSHOT_NOT_SAVED,
			));
		}
		else
		{
			MigrationMetrics::journalOperation(
				'snapshot accepted',
				$mailboxId,
				$operationId,
				(int)$generation['ID'],
				$stage->value,
			);
		}

		return RetryPolicy::enrichCommandResult($result);
	}

	/**
	 * The number of the mail service the kit belongs to.
	 *
	 * The other side names the service and leaves the number at zero: the directory of the services
	 * belongs to this module, so only this module can turn a name into a number. A number it did
	 * name is kept as it is - the contract allows it to start sending one of its own.
	 *
	 * A service that is not in the directory yet is no reason to refuse the kit. It means the portal
	 * has not been updated far enough; the mailbox moves on the credentials it came with, and the
	 * number reaches it later - by a repeated kit if one comes, and otherwise by the background walk
	 * the install of the catalogue asks for when it puts such a row in
	 * {@see \Bitrix\Mail\Internal\Service\SourceGeneration\LateServiceBinder}. The resolve never
	 * creates the row either: the directory has one owner, the installer.
	 */
	private function resolveServiceId(array $mailbox, ConnectionSnapshot $snapshot): int
	{
		$serviceId = (int)($snapshot->imap()['SERVICE_ID'] ?? 0);
		if ($serviceId > 0)
		{
			return $serviceId;
		}

		// The site of the mailbox, not of the request: a kit arrives on a background call, which has
		// no current site of its own.
		return MailServicesTable::findImapServiceId(
			(string)($mailbox['LID'] ?? ''),
			(string)($snapshot->imap()['SERVICE_NAME'] ?? ''),
		);
	}
}
