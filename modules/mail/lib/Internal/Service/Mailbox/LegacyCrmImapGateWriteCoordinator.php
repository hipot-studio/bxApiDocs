<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Mail\Helper\Enum\CrmFlag;
use Bitrix\Mail\Helper\Mailbox\CrmImapFilter;
use Bitrix\Mail\Internal\Repository\SqlRowLock;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailFilterTable;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

final class LegacyCrmImapGateWriteCoordinator
{
	public const ERROR_WRITE_FAILED = 'MAIL_CRM_IMAP_GATE_LEGACY_WRITE_FAILED';

	private const CRM_ACTION_TYPE = 'crm_imap';
	private const MAX_OWNER_RETRIES = 3;

	private readonly \Closure $invalidate;

	public function __construct(?\Closure $invalidate = null)
	{
		$this->invalidate = $invalidate ?? static fn (array $mailboxIds): Result => (new CrmImapGateReconciler())->invalidateManyWithinTransaction($mailboxIds);
	}

	public function updateMailbox(int $mailboxId, array $fields, \Closure $write): bool
	{
		return $this->transaction(function () use ($mailboxId, $fields, $write): bool {
			$mailbox = $this->lockMailbox($mailboxId);
			if ($mailbox === null)
			{
				return false;
			}

			$desiredChanged = array_key_exists('OPTIONS', $fields)
				&& $this->hasCrmFlag($mailbox['OPTIONS']) !== $this->hasCrmFlag((array)$fields['OPTIONS']);
			if ($desiredChanged)
			{
				CrmImapFilter::fetchFilters($mailboxId, true);
			}

			if (!$write($mailbox))
			{
				return false;
			}

			return !$desiredChanged || $this->invalidate([$mailboxId]);
		});
	}

	public function addFilter(int $mailboxId, string $actionType, \Closure $write): int|false
	{
		return $this->transaction(function () use ($mailboxId, $actionType, $write): int|false {
			if (!$this->lockMailboxes([$mailboxId]))
			{
				return false;
			}
			CrmImapFilter::fetchFilters($mailboxId, true);

			$filterId = $write();
			if (!$filterId)
			{
				return false;
			}

			return $actionType !== self::CRM_ACTION_TYPE || $this->invalidate([$mailboxId])
				? (int)$filterId
				: false;
		});
	}

	public function updateFilter(int $filterId, array $fields, \Closure $write): bool
	{
		for ($attempt = 0; $attempt < self::MAX_OWNER_RETRIES; $attempt++)
		{
			$observed = $this->readFilter($filterId);
			if ($observed === null)
			{
				return (bool)$write();
			}

			$retry = false;
			$result = $this->transaction(function () use ($filterId, $fields, $write, $observed, &$retry): bool {
				$mailboxIds = [(int)$observed['MAILBOX_ID']];
				if (array_key_exists('MAILBOX_ID', $fields))
				{
					$mailboxIds[] = (int)$fields['MAILBOX_ID'];
				}
				if (!$this->lockMailboxes($mailboxIds))
				{
					return false;
				}
				$this->lockCrmFilters($mailboxIds);

				$filter = $this->lockFilter($filterId);
				if ($filter === null)
				{
					return (bool)$write();
				}
				if ((int)$filter['MAILBOX_ID'] !== (int)$observed['MAILBOX_ID'])
				{
					$retry = true;

					return false;
				}

				if (!$write())
				{
					return false;
				}

				$newMailboxId = array_key_exists('MAILBOX_ID', $fields)
					? (int)$fields['MAILBOX_ID']
					: (int)$filter['MAILBOX_ID'];
				$newActionType = array_key_exists('ACTION_TYPE', $fields)
					? (string)$fields['ACTION_TYPE']
					: (string)$filter['ACTION_TYPE'];
				$affected = [];
				if ((string)$filter['ACTION_TYPE'] === self::CRM_ACTION_TYPE)
				{
					$affected[] = (int)$filter['MAILBOX_ID'];
				}
				if ($newActionType === self::CRM_ACTION_TYPE)
				{
					$affected[] = $newMailboxId;
				}

				return $this->invalidate($affected);
			});

			if (!$retry)
			{
				return $result;
			}
		}

		return $this->fail();
	}

