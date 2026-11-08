<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Integration\MailService\MailboxMigrationCancelReceiver;
use Bitrix\Mail\Integration\MailService\MailboxSwitchAuthorizationReceiver;
use Bitrix\Mail\Integration\MailService\ReadyMailboxReceiver;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Pure scheduling policy shared by the background loop and command receivers.
 */
final class RetryPolicy
{
	public const CLASS_RETRYABLE = 'retryable';
	public const CLASS_EXTERNAL_FIX = 'external_fix';
	public const CLASS_TERMINAL = 'terminal';
	public const CLASS_PAUSED = 'paused';
	public const CONTEXT_COMMAND = 'command';
	public const CONTEXT_ACCEPTED = 'accepted';
	public const FAILURE_TRANSIENT = 'transient';
	public const FAILURE_CREDENTIALS = 'credentials';
	public const FAILURE_CONFIGURATION = 'configuration';

	public const ERROR_AGENT_EXCEPTION = 'MAIL_SOURCE_GENERATION_AGENT_EXCEPTION';
	public const ERROR_NO_PROGRESS = 'MAIL_SOURCE_GENERATION_NO_PROGRESS';
	public const ERROR_SCHEDULER_EXCEPTION = 'MAIL_SOURCE_GENERATION_SCHEDULER_EXCEPTION';

	private const MAX_STALLED_ATTEMPTS = 10;
	private const MAX_STALLED_SECONDS = 86400;
	private const AWAIT_POLL_SECONDS = 20 * 60;
	/*
		A pass under a manual wait moves the transfer nowhere: the grant of a stage schedules the
		continuation itself and never waits for a poll. Polling is left only to notice the mode
		being switched off for the whole portal, so it runs as rarely as the wait for an external
		answer does.
	*/
	private const MANUAL_POLL_SECONDS = self::AWAIT_POLL_SECONDS;
	private const BUSY_RETRY_SECONDS = 5 * 60;
	private const NETWORK_RETRY_MAX_SECONDS = 30 * 60;
	private const TAIL_RETRY_MAX_SECONDS = 5 * 60;

	/**
	 * @return array{
	 *     execState: string,
	 *     stage: MigrationStage,
	 *     autoRetry: bool,
	 *     stalledAttempts: int,
	 *     nextRun: ?DateTime,
	 *     lastProgress: DateTime,
	 *     errorCode: ?string,
	 *     errorOwner: ?string
	 * }
	 */
	public function decide(
		array $operation,
		MigrationStage $stage,
		?string $errorCode,
		bool $progressed,
		?DateTime $now = null,
		bool $manualWaiting = false,
		?DateTime $retryResumedAt = null,
		bool $retryAttemptsReset = false,
	): array
	{
		$now ??= new DateTime();
		$lastProgress = $this->dateOf($operation['LAST_PROGRESS'] ?? null)
			?? $this->dateOf($operation['DATE_CREATE'] ?? null)
			?? clone $now;
		$retryBudgetResumed = $retryResumedAt !== null
			&& $retryResumedAt->getTimestamp() > $lastProgress->getTimestamp();
		$lastProgressForBudget = $retryBudgetResumed ? clone $retryResumedAt : clone $lastProgress;
		$stalledAttempts = (int)($operation['STALLED_ATTEMPTS'] ?? 0);
		if ($retryAttemptsReset)
		{
			$stalledAttempts = 0;
		}

		if ($progressed)
		{
			$stalledAttempts = 0;
			$lastProgress = clone $now;
			$lastProgressForBudget = clone $now;
		}

		if ($stage->hasNowhereToGo())
		{
			return $this->decision(
				MigrationOperationTable::STATE_DONE,
				$stage,
				false,
				$stalledAttempts,
				null,
				$lastProgress,
				null,
				null,
			);
		}

		if ($manualWaiting)
		{
			return $this->decision(
				MigrationOperationTable::STATE_WAITING,
				$stage,
				true,
				$stalledAttempts,
				$this->after($now, self::MANUAL_POLL_SECONDS),
				$lastProgress,
				null,
				null,
			);
		}

		if ($errorCode === null && $stage->awaitsExternalInput())
		{
			return $this->decision(
				MigrationOperationTable::STATE_WAITING,
				$stage,
				true,
				$stalledAttempts,
				$this->after($now, self::AWAIT_POLL_SECONDS),
				$lastProgress,
				null,
				null,
			);
		}

		if ($errorCode === null)
		{
			if (!$progressed)
			{
				$stalledAttempts++;
			}
			if (
				$stalledAttempts >= self::MAX_STALLED_ATTEMPTS
				|| $now->getTimestamp() - $lastProgressForBudget->getTimestamp() >= self::MAX_STALLED_SECONDS
			)
			{
				return $this->decision(
					MigrationOperationTable::STATE_NEEDS_ATTENTION,
					$stage,
					false,
					$stalledAttempts,
					null,
					$lastProgress,
					self::ERROR_NO_PROGRESS,
					MigrationOperationTable::ERROR_OWNER_MAIL,
				);
			}

			return $this->decision(
				MigrationOperationTable::STATE_SCHEDULED,
				$stage,
				true,
				$stalledAttempts,
				clone $now,
				$lastProgress,
				null,
				null,
			);
		}

		$class = self::classifyAccepted($errorCode);
		$owner = self::ownerOf($errorCode, self::CONTEXT_ACCEPTED);

		if ($class === self::CLASS_PAUSED)
		{
			return $this->decision(
				MigrationOperationTable::STATE_WAITING,
				$stage,
				true,
				$stalledAttempts,
				$this->after($now, self::AWAIT_POLL_SECONDS),
				$lastProgress,
				$errorCode,
				$owner,
			);
		}

		if ($class !== self::CLASS_RETRYABLE)
		{
			return $this->decision(
				MigrationOperationTable::STATE_NEEDS_ATTENTION,
				$stage,
				false,
				$stalledAttempts,
				null,
				$lastProgress,
				$errorCode,
				$owner,
			);
		}

		if (!$progressed)
		{
			$stalledAttempts++;
		}
		if (
			$stalledAttempts >= self::MAX_STALLED_ATTEMPTS
			|| $now->getTimestamp() - $lastProgressForBudget->getTimestamp() >= self::MAX_STALLED_SECONDS
		)
		{
			return $this->decision(
				MigrationOperationTable::STATE_NEEDS_ATTENTION,
				$stage,
				false,
				$stalledAttempts,
				null,
				$lastProgress,
				$errorCode,
				$owner,
			);
		}

		return $this->decision(
			MigrationOperationTable::STATE_SCHEDULED,
			$stage,
			true,
			$stalledAttempts,
			$this->after($now, $this->backoff($errorCode, $stage, $stalledAttempts)),
			$lastProgress,
			$errorCode,
			$owner,
		);
	}

