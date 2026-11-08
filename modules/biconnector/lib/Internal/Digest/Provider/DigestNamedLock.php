<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\Main\DB\Connection;

/**
 * Named database lock taken without waiting. A lock that cannot be taken, for whatever reason, reads as busy:
 * the callers guard a state change and give up rather than run it twice.
 * Left open for extension so tests can replace it with a stub.
 */
class DigestNamedLock
{
	public function __construct(private readonly Connection $connection)
	{
	}

	public function acquire(string $name): bool
	{
		try
		{
			return $this->connection->lock($name, 0);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function release(string $name): void
	{
		try
		{
			$this->connection->unlock($name);
		}
		catch (\Throwable)
		{
			// The connection releases the named lock even if an explicit unlock fails.
		}
	}
}
