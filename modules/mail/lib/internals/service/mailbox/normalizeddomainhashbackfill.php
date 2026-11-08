<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Service\Mailbox;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Throwable;

final class NormalizedDomainHashBackfill
{
	private const BATCH_SIZE = 300;

	public function __construct(private readonly Connection $connection)
	{
	}

	public static function runAgent(): string
	{
		(new self(Application::getConnection()))->run();

		return '';
	}

	public function run(): void
	{
		$this->connection->clearCaches(MailboxTable::getTableName());
		$sqlHelper = $this->connection->getSqlHelper();
		$table = $sqlHelper->quote(MailboxTable::getTableName());
		$afterId = 0;
		$normalizer = new EmailNormalizer();
		while (true)
		{
			$this->connection->startTransaction();
			try
			{
				$rows = $this->connection->query(sprintf(
					'SELECT ID, EMAIL, NAME, LOGIN, EMAIL_DOMAIN_HASH FROM %s'
					. ' WHERE ID > %u ORDER BY ID ASC LIMIT %u FOR UPDATE',
					$table, $afterId, self::BATCH_SIZE,
				))->fetchAll();
				foreach ($rows as $row)
				{
					$domainHash = $normalizer->domainHash($normalizer->normalizeMailbox($row));
					if ($row['EMAIL_DOMAIN_HASH'] !== $domainHash)
					{
						[$update, $binds] = $sqlHelper->prepareUpdate(
							MailboxTable::getTableName(), ['EMAIL_DOMAIN_HASH' => $domainHash],
						);
						$this->connection->queryExecute(sprintf(
							'UPDATE %s SET %s WHERE ID = %u', $table, $update, (int)$row['ID'],
						), $binds);
					}
					$afterId = (int)$row['ID'];
				}
				$this->connection->commitTransaction();
			}
			catch (Throwable $failure)
			{
				try
				{
					$this->connection->rollbackTransaction();
				}
				catch (TransactionException $exception)
				{
					if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
					{
						throw $exception;
					}
				}
				throw $failure;
			}
			if ($rows === [])
			{
				break;
			}
		}
	}
}
