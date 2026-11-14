<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking\Ad;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Type\DateTime;

final class AuthorizationTicketRepository
{
	private readonly Connection $connection;

	public function __construct(?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function issue(int $ownerId, int $sourceId, \DateTimeImmutable $now): string
	{
		if ($ownerId < 1 || $sourceId < 1)
		{
			throw new \DomainException('Invalid authorization ticket');
		}
		$this->removeExpired($now);
		$ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$this->connection->add('b_crm_tracking_ad_auth_ticket', [
			'HASH' => hash('sha256', $ticket),
			'OWNER_ID' => $ownerId,
			'SOURCE_ID' => $sourceId,
			'EXPIRES_AT' => DateTime::createFromTimestamp($now->getTimestamp() + 300),
		], null);

		return $ticket;
	}

	public function source(string $hash, int $ownerId, \DateTimeImmutable $now): int
	{
		return (int)$this->read($hash, $ownerId, $now, false)['SOURCE_ID'];
	}

	public function consume(
		string $hash, int $ownerId, string $sessionHash, \DateTimeImmutable $now, ?callable $beforeConsume = null,
	): int
	{
		if (!preg_match('/^[a-f0-9]{64}$/D', $sessionHash))
		{
			throw new \DomainException('Invalid authorization ticket');
		}
		$this->connection->startTransaction();
		try
		{
			$row = $this->read($hash, $ownerId, $now, true);
			if ($beforeConsume !== null)
			{
				$beforeConsume((int)$row['SOURCE_ID']);
			}
			$helper = $this->connection->getSqlHelper();
			$this->connection->queryExecute('UPDATE b_crm_tracking_ad_auth_ticket SET CONSUMED_AT = '
				. $helper->convertToDbDateTime(DateTime::createFromTimestamp($now->getTimestamp()))
				. ", SESSION_HASH = '" . $sessionHash . "' WHERE HASH = '" . $hash . "' AND CONSUMED_AT IS NULL");
			if ($this->connection->getAffectedRowsCount() !== 1)
			{
				throw new \DomainException('Invalid authorization ticket');
			}
			$this->connection->commitTransaction();

			return (int)$row['SOURCE_ID'];
		}
		catch (\Throwable $exception)
		{
			try
			{
				$this->connection->rollbackTransaction();
			}
			catch (\Bitrix\Main\DB\TransactionException $rollbackException)
			{
				// MySQL completes the savepoint rollback before reporting this exception.
				if ($rollbackException->getMessage() !== 'Nested rollbacks are unsupported.')
				{
					throw $rollbackException;
				}
			}
			throw $exception;
		}
	}

	private function removeExpired(\DateTimeImmutable $now): void
	{
		$helper = $this->connection->getSqlHelper();
		$expiry = $helper->convertToDbDateTime(DateTime::createFromTimestamp($now->getTimestamp()));
		$rows = $this->connection->query($helper->getTopSql(
			'SELECT HASH FROM b_crm_tracking_ad_auth_ticket WHERE EXPIRES_AT <= ' . $expiry
				. ' ORDER BY EXPIRES_AT', 100,
		));
		$hashes = [];
		while ($row = $rows->fetch())
		{
			$hashes[] = "'" . $helper->forSql($row['HASH']) . "'";
		}
		if ($hashes !== [])
		{
			$this->connection->queryExecute('DELETE FROM b_crm_tracking_ad_auth_ticket WHERE HASH IN ('
				. implode(',', $hashes) . ') AND EXPIRES_AT <= ' . $expiry);
		}
	}

	private function read(string $hash, int $ownerId, \DateTimeImmutable $now, bool $lock): array
	{
		if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || $ownerId < 1)
		{
			throw new \DomainException('Invalid authorization ticket');
		}
		$expiry = $this->connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp($now->getTimestamp()),
		);
		$row = $this->connection->query('SELECT SOURCE_ID FROM b_crm_tracking_ad_auth_ticket'
			. " WHERE HASH = '" . $hash . "' AND OWNER_ID = " . $ownerId
			. ' AND CONSUMED_AT IS NULL AND EXPIRES_AT > ' . $expiry . ($lock ? ' FOR UPDATE' : ''))->fetch();
		if (!$row)
		{
			throw new \DomainException('Invalid authorization ticket');
		}

		return $row;
	}
}
