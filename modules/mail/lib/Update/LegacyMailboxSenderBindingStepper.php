<?php

declare(strict_types=1);

namespace Bitrix\Mail\Update;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\ORM\Query\Query;
use Psr\Log\LogLevel;

/**
 * Binds to every active mailbox the SMTP record its settings show, once.
 *
 * Records written before they carried the mailbox reference are found by the settings through
 * the owner and the address, see MailboxConnector::getMailboxSender, while the core looks a mailbox
 * record up by the reference only. The walk asks the settings lookup itself and has no rules of its own.
 * A record shown to several mailboxes of the owner stays with the first of them, the others get
 * a bound copy, so no mailbox loses what its settings show.
 *
 * Nothing is deleted. A bound record is found by the reference, so a repeated walk changes nothing.
 * Once the walk is over with no mailbox skipped, the lookup by the owner and the address is switched off.
 * A walk that skipped mailboxes only for a failed write or a record changed meanwhile is repeated
 * an hour later, a few times at most; after that the lookup stays on.
 * The summary of every walk goes to the event log by identifiers and counters, without addresses.
 * A portion reads the mailboxes of its owners and their SMTP records at once, the settings lookup
 * is then asked over the rows read, so the number of reads does not grow with the number of records.
 */
final class LegacyMailboxSenderBindingStepper extends Main\Update\Stepper
{
	public const AUDIT_TYPE = 'MAIL_LEGACY_SENDER_BINDING';

	public const SKIP_CHANGED = 'changed';
	public const SKIP_FOREIGN_PARENT = 'foreignParent';
	public const SKIP_WRITE_FAILED = 'writeFailed';

	public const RETRY_DELAY = 3600;
	public const RETRY_LIMIT = 3;

	private const TRANSIENT_SKIPS = [self::SKIP_WRITE_FAILED, self::SKIP_CHANGED];
	private const LIMIT = 50;
	private const LOGGED_IDS_PER_REASON = 100;
	private const LOGGED_CHANGES = 500;

	protected static $moduleId = 'mail';

	public function execute(array &$option): bool
	{
		if (isset($option['retryAt']))
		{
			$wait = (int)$option['retryAt'] - time();
			if ($wait > 0)
			{
				$this->delayNextRun($wait);

				return self::CONTINUE_EXECUTION;
			}

			unset($option['retryAt']);
		}

		$option['lastId'] = (int)($option['lastId'] ?? 0);
		$option['startId'] = (int)($option['startId'] ?? $option['lastId']);
		$option['retries'] = (int)($option['retries'] ?? 0);

		// the base class writes steps and count into the option between runs, so they do not mark a started walk
		if (!isset($option['skipped']))
		{
			$option['maxId'] = (int)($option['maxId'] ?? $this->getMaxMailboxId());
			$option['count'] = $this->countActiveMailboxes((int)$option['lastId'], (int)$option['maxId']);
			$option['steps'] = 0;
			$option['bound'] = 0;
			$option['copied'] = 0;
			$option['public'] = 0;
			$option['changes'] = [];
			$option['skipped'] = [];
		}

		$mailboxes = $this->getActiveMailboxes((int)$option['lastId'], (int)$option['maxId']);
		$portion = $this->readPortion($mailboxes);
		foreach ($mailboxes as $mailbox)
		{
			$option['lastId'] = (int)$mailbox['ID'];
			$option['steps'] = (int)$option['steps'] + 1;

			$this->processMailbox($mailbox, $option, $portion);
		}

		if (count($mailboxes) < self::LIMIT || (int)$option['lastId'] >= (int)$option['maxId'])
		{
			return $this->finishWalk($option);
		}

		return self::CONTINUE_EXECUTION;
	}

