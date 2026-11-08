<?php

namespace Bitrix\BIConnector\DataSourceConnector;

use Bitrix\BIConnector\DB\BiTimeLimitInterface;
use Bitrix\Main\Application;
use Bitrix\Main\DB;

final class SqlTimeLimit
{
	/** @var array<int, array{connection: DB\Connection, sql: string}> */
	private static array $pendingRestore = [];

	private static bool $shutdownRegistered = false;

	public static function apply(DB\Connection $connection, int $seconds): bool
	{
		if ($seconds <= 0)
		{
			return true;
		}

		try
		{
			if ($connection instanceof BiTimeLimitInterface)
			{
				$connection->setBiTimeLimitSeconds($seconds);

				return true;
			}

			return self::applyOnPinnedConnection($connection, $seconds);
		}
		catch (\Throwable $e)
		{
			\AddMessage2Log('biconnector: failed to set SQL time limit: ' . $e->getMessage(), 'biconnector');

			return false;
		}
	}

	public static function restore(DB\Connection $connection): void
	{
		self::runRestore(spl_object_id($connection));
	}

	public static function restoreAll(): void
	{
		foreach (array_keys(self::$pendingRestore) as $id)
		{
			self::runRestore($id);
		}
	}

	public static function forgetRestore(DB\Connection $connection): void
	{
		unset(self::$pendingRestore[spl_object_id($connection)]);
	}

	private static function applyOnPinnedConnection(DB\Connection $connection, int $seconds): bool
	{
		self::pinConnection();

		try
		{
			return match ($connection->getType())
			{
				'pgsql' => self::applyPgsql($connection, $seconds),
				'mysql' => self::applyMysqlFamily($connection, $seconds),
				default => self::skipUnsupportedType($connection),
			};
		}
		finally
		{
			self::unpinConnection();
		}
	}

	private static function pinConnection(): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly(true);
	}

	private static function unpinConnection(): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly(false);
	}

	private static function skipUnsupportedType(DB\Connection $connection): bool
	{
		\AddMessage2Log(
			'biconnector: no SQL time limit for connection type "' . $connection->getType() . '"',
			'biconnector',
		);

		return true;
	}

	private static function applyMysqlFamily(DB\Connection $connection, int $seconds): bool
	{
		return self::isMariaDb($connection)
			? self::applyMariaDb($connection, $seconds)
			: self::applyMysql($connection, $seconds)
		;
	}

	private static function applyPgsql(DB\Connection $connection, int $seconds): bool
	{
		$requestedMs = $seconds * 1000;
		$currentMs = (int)self::readValue(
			$connection,
			"SELECT setting::int FROM pg_settings WHERE name = 'statement_timeout'"
		);

		if ($currentMs > 0 && $currentMs <= $requestedMs)
		{
			return true;
		}

		$connection->query('SET statement_timeout = ' . $requestedMs);
		self::rememberRestore($connection, 'SET statement_timeout = ' . $currentMs);

		return true;
	}

	private static function applyMysql(DB\Connection $connection, int $seconds): bool
	{
		$requestedMs = $seconds * 1000;
		$currentMs = (int)self::readValue($connection, 'SELECT @@SESSION.max_execution_time');

		if ($currentMs > 0 && $currentMs <= $requestedMs)
		{
			return true;
		}

		$connection->query('SET SESSION max_execution_time = ' . $requestedMs);
		self::rememberRestore($connection, 'SET SESSION max_execution_time = ' . $currentMs);

		return true;
	}

	private static function applyMariaDb(DB\Connection $connection, int $seconds): bool
	{
		$current = (float)self::readValue($connection, 'SELECT @@SESSION.max_statement_time');

		if ($current > 0 && $current <= $seconds)
		{
			return true;
		}

		$connection->query('SET SESSION max_statement_time = ' . $seconds);
		self::rememberRestore($connection, 'SET SESSION max_statement_time = ' . self::formatSeconds($current));

		return true;
	}

	private static function readValue(DB\Connection $connection, string $sql): string
	{
		$row = $connection->query($sql)->fetch();
		if (!$row)
		{
			return '0';
		}

		return (string)reset($row);
	}

	private static function formatSeconds(float $value): string
	{
		if ($value <= 0)
		{
			return '0';
		}

		return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
	}

	private static function rememberRestore(DB\Connection $connection, string $sql): void
	{
		self::$pendingRestore[spl_object_id($connection)] = [
			'connection' => $connection,
			'sql' => $sql,
		];

		if (!self::$shutdownRegistered)
		{
			self::$shutdownRegistered = true;
			register_shutdown_function([self::class, 'restoreAll']);
		}
	}

	private static function runRestore(int $id): void
	{
		if (!isset(self::$pendingRestore[$id]))
		{
			return;
		}

		$entry = self::$pendingRestore[$id];
		unset(self::$pendingRestore[$id]);

		self::pinConnection();

		try
		{
			$entry['connection']->query($entry['sql']);
		}
		catch (\Throwable $e)
		{
			\AddMessage2Log('biconnector: failed to restore SQL time limit: ' . $e->getMessage(), 'biconnector');
		}
		finally
		{
			self::unpinConnection();
		}
	}

	private static function isMariaDb(DB\Connection $connection): bool
	{
		if (!method_exists($connection, 'getResource'))
		{
			return false;
		}

		$resource = $connection->getResource();

		return $resource instanceof \mysqli
			&& stripos((string)$resource->server_info, 'MariaDB') !== false;
	}
}