	/**
	 * @return array{
	 *     errorOwner: ?string,
	 *     retryable: bool,
	 *     failureCategory: ?string,
	 *     retryAfter: ?int,
	 * }
	 */
	public static function commandMetadata(Result $result): array
	{
		$error = $result->getErrors()[0] ?? null;
		if ($error === null)
		{
			return [
				'errorOwner' => null,
				'retryable' => false,
				'failureCategory' => null,
				'retryAfter' => null,
			];
		}

		$code = (string)$error->getCode();
		$class = self::classifyCommand($code);

		return [
			'errorOwner' => self::ownerOf($code, self::CONTEXT_COMMAND),
			'retryable' => in_array($class, [self::CLASS_RETRYABLE, self::CLASS_EXTERNAL_FIX, self::CLASS_PAUSED], true),
			'failureCategory' => self::commandFailureCategory($error),
			'retryAfter' => null,
		];
	}

	private static function commandFailureCategory(Error $error): ?string
	{
		$code = (string)$error->getCode();
		if (in_array($code, [
			ConnectionSnapshotValidator::ERROR_IMAP_INVALID,
			ConnectionSnapshotValidator::ERROR_SMTP_INVALID,
		], true))
		{
			return self::FAILURE_CONFIGURATION;
		}

		if (!in_array($code, [
			MigrationService::ERROR_CONNECTION_UNAVAILABLE,
			SmtpSnapshotSwitcher::ERROR_CONNECTION_UNAVAILABLE,
		], true))
		{
			return null;
		}

		$customData = $error->getCustomData();
		$type = is_array($customData) ? ($customData['type'] ?? null) : null;
		if ($type === \Bitrix\Mail\Helper\Mailbox\MailboxConnector::RESPONSE_ERROR_CODE_WRONG_AUTH)
		{
			return self::FAILURE_CREDENTIALS;
		}

		if (in_array($type, [
			\Bitrix\Mail\Helper\Mailbox\MailboxConnector::RESPONSE_ERROR_CODE_IMAP_CONNECTION,
			\Bitrix\Mail\Helper\Mailbox\MailboxConnector::RESPONSE_ERROR_CODE_SMTP_CONNECTION,
		], true))
		{
			return self::FAILURE_TRANSIENT;
		}

		return null;
	}

	public static function enrichCommandResult(Result $result): Result
	{
		return $result->setData($result->getData() + self::commandMetadata($result));
	}