	private function finishWalk(array &$option): bool
	{
		// every skipped mailbox still shows an unbound record, without the lookup it would lose it
		if (empty($option['skipped']))
		{
			$this->logSummary($option, 'the lookup by address is switched off');
			Option::set('main', MailboxConnector::LEGACY_SENDER_LOOKUP_OPTION, 'N');

			return self::FINISH_EXECUTION;
		}

		if (!$this->isWorthRetrying($option))
		{
			$this->logSummary($option, 'the lookup by address is kept');

			return self::FINISH_EXECUTION;
		}

		$this->logSummary($option, 'the walk is repeated in ' . self::RETRY_DELAY . ' s');
		$option = [
			'lastId' => (int)$option['startId'],
			'startId' => (int)$option['startId'],
			'maxId' => (int)$option['maxId'],
			'retries' => (int)$option['retries'] + 1,
			'retryAt' => time() + self::RETRY_DELAY,
			'previous' => [
				'bound' => (int)$option['bound'],
				'copied' => (int)$option['copied'],
				'skipped' => $option['skipped'],
			],
		];
		$this->delayNextRun(self::RETRY_DELAY);

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * A record of another module stays shown on every walk, a repeat helps only a failed write or a changed record.
	 */
	private function isWorthRetrying(array $option): bool
	{
		return (int)$option['retries'] < self::RETRY_LIMIT
			&& array_diff(array_keys($option['skipped']), self::TRANSIENT_SKIPS) === []
		;
	}

	private function delayNextRun(int $seconds): void
	{
		global $pPERIOD;

		// read by CAgent after the run, see Stepper::execAgent
		$pPERIOD = $seconds;
	}

	private function processMailbox(array $mailbox, array &$option, array &$portion): void
	{
		$mailboxId = (int)$mailbox['ID'];
		$ownerId = (int)$mailbox['USER_ID'];

		$sender = $this->getShownSender($mailbox, $portion);
		if ($sender === null || $this->isBoundTo($sender, $mailboxId))
		{
			return;
		}

		if (!$this->isWithoutMailboxReference($sender))
		{
			$this->rememberSkipped($option, self::SKIP_FOREIGN_PARENT, $mailboxId);

			return;
		}

		$senderId = (int)$sender['ID'];
		// every mailbox is decided before anything is written: a bound record is no longer shown to the others
		$mailboxIds = $this->findMailboxesShowing($senderId, (string)$sender['EMAIL'], $ownerId, $portion);
		$mailboxIds[] = $mailboxId;
		$mailboxIds = array_values(array_unique($mailboxIds));
		sort($mailboxIds);

		$record = $this->readUnchanged($sender, $portion);
		if ($record === null)
		{
			$this->rememberSkipped($option, self::SKIP_CHANGED, $mailboxId);

			return;
		}

		// copies go first: an interrupted walk leaves the original unbound, and the next walk finds it again
		foreach (array_slice($mailboxIds, 1) as $copyMailboxId)
		{
			$copyId = $this->addCopy($record, $copyMailboxId);
			if ($copyId <= 0)
			{
				$this->rememberSkipped($option, self::SKIP_WRITE_FAILED, $copyMailboxId);

				return;
			}

			$portion['senders'][$copyId] = ['ID' => $copyId, 'PARENT_MODULE_ID' => 'mail', 'PARENT_ID' => $copyMailboxId] + $record;
			$option['copied'] = (int)$option['copied'] + 1;
			$this->rememberChange($option, $copyMailboxId, $copyId, $senderId);
		}

		if (!$this->bindRecord($senderId, $mailboxIds[0]))
		{
			$this->rememberSkipped($option, self::SKIP_WRITE_FAILED, $mailboxIds[0]);

			return;
		}

		$portion['senders'][$senderId] = ['PARENT_MODULE_ID' => 'mail', 'PARENT_ID' => $mailboxIds[0]] + $record;
		$option['bound'] = (int)$option['bound'] + 1;
		if (!empty($record['IS_PUBLIC']))
		{
			$option['public'] = (int)$option['public'] + 1;
		}
		$this->rememberChange($option, $mailboxIds[0], $senderId);
	}

	/**
	 * @return int[] active mailboxes of the owner whose settings show the record
	 */
	private function findMailboxesShowing(int $senderId, string $senderEmail, int $ownerId, array $portion): array
	{
		$senderEmail = $this->normalizeEmail($senderEmail);
		$mailboxIds = [];
		foreach ($portion['mailboxes'][$ownerId] ?? [] as $mailbox)
		{
			if ($this->normalizeEmail((string)$mailbox['EMAIL']) !== $senderEmail)
			{
				continue;
			}

			$shown = $this->getShownSender($mailbox, $portion);
			if ((int)($shown['ID'] ?? 0) === $senderId)
			{
				$mailboxIds[] = (int)$mailbox['ID'];
			}
		}

		return $mailboxIds;
	}

	/**
	 * The record the settings of the mailbox show, decided by the settings lookup over the rows of the portion.
	 */
	private function getShownSender(array $mailbox, array $portion): ?array
	{
		$mailboxId = (int)$mailbox['ID'];
		$ownerId = (int)$mailbox['USER_ID'];
		$email = (string)$mailbox['EMAIL'];
		$normalizedEmail = $this->normalizeEmail($email);

		$rows = [];
		foreach ($portion['senders'] as $sender)
		{
			$isBound = $sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] === $mailboxId;
			// the settings look the address up normalized, the lookup over given rows compares it as passed
			$hasAddress = (int)$sender['USER_ID'] === $ownerId
				&& $this->normalizeEmail((string)$sender['EMAIL']) === $normalizedEmail
			;
			if ($hasAddress)
			{
				$sender['EMAIL'] = $email;
			}
			if ($isBound || $hasAddress)
			{
				$rows[] = $sender;
			}
		}

		$shown = MailboxConnector::getMailboxSender($mailboxId, $email, $ownerId, readOnly: true, lockedSenders: $rows);

		return $shown === null ? null : $portion['senders'][(int)$shown['ID']];
	}

