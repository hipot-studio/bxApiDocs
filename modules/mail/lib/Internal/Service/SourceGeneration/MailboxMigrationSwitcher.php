<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Public\Service\Mailbox\Alias\AddressHistory;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class MailboxMigrationSwitcher
{
	public const ERROR_MAILBOX_NOT_FOUND = 'MAIL_SOURCE_GENERATION_SWITCH_MAILBOX_NOT_FOUND';
	public const ERROR_EMAIL_INVALID = 'MAIL_SOURCE_GENERATION_SWITCH_EMAIL_INVALID';
	public const ERROR_EMAIL_UPDATE_FAILED = 'MAIL_SOURCE_GENERATION_SWITCH_EMAIL_UPDATE_FAILED';
	public const ERROR_SWITCH_FAILED = 'MAIL_SOURCE_GENERATION_BUSINESS_SWITCH_FAILED';
	public const RESULT_IMAP_DIRECTORIES = 'imapDirectories';
	/** The provisioned block of the inspection: {pairs: [{source, target, created}], notCreated: [{source, reason, directoryId}]} */
	public const RESULT_FOLDER_OUTCOMES = 'folderOutcomes';

	public function __construct(
		private readonly Switcher $generationSwitcher = new Switcher(),
		private readonly SmtpSnapshotSwitcher $smtpSwitcher = new SmtpSnapshotSwitcher(),
		private readonly AddressHistory $addressHistory = new AddressHistory(),
		private readonly EmailNormalizer $emailNormalizer = new EmailNormalizer(),
	)
	{
	}

	/**
	 * Owns the switch transaction; the caller holds OperationLock before entering.
	 * @param array $heldLock The mailbox synchronization lease held over final sync and delta.
	 */
	public function switch(
		int $mailboxId,
		string $operationId,
		ConnectionSnapshot $snapshot,
		int $expectedTargetRevision,
		int $expectedActiveGenerationId,
		array &$heldLock,
		array $preparedSmtp,
	): Result
	{
		$alreadySwitched = $this->alreadySwitched($mailboxId, $operationId);
		if ($alreadySwitched !== null)
		{
			return (new Result())->setData([
				'generationId' => $alreadySwitched,
				'alreadySwitched' => true,
			]);
		}

		$targetEmail = $this->emailNormalizer->normalize($snapshot->email());
		if ($targetEmail === null)
		{
			return (new Result())->addError(new Error(
				'A valid target mailbox address is expected',
				self::ERROR_EMAIL_INVALID,
			));
		}

		$apply = function() use (
			$mailboxId,
			$operationId,
			$expectedTargetRevision,
			$expectedActiveGenerationId,
			&$heldLock,
			$targetEmail,
			$preparedSmtp,
		): Result {
			return $this->applyWithinTransaction(
				$mailboxId,
				$operationId,
				$expectedTargetRevision,
				$expectedActiveGenerationId,
				$heldLock,
				$targetEmail,
				$preparedSmtp,
			);
		};

		return $apply();
	}

	/** Validates the target transports before the mailbox synchronization lease is acquired. */
	public function prepare(ConnectionSnapshot $snapshot): Result
	{
		$checked = $this->checkedImap($snapshot, $this->validateImapConnection($snapshot->imap()), false);

		return $checked->isSuccess() ? $this->withPreparedSmtp($snapshot, $checked->getData()) : $checked;
	}

	/**
	 * @param array<int, array<string, mixed>> $knownDirectories
	 * @param bool $atAcceptance The acceptance of a snapshot creates the missing folders of the user
	 *        on the target server; any other check only compares {@see inspectKnownTopology()}.
	 * @param string[] $notCreatedSources Paths of the known folders the operation already failed to create.
	 * @param bool $createMissing False for a repeated acceptance of an operation whose generation is
	 *        already written: a missing folder of the user is let through and left to the hand over.
	 * @param TargetFolderCreator|null $creator The creator of the acceptance, with its deadline and
	 *        its observer of created folders; a plain one when none is given.
	 */
	public function prepareKnownTopology(
		ConnectionSnapshot $snapshot,
		array $knownDirectories,
		?\Bitrix\Mail\Imap $client = null,
		bool $atAcceptance = false,
		array $notCreatedSources = [],
		bool $createMissing = true,
		?TargetFolderCreator $creator = null,
	): Result
	{
		$checked = $this->inspectKnownTopology(
			$snapshot,
			$knownDirectories,
			$client,
			$atAcceptance,
			$notCreatedSources,
			$createMissing,
			$creator,
		);

		return $checked->isSuccess() ? $this->withPreparedSmtp($snapshot, $checked->getData()) : $checked;
	}

	/**
	 * The IMAP half of {@see prepareKnownTopology()}: the target folders of the known topology under
	 * {@see RESULT_IMAP_DIRECTORIES} and, at the acceptance, what became of the folders without a pair
	 * under {@see RESULT_FOLDER_OUTCOMES}.
	 *
	 * A folder of the inbox or of a system role missing on the target server fails the check with a
	 * code the retry policy treats as one needing an external fix: always so at the acceptance, where
	 * the mail service knows no code of a missing inbox, and with that code later on.
	 *
	 * @param array<int, array<string, mixed>> $knownDirectories
	 * @param string[] $notCreatedSources
	 */
	public function inspectKnownTopology(
		ConnectionSnapshot $snapshot,
		array $knownDirectories,
		?\Bitrix\Mail\Imap $client = null,
		bool $atAcceptance = false,
		array $notCreatedSources = [],
		bool $createMissing = true,
		?TargetFolderCreator $creator = null,
	): Result
	{
		$validatedImap = (new MailboxConnector())->inspectKnownMigrationConnectionSnapshot(
			$snapshot->imap(),
			$knownDirectories,
			$client,
			$atAcceptance ? ($creator ?? new TargetFolderCreator()) : null,
			$notCreatedSources,
			$createMissing,
		);

		return $this->checkedImap($snapshot, $validatedImap, $atAcceptance);
	}

	private function checkedImap(ConnectionSnapshot $snapshot, Result $validatedImap, bool $atAcceptance): Result
	{
		$imap = $snapshot->imap();
		if (!$validatedImap->isSuccess())
		{
			$result = new Result();
			foreach ($validatedImap->getErrors() as $error)
			{
				$customData = is_array($error->getCustomData()) ? $error->getCustomData() : [];
				$type = $customData['type'] ?? null;
				$missingRole = $customData['missingRole'] ?? null;
				$result->addError(new Error(
					MigrationMetrics::withoutSecretsOf($imap, $error->getMessage()),
					is_string($missingRole) ? self::missingFolderCode($missingRole, $atAcceptance) : MigrationService::ERROR_CONNECTION_UNAVAILABLE,
					array_filter(['type' => $type, 'missingRole' => $missingRole], 'is_string') ?: null,
				));
			}

			return $result;
		}

		$directories = $validatedImap->getData()['directories'] ?? null;
		if (!is_array($directories) || !self::hasRequiredDirectoryRoles($directories))
		{
			return (new Result())->addError(new Error(
				'The new IMAP source has missing or ambiguous system folder roles',
				MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
			));
		}

		$data = [self::RESULT_IMAP_DIRECTORIES => $directories];
		$provisioned = $validatedImap->getData()['provisioned'] ?? null;
		if (is_array($provisioned))
		{
			$data[self::RESULT_FOLDER_OUTCOMES] = $provisioned;
		}

		return (new Result())->setData($data);
	}

	private static function missingFolderCode(string $role, bool $atAcceptance): string
	{
		return !$atAcceptance && $role === 'inbox'
			? MigrationService::ERROR_INCOME_FOLDER_MISSING
			: MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS
		;
	}

	private function withPreparedSmtp(ConnectionSnapshot $snapshot, array $imapData): Result
	{
		$preparedSmtp = $this->smtpSwitcher->prepare($snapshot);
		if ($preparedSmtp->isSuccess())
		{
			$preparedSmtp->setData($preparedSmtp->getData() + $imapData);
		}

		return $preparedSmtp;
	}

	protected function validateImapConnection(array $imap): Result
	{
		return (new MailboxConnector())->inspectMigrationConnectionSnapshot($imap);
	}

	public static function hasRequiredDirectoryRoles(array $directories): bool
	{
		$roles = [
			'sent' => 0,
			'trash' => 0,
			'spam' => 0,
		];

		foreach ($directories as $directory)
		{
			$flags = is_array($directory['flags'] ?? null) ? $directory['flags'] : [];
			$directoryRoles = [];
			foreach ($flags as $flag)
			{
				$normalized = mb_strtolower(ltrim((string)$flag, '\\'));
				if ($normalized === 'sent')
				{
					$directoryRoles['sent'] = true;
				}
				elseif ($normalized === 'trash')
				{
					$directoryRoles['trash'] = true;
				}
				elseif ($normalized === 'junk' || $normalized === 'spam')
				{
					$directoryRoles['spam'] = true;
				}
			}

			foreach (array_keys($directoryRoles) as $role)
			{
				$roles[$role]++;
			}
		}

		return count(array_filter($roles, static fn(int $count): bool => $count === 1)) === count($roles);
	}

	/**
	 * @param array<string, mixed> $preparedSmtp
	 */
	private function applyWithinTransaction(
		int $mailboxId,
		string $operationId,
		int $expectedTargetRevision,
		int $expectedActiveGenerationId,
		array &$heldLock,
		string $targetEmail,
		array $preparedSmtp,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$transactionStarted = false;
		$smtpChange = null;
		$originalLock = $heldLock;

		try
		{
			$connection->startTransaction();
			$transactionStarted = true;

			$coordinator = new SettingsWriteCoordinator();
			$locked = $coordinator->lockWithinTransaction($mailboxId, true, $operationId);
			if (!$locked->isSuccess())
			{
				$this->rollback($connection);
				$transactionStarted = false;

				return $result->addError(new Error(
					'The mailbox is not found',
					self::ERROR_MAILBOX_NOT_FOUND,
				));
			}

			$mailbox = $locked->getData()['mailbox'];
			$currentEmail = $this->emailNormalizer->normalizeMailbox($mailbox);
			if ($currentEmail === null)
			{
				$this->rollback($connection);
				$transactionStarted = false;

				return $result->addError(new Error(
					'A valid current mailbox address is expected',
					self::ERROR_EMAIL_INVALID,
				));
			}

			$coordinator->lockSendersWithinTransaction($mailboxId, $mailbox);

			$activated = $this->generationSwitcher->activateWithinTransaction(
				$mailboxId,
				$operationId,
				$expectedActiveGenerationId,
				$heldLock,
				$expectedTargetRevision,
			);
			if (!$activated->isSuccess())
			{
				$this->rollback($connection);
				$transactionStarted = false;
				$heldLock = $originalLock;

				return $activated;
			}

			if ($currentEmail !== $targetEmail)
			{
				$updated = MailboxTable::update($mailboxId, ['EMAIL' => $targetEmail]);
				if (!$updated->isSuccess())
				{
					$this->rollback($connection);
					$transactionStarted = false;
					$heldLock = $originalLock;

					return $result->addError(new Error(
						implode('; ', $updated->getErrorMessages()),
						self::ERROR_EMAIL_UPDATE_FAILED,
					));
				}

				$registered = $this->addressHistory->registerWithinTransaction($mailboxId, $currentEmail);
				if (!$registered->isSuccess())
				{
					$this->rollback($connection);
					$transactionStarted = false;
					$heldLock = $originalLock;

					return $registered;
				}
			}

			$appliedSmtp = $this->smtpSwitcher->applyWithinTransaction(
				$mailboxId,
				$targetEmail,
				$mailbox,
				$preparedSmtp,
			);
			if (!$appliedSmtp->isSuccess())
			{
				$this->rollback($connection);
				$transactionStarted = false;
				$heldLock = $originalLock;

				return $appliedSmtp;
			}
			$smtpChange = $appliedSmtp->getData()['cacheChange'];

			$connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (\Throwable)
		{
			if ($transactionStarted)
			{
				$this->rollback($connection);
			}
			$heldLock = $originalLock;

			return $result->addError(new Error(
				'The mailbox migration switch failed',
				self::ERROR_SWITCH_FAILED,
			));
		}

		$afterCommit = [fn() => $this->generationSwitcher->afterCommit($mailboxId)];
		if ($smtpChange !== null)
		{
			$afterCommit[] = fn() => $this->smtpSwitcher->afterCommit($smtpChange, $mailboxId);
		}
		$coordinator->afterCommit($afterCommit);

		return $result->setData($activated->getData());
	}

	private function alreadySwitched(int $mailboxId, string $operationId): ?int
	{
		$generation = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'STATUS'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => $operationId,
			],
		])->fetch();
		if (
			$generation === false
			|| (string)$generation['STATUS'] !== MailboxSourceGenerationTable::STATUS_ACTIVE
		)
		{
			return null;
		}

		$generationId = (int)$generation['ID'];

		return $this->generationSwitcher->getActiveGenerationId($mailboxId) === $generationId
			&& $this->generationSwitcher->isProjectionConfirmed($mailboxId, $generationId)
			? $generationId
			: null;
	}

	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}
}