	public function deleteFilter(int $filterId, \Closure $write): mixed
	{
		for ($attempt = 0; $attempt < self::MAX_OWNER_RETRIES; $attempt++)
		{
			$observed = $this->readFilter($filterId);
			if ($observed === null)
			{
				return $write();
			}

			$retry = false;
			$result = $this->transaction(function () use ($filterId, $write, $observed, &$retry): mixed {
				$mailboxId = (int)$observed['MAILBOX_ID'];
				if (!$this->lockMailboxes([$mailboxId]))
				{
					return false;
				}
				CrmImapFilter::fetchFilters($mailboxId, true);

				$filter = $this->lockFilter($filterId);
				if ($filter === null)
				{
					return $write();
				}
				if ((int)$filter['MAILBOX_ID'] !== $mailboxId)
				{
					$retry = true;

					return false;
				}

				$result = $write();
				if ($result === false)
				{
					return false;
				}

				return (string)$filter['ACTION_TYPE'] !== self::CRM_ACTION_TYPE
					|| $this->invalidate([$mailboxId])
					? $result
					: false;
			});

			if (!$retry)
			{
				return $result;
			}
		}

		return $this->fail();
	}

	private function transaction(\Closure $operation): mixed
	{
		$connection = Application::getConnection();
		$transaction = new LegacyCrmImapGateTransaction($connection);
		$transaction->begin();
		try
		{
			$result = $operation();
			if ($result === false)
			{
				$this->rollback($transaction);

				return false;
			}
			$transaction->commit();

			return $result;
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$this->rollback($transaction);

			return $this->fail();
		}
	}

	private function rollback(LegacyCrmImapGateTransaction $transaction): void
	{
		try
		{
			$transaction->rollback();
		}
		catch (\Bitrix\Main\DB\TransactionException)
		{
			// MySQL reports unsupported nested rollback after it has rolled back to the savepoint.
		}
	}

	private function lockMailbox(int $mailboxId): ?array
	{
		$connection = Application::getConnection();
		$row = $connection->query(sprintf(
			'SELECT * FROM %s WHERE ID = %u%s',
			SqlRowLock::table(MailboxTable::getTableName(), $connection->getType()),
			$mailboxId,
			SqlRowLock::suffix($connection->getType()),
		))->fetch();
		if (!$row)
		{
			return null;
		}
		$row['OPTIONS'] = $this->decodeOptions($row['OPTIONS'] ?? null);

		return $row;
	}

	private function lockMailboxes(array $mailboxIds): bool
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		sort($mailboxIds, SORT_NUMERIC);
		foreach ($mailboxIds as $mailboxId)
		{
			if ($this->lockMailbox($mailboxId) === null)
			{
				return false;
			}
		}

		return true;
	}

	private function lockCrmFilters(array $mailboxIds): void
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		sort($mailboxIds, SORT_NUMERIC);
		foreach ($mailboxIds as $mailboxId)
		{
			CrmImapFilter::fetchFilters($mailboxId, true);
		}
	}

	private function readFilter(int $filterId): ?array
	{
		return MailFilterTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'ACTION_TYPE'],
			'filter' => ['=ID' => $filterId],
			'limit' => 1,
		])->fetch() ?: null;
	}

	private function lockFilter(int $filterId): ?array
	{
		$connection = Application::getConnection();
		return $connection->query(sprintf(
			'SELECT ID, MAILBOX_ID, ACTION_TYPE FROM %s WHERE ID = %u%s',
			SqlRowLock::table(MailFilterTable::getTableName(), $connection->getType()),
			$filterId,
			SqlRowLock::suffix($connection->getType()),
		))->fetch() ?: null;
	}

	private function invalidate(array $mailboxIds): bool
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		if ($mailboxIds === [])
		{
			return true;
		}

		return ($this->invalidate)($mailboxIds)->isSuccess() || $this->fail();
	}

	private function decodeOptions(mixed $stored): array
	{
		if ($stored === null || $stored === '')
		{
			return [];
		}
		$options = unserialize((string)$stored, ['allowed_classes' => false]);

		return is_array($options) ? $options : [];
	}

	private function hasCrmFlag(array $options): bool
	{
		return in_array(CrmFlag::Connect->value, (array)($options['flags'] ?? []), true);
	}

	private function fail(): false
	{
		\CMailError::SetError(self::ERROR_WRITE_FAILED, 'The CRM IMAP gate state was not invalidated');

		return false;
	}
}