	/**
	 * @return array{mailboxes: array<int, array[]>, senders: array<int, array>} active mailboxes by owner
	 * and SMTP records by identifier: the records bound to those mailboxes and those of the owners at the addresses
	 */
	private function readPortion(array $mailboxes): array
	{
		$portion = ['mailboxes' => [], 'senders' => []];
		if ($mailboxes === [])
		{
			return $portion;
		}

		// both lists are bounded by the size of the portion
		$ownerIds = array_values(array_unique(array_map(static fn(array $mailbox): int => (int)$mailbox['USER_ID'], $mailboxes)));
		$emails = array_values(array_unique(array_map(fn(array $mailbox): string => $this->normalizeEmail((string)$mailbox['EMAIL']), $mailboxes)));

		$ownerMailboxes = MailboxTable::query()
			->setSelect(['ID', 'USER_ID', 'EMAIL'])
			->whereIn('USER_ID', $ownerIds)
			->where('ACTIVE', MailboxStatus::Active->value)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;
		foreach ($ownerMailboxes as $mailbox)
		{
			$portion['mailboxes'][(int)$mailbox['USER_ID']][] = $mailbox;
		}

		$senders = SenderTable::query()
			->setSelect(['*'])
			->where(
				Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->where('PARENT_MODULE_ID', 'mail')
							->whereIn(
								'PARENT_ID',
								MailboxTable::query()
									->setSelect(['ID'])
									->whereIn('USER_ID', $ownerIds)
									->where('ACTIVE', MailboxStatus::Active->value),
							),
					)
					->where(
						Query::filter()
							->whereIn('USER_ID', $ownerIds)
							->whereIn('EMAIL', $emails),
					),
			)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;
		foreach ($senders as $sender)
		{
			$portion['senders'][(int)$sender['ID']] = $sender;
		}