	public static function classifyAccepted(string $errorCode): string
	{
		if (in_array($errorCode, [
			MigrationService::ERROR_MIGRATION_STOPPED,
			MigrationService::ERROR_FEATURE_DISABLED,
		], true))
		{
			return self::CLASS_PAUSED;
		}

		if (in_array($errorCode, [
			MigrationService::ERROR_OPERATION_BUSY,
			MigrationService::ERROR_CONNECTION_UNAVAILABLE,
			MigrationService::ERROR_SHADOW_FAILED,
			MigrationService::ERROR_IMPORT_FAILED,
			MigrationService::ERROR_FOLDERS_UNAVAILABLE,
			MigrationService::ERROR_FINAL_SYNC_FAILED,
			/*
				The pass of catching up gave the page back refused, and the commonest reason for
				it is the new source that could not be reached right now - the same thing the
				final synchronization answers with, and it stands here for the same reason: the
				connection comes back and the next run walks the same cursor again. The refusal
				keeps the switch from happening until a pass goes through; what it must not do is
				keep the operation from ever trying again.
			*/
			MigrationService::ERROR_REPLAY_REFUSED,
			MigrationService::ERROR_SNAPSHOT_REVISION_CONFLICT,
			SmtpSnapshotSwitcher::ERROR_CONNECTION_UNAVAILABLE,
			Switcher::ERROR_MAILBOX_LOCKED,
			Switcher::ERROR_TARGET_REVISION_CONFLICT,
			Switcher::ERROR_POINTER_CONFLICT,
			Switcher::ERROR_G1_NOT_READY,
			TailAppendService::ERROR_TAIL_FAILED,
			self::ERROR_AGENT_EXCEPTION,
		], true))
		{
			return self::CLASS_RETRYABLE;
		}

		if (in_array($errorCode, [
			MigrationService::ERROR_INCOME_FOLDER_MISSING,
			MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
			TailAppendService::ERROR_TAIL_SOURCE_REFUSES,
			TailAppendService::ERROR_TAIL_FOLDER_MISSING,
		], true))
		{
			return self::CLASS_EXTERNAL_FIX;
		}

		return self::CLASS_TERMINAL;
	}

	public static function canResumeAfterSwitchAuthorization(?string $errorCode): bool
	{
		return $errorCode === self::ERROR_NO_PROGRESS
			|| ($errorCode !== null && self::classifyAccepted($errorCode) === self::CLASS_RETRYABLE);
	}

	public static function classifyCommand(string $errorCode): string
	{
		if (in_array($errorCode, [
			MigrationService::ERROR_FEATURE_DISABLED,
			MigrationService::ERROR_MIGRATION_STOPPED,
			/*
				A portal the transfer has not been rolled out to yet: the same question answers
				yes once the update lands, so it stands with the two switches rather than with a
				failure. The class is also what tells the other side the refusal is free - their
				list of the words of this module is kept level with this very branch.
			*/
			MigrationService::ERROR_OPERATION_SCHEMA_MISSING,
		], true))
		{
			return self::CLASS_PAUSED;
		}

		if (in_array($errorCode, [
			MigrationService::ERROR_OPERATION_BUSY,
			MigrationService::ERROR_SNAPSHOT_REVISION_CONFLICT,
			MigrationService::ERROR_SNAPSHOT_NOT_SAVED,
			MigrationService::ERROR_GENERATION_NOT_CREATED,
			MigrationService::ERROR_SWITCH_AUTHORIZATION_NOT_SAVED,
			MigrationService::ERROR_CANCEL_NOT_SAVED,
		], true))
		{
			return self::CLASS_RETRYABLE;
		}

		if (in_array($errorCode, [
			ConnectionSnapshotValidator::ERROR_IMAP_INVALID,
			ConnectionSnapshotValidator::ERROR_SMTP_INVALID,
			MigrationService::ERROR_CONNECTION_UNAVAILABLE,
			MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
			SmtpSnapshotSwitcher::ERROR_CONNECTION_UNAVAILABLE,
		], true))
		{
			return self::CLASS_EXTERNAL_FIX;
		}

		return self::CLASS_TERMINAL;
	}

