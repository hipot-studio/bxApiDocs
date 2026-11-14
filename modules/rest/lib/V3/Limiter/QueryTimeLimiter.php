<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter;

use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Rest\V3\Exception\Limiter\QueryTimeLimitException;

class QueryTimeLimiter implements LimiterInterface
{
	private const MYSQL_CONNECTION_TYPE = 'mysql';
	private const MYSQL_VARIABLE = 'max_execution_time';
	private const MYSQL_SECOND = 1000;
	private const MYSQL_MIN_VERSION = '5.7.8';
	private const MYSQL_ERROR_CODE = 3024;
	private const MARIADB_MARKER = 'MariaDB';
	private const MARIADB_VARIABLE = 'max_statement_time';
	private const MARIADB_SECOND = 1;
	private const MARIADB_MIN_VERSION = '10.1.1';
	private const MARIADB_ERROR_CODE = 1969;
	private const SORT_ABORTED_ERROR_CODE = 1028;
	private const SORT_ABORTED_TIME_LIMIT_PATTERN = '/(maximum statement execution time|max_statement_time) exceeded/';

	private int $depth = 0;
	private ?string $release = null;
	private bool $isShutdownRegistered = false;

	public function __construct(
		private readonly ConnectionPool $pool,
		private readonly bool $isActive,
		private readonly int $seconds,
	)
	{
	}

	public function check(): void
	{
	}

	public function start(): void
	{
		$this->depth++;
		if ($this->depth > 1 || !$this->isActive())
		{
			return;
		}

		try
		{
			$variable = self::resolveVariable($this->pool->getConnection());
			if ($variable === null)
			{
				return;
			}

			[$name, $second] = $variable;
			$this->execute('SET SESSION ' . $name . ' = ' . $this->seconds * $second);
			$this->release = 'SET SESSION ' . $name . ' = DEFAULT';
		}
		catch (\Throwable)
		{
		}

		if ($this->release !== null && !$this->isShutdownRegistered)
		{
			$this->isShutdownRegistered = true;
			register_shutdown_function($this->releaseOnShutdown(...));
		}
	}

	public function catchException(\Throwable $e): \Throwable
	{
		return $this->isExceeded($e) ? new QueryTimeLimitException($this->getRetryAfter(), $e) : $e;
	}

	public function finish(): void
	{
		if ($this->depth === 0)
		{
			return;
		}

		$this->depth--;
		if ($this->depth > 0 || $this->release === null)
		{
			return;
		}

		$release = $this->release;
		$this->release = null;

		try
		{
			$this->execute($release);
		}
		catch (\Throwable)
		{
		}
	}

	public function getDebugInfo(): array
	{
		return [];
	}

	private function isExceeded(\Throwable $e): bool
	{
		if (!$this->isActive() || !($e instanceof SqlQueryException))
		{
			return false;
		}

		$databaseMessage = (string)$e->getDatabaseMessage();

		return str_starts_with($databaseMessage, '(' . self::MYSQL_ERROR_CODE . ')')
			|| str_starts_with($databaseMessage, '(' . self::MARIADB_ERROR_CODE . ')')
			|| str_starts_with($databaseMessage, '(' . self::SORT_ABORTED_ERROR_CODE . ')')
				&& preg_match(self::SORT_ABORTED_TIME_LIMIT_PATTERN, $databaseMessage)
		;
	}

	private function isActive(): bool
	{
		return $this->isActive && $this->seconds > 0;
	}

	private function getRetryAfter(): int
	{
		return $this->seconds;
	}

	private function execute(string $sql): void
	{
		$this->pool->useMasterOnly(true);
		try
		{
			$this->pool->getConnection()->queryExecute($sql);
		}
		finally
		{
			$this->pool->useMasterOnly(false);
		}
	}

	public static function isSupported(Connection $connection): bool
	{
		return self::resolveVariable($connection) !== null;
	}

	private function releaseOnShutdown(): void
	{
		$this->depth = min($this->depth, 1);
		$this->finish();
	}

	private static function resolveVariable(object $connection): ?array
	{
		if ($connection->getType() !== self::MYSQL_CONNECTION_TYPE)
		{
			return null;
		}

		$serverInfo = (string)($connection->getResource()?->server_info ?? '');
		if (stripos($serverInfo, self::MARIADB_MARKER) !== false)
		{
			return version_compare(self::mariaDbVersion($serverInfo), self::MARIADB_MIN_VERSION, '>=')
				? [self::MARIADB_VARIABLE, self::MARIADB_SECOND]
				: null
			;
		}

		return version_compare((string)$connection->getVersion()[0], self::MYSQL_MIN_VERSION, '>=')
			? [self::MYSQL_VARIABLE, self::MYSQL_SECOND]
			: null
		;
	}

	private static function mariaDbVersion(string $serverInfo): string
	{
		preg_match('#([0-9]+\.[0-9]+\.[0-9]+)-' . self::MARIADB_MARKER . '#i', $serverInfo, $matches);

		return $matches[1] ?? '';
	}
}
