<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

final class CrmImapGateMarkerRepository
{
	public const PROPERTY_NAME = 'CRM_IMAP_SYNC_V';

	public function lockMailbox(int $mailboxId): ?array
	{
		$connection = Application::getConnection();
		$row = $connection->query(sprintf(
			'SELECT ID, ACTIVE, SERVER_TYPE, OPTIONS FROM %s WHERE ID = %u%s',
			SqlRowLock::table(MailboxTable::getTableName(), $connection->getType()),
			$mailboxId,
			SqlRowLock::suffix($connection->getType()),
		))->fetch();
		if (!$row)
		{
			return null;
		}

		$storedOptions = $row['OPTIONS'] ?? null;
		if ($storedOptions === null || $storedOptions === '')
		{
			$row['OPTIONS'] = [];

			return $row;
		}

		$options = unserialize((string)$storedOptions, ['allowed_classes' => false]);
		if (!is_array($options))
		{
			throw new \UnexpectedValueException('The mailbox options are unreadable');
		}
		$row['OPTIONS'] = $options;

		return $row;
	}

	public function read(int $mailboxId, bool $forUpdate = false): ?string
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$row = $connection->query(sprintf(
			"SELECT VALUE FROM %s WHERE MAILBOX_ID = %u AND ENTITY_TYPE = '%s'"
				. " AND ENTITY_ID = '%s' AND PROPERTY_NAME = '%s'%s",
			SqlRowLock::table(
				MailEntityOptionsTable::getTableName(),
				$forUpdate ? $connection->getType() : '',
			),
			$mailboxId,
			$sqlHelper->forSql(MailEntityOptionsTable::MAILBOX_TYPE_NAME),
			$sqlHelper->forSql((string)$mailboxId),
			$sqlHelper->forSql(self::PROPERTY_NAME),
			$forUpdate ? SqlRowLock::suffix($connection->getType()) : '',
		))->fetch();

		return $row ? (string)$row['VALUE'] : null;
	}

	public function write(int $mailboxId, string $value): bool
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$fields = [
			'MAILBOX_ID' => $mailboxId,
			'ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'ENTITY_ID' => (string)$mailboxId,
			'PROPERTY_NAME' => self::PROPERTY_NAME,
			'VALUE' => $value,
		];
		[$sql] = $sqlHelper->prepareMerge(
			MailEntityOptionsTable::getTableName(),
			['MAILBOX_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'PROPERTY_NAME'],
			$fields,
			['VALUE' => $value],
		);
		$connection->queryExecute($sql);

		return $this->read($mailboxId) === $value;
	}

	public function invalidate(int $mailboxId): Result
	{
		Application::getConnection()->queryExecute(sprintf(
			"DELETE FROM %s WHERE MAILBOX_ID = %u AND ENTITY_TYPE = '%s'"
				. " AND ENTITY_ID = '%s' AND PROPERTY_NAME = '%s'",
			MailEntityOptionsTable::getTableName(),
			$mailboxId,
			MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			$mailboxId,
			self::PROPERTY_NAME,
		));

		return new Result();
	}
}