	public static function ownerOf(string $errorCode, string $context = self::CONTEXT_ACCEPTED): string
	{
		if ($context === self::CONTEXT_COMMAND)
		{
			if (in_array($errorCode, [
				ReadyMailboxReceiver::ERROR_CONTRACT_INVALID,
				ReadyMailboxReceiver::ERROR_MAILBOX_NOT_FOUND,
				ReadyMailboxReceiver::ERROR_MAILBOX_INACTIVE,
				MailboxSwitchAuthorizationReceiver::ERROR_CONTRACT_INVALID,
				MailboxMigrationCancelReceiver::ERROR_CONTRACT_INVALID,
				ConnectionSnapshotValidator::ERROR_EMAIL_INVALID,
				ConnectionSnapshotValidator::ERROR_IMAP_INVALID,
				ConnectionSnapshotValidator::ERROR_SMTP_INVALID,
				MigrationService::ERROR_OPERATION_INVALID,
				MigrationService::ERROR_MAILBOX_NOT_FOUND,
				MigrationService::ERROR_MAILBOX_NOT_SUPPORTED,
				MigrationService::ERROR_OPERATION_BUSY,
				MigrationService::ERROR_ANOTHER_OPERATION,
				MigrationService::ERROR_SNAPSHOT_IDENTITY_CONFLICT,
				MigrationService::ERROR_SNAPSHOT_REVISION_CONFLICT,
				MigrationService::ERROR_OPERATION_NOT_FOUND,
				MigrationService::ERROR_CANCEL_REQUESTED,
				MigrationService::ERROR_CANCEL_TOO_LATE,
			], true))
			{
				return MigrationOperationTable::ERROR_OWNER_MAILSERVICE;
			}

			if (in_array($errorCode, [
				MigrationService::ERROR_CONNECTION_UNAVAILABLE,
				MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
				MigrationService::ERROR_MIGRATION_STOPPED,
				MigrationService::ERROR_FEATURE_DISABLED,
				MigrationService::ERROR_OPERATION_SCHEMA_MISSING,
				SmtpSnapshotSwitcher::ERROR_CONNECTION_UNAVAILABLE,
			], true))
			{
				return MigrationOperationTable::ERROR_OWNER_INFRASTRUCTURE;
			}

			/*
				A readiness that could not be answered falls here on purpose: a schema left half
				built is a state of this module, and the owner of it is this module.
			*/
			return MigrationOperationTable::ERROR_OWNER_MAIL;
		}

		if (in_array($errorCode, [
			MigrationService::ERROR_CONNECTION_UNAVAILABLE,
			MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
			MigrationService::ERROR_INCOME_FOLDER_MISSING,
			MigrationService::ERROR_SHADOW_FAILED,
			MigrationService::ERROR_IMPORT_FAILED,
			MigrationService::ERROR_FOLDERS_UNAVAILABLE,
			MigrationService::ERROR_FINAL_SYNC_FAILED,
			TailAppendService::ERROR_TAIL_FAILED,
			TailAppendService::ERROR_TAIL_SOURCE_REFUSES,
			TailAppendService::ERROR_TAIL_FOLDER_MISSING,
			MigrationService::ERROR_MIGRATION_STOPPED,
			MigrationService::ERROR_FEATURE_DISABLED,
			SmtpSnapshotSwitcher::ERROR_CONNECTION_UNAVAILABLE,
		], true))
		{
			return MigrationOperationTable::ERROR_OWNER_INFRASTRUCTURE;
		}

		return MigrationOperationTable::ERROR_OWNER_MAIL;
	}

	private function backoff(string $errorCode, MigrationStage $stage, int $stalledAttempts): int
	{
		if (in_array($errorCode, [MigrationService::ERROR_OPERATION_BUSY, Switcher::ERROR_MAILBOX_LOCKED], true))
		{
			return self::BUSY_RETRY_SECONDS;
		}

		$isRevisionConflict = in_array($errorCode, [
			MigrationService::ERROR_SNAPSHOT_REVISION_CONFLICT,
			Switcher::ERROR_TARGET_REVISION_CONFLICT,
		], true);
		if ($stalledAttempts === 1 && $isRevisionConflict)
		{
			return 0;
		}

		$backoffAttempt = $isRevisionConflict ? $stalledAttempts - 1 : $stalledAttempts;
		$seconds = min(
			self::NETWORK_RETRY_MAX_SECONDS,
			60 * (2 ** min(5, max(0, $backoffAttempt - 1))),
		);

		return $stage === MigrationStage::TailAppend ? min($seconds, self::TAIL_RETRY_MAX_SECONDS) : $seconds;
	}

	private function after(DateTime $now, int $seconds): DateTime
	{
		return DateTime::createFromTimestamp($now->getTimestamp() + $seconds);
	}

	private function dateOf(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return clone $value;
		}

		return null;
	}

	private function decision(
		string $execState,
		MigrationStage $stage,
		bool $autoRetry,
		int $stalledAttempts,
		?DateTime $nextRun,
		DateTime $lastProgress,
		?string $errorCode,
		?string $errorOwner,
	): array
	{
		return compact(
			'execState',
			'stage',
			'autoRetry',
			'stalledAttempts',
			'nextRun',
			'lastProgress',
			'errorCode',
			'errorOwner',
		);
	}
}