		return $portion;
	}

	/**
	 * Reads the record again right before the write, so that a record changed meanwhile is left alone.
	 */
	private function readUnchanged(array $sender, array &$portion): ?array
	{
		$record = SenderTable::getById((int)$sender['ID'])->fetch();
		// the other mailboxes of the portion are decided on the record as it is now
		if ($record)
		{
			$portion['senders'][(int)$sender['ID']] = $record;
		}
		else
		{
			unset($portion['senders'][(int)$sender['ID']]);
		}

		if (
			!$record
			|| $record['PARENT_MODULE_ID'] !== $sender['PARENT_MODULE_ID']
			|| (int)$record['PARENT_ID'] !== (int)$sender['PARENT_ID']
			|| (int)$record['USER_ID'] !== (int)$sender['USER_ID']
			|| $record['EMAIL'] !== $sender['EMAIL']
		)
		{
			return null;
		}

		return $record;
	}

	/**
	 * Written through the entity: it encrypts the password of the copy and drops the cached resolution.
	 */
	private function addCopy(array $record, int $mailboxId): int
	{
		$result = SenderTable::add([
			'EMAIL' => $record['EMAIL'],
			'NAME' => $record['NAME'],
			'USER_ID' => (int)$record['USER_ID'],
			'IS_CONFIRMED' => (bool)$record['IS_CONFIRMED'],
			'IS_PUBLIC' => (bool)$record['IS_PUBLIC'],
			'OPTIONS' => $record['OPTIONS'],
			'PARENT_MODULE_ID' => 'mail',
			'PARENT_ID' => $mailboxId,
		]);

		return $result->isSuccess() ? (int)$result->getId() : 0;
	}

	private function bindRecord(int $senderId, int $mailboxId): bool
	{
		return SenderTable::update($senderId, [
			'PARENT_MODULE_ID' => 'mail',
			'PARENT_ID' => $mailboxId,
		])->isSuccess();
	}

	private function isBoundTo(array $sender, int $mailboxId): bool
	{
		return $sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] === $mailboxId;
	}

	private function isWithoutMailboxReference(array $sender): bool
	{
		return $sender['PARENT_MODULE_ID'] === 'main'
			|| ($sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] <= 0)
		;
	}

	private function getActiveMailboxes(int $lastId, int $maxId): array
	{
		return MailboxTable::query()
			->setSelect(['ID', 'USER_ID', 'EMAIL'])
			->where('ID', '>', $lastId)
			->where('ID', '<=', $maxId)
			->where('ACTIVE', MailboxStatus::Active->value)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::LIMIT)
			->fetchAll()
		;
	}

	private function countActiveMailboxes(int $lastId, int $maxId): int
	{
		return (int)MailboxTable::query()
			->where('ID', '>', $lastId)
			->where('ID', '<=', $maxId)
			->where('ACTIVE', MailboxStatus::Active->value)
			->queryCountTotal()
		;
	}

	private function getMaxMailboxId(): int
	{
		$row = MailboxTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return (int)($row['ID'] ?? 0);
	}

	private function normalizeEmail(string $email): string
	{
		return mb_strtolower(trim($email));
	}

	private function rememberChange(array &$option, int $mailboxId, int $senderId, ?int $sourceSenderId = null): void
	{
		if (count($option['changes']) >= self::LOGGED_CHANGES)
		{
			return;
		}

		$option['changes'][] = $sourceSenderId === null
			? [$mailboxId, $senderId]
			: [$mailboxId, $senderId, $sourceSenderId]
		;
	}

	private function rememberSkipped(array &$option, string $reason, int $mailboxId): void
	{
		$skipped = $option['skipped'][$reason] ?? ['count' => 0, 'ids' => []];
		$skipped['count']++;
		if (count($skipped['ids']) < self::LOGGED_IDS_PER_REASON)
		{
			$skipped['ids'][] = $mailboxId;
		}

		$option['skipped'][$reason] = $skipped;
	}

	private function logSummary(array $option, string $outcome): void
	{
		if (
			(int)$option['bound'] === 0
			&& (int)$option['copied'] === 0
			&& empty($option['skipped'])
			&& (int)$option['retries'] === 0
		)
		{
			return;
		}

		try
		{
			(new Main\Diag\EventLogger('mail', self::AUDIT_TYPE))->log(
				empty($option['skipped']) ? LogLevel::INFO : LogLevel::WARNING,
				'Walk {attempt} of {attempts}: {outcome}. '
				. 'Mailbox SMTP records bound: {bound}, public among them: {public}; copies: {copied}; '
				. 'changes [mailbox, record, source record]: {changes}; mailboxes left as they are: {skipped}',
				[
					'attempt' => (int)$option['retries'] + 1,
					'attempts' => self::RETRY_LIMIT + 1,
					'outcome' => $outcome,
					'bound' => (int)$option['bound'],
					'public' => (int)$option['public'],
					'copied' => (int)$option['copied'],
					'changes' => Main\Web\Json::encode($option['changes']),
					'skipped' => Main\Web\Json::encode($option['skipped']),
				],
			);
		}
		catch (\Throwable)
		{
		}
	}
}
