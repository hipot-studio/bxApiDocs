<?php

namespace Bitrix\Sign\Service;

use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\MysqliConnection;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Internal\MemberTable;

/** @internal */
final class MemberValidationCrmDispatchGuard
{
	private static array $reservations = [];
	private static array $physicalKeys = [];
	private static array $owners = [];
	private static bool $poisoned = false;
	private static bool $shutdownRegistered = false;
	private static int $sequence = 0;

	public function run(Connection $master, int $documentId, int $memberId, \Closure $critical): void
	{
		$isPg = $master instanceof PgsqlConnection;
		if ($documentId <= 0 || $memberId <= 0 || self::$poisoned
			|| (!$isPg && !$master instanceof MysqliConnection))
		{
			throw self::failure(25967007, 'setup', 'invalid_context');
		}
		$functions = $isPg
			? ['hash', 'pg_connect', 'pg_query_params', 'pg_query', 'pg_fetch_result', 'pg_free_result',
				'pg_close', 'pg_parameter_status', 'pg_get_pid', 'pg_connection_status', 'pg_transaction_status']
			: ['hash', 'mysqli_init', 'mysqli_options', 'mysqli_real_connect', 'mysqli_query', 'mysqli_fetch_row',
				'mysqli_free_result', 'mysqli_close', 'mysqli_set_charset', 'mysqli_character_set_name'];
		foreach ($functions as $function)
		{
			if (!function_exists($function))
			{
				throw self::failure(25967007, 'setup', 'native_unavailable');
			}
		}
		$constants = $isPg
			? ['PGSQL_CONNECT_FORCE_NEW', 'PGSQL_CONNECTION_OK', 'PGSQL_TRANSACTION_INERROR']
			: ['MYSQLI_OPT_CONNECT_TIMEOUT', 'MYSQLI_OPT_READ_TIMEOUT'];
		foreach ($constants as $constant)
		{
			if (!defined($constant))
			{
				throw self::failure(25967007, 'setup', 'native_unavailable');
			}
		}
		$canonical = 'sign.validation.crm.v2';
		foreach ([(string)$master->getDatabase(), $isPg ? 'public' : (string)$master->getDatabase(),
			MemberTable::getTableName(), (string)$documentId, (string)$memberId] as $field)
		{
			$canonical .= strlen($field) . ':' . $field;
		}
		$hash = hash('sha256', $canonical);
		$keys = [];
		if ($isPg)
		{
			$bytes = hex2bin($hash);
			foreach ([0, 4] as $offset)
			{
				$first = ord($bytes[$offset]);
				$value = ($first & 127) * 16777216 + ord($bytes[$offset + 1]) * 65536
					+ ord($bytes[$offset + 2]) * 256 + ord($bytes[$offset + 3]);
				$keys[] = (string)(($first & 128) === 0 ? $value : $value - 2147483647 - 1);
			}
		}
		$physical = ($isPg ? 'pg:' . implode(':', $keys) : 'mysql:' . $hash);
		if (isset(self::$reservations[$canonical]) || isset(self::$physicalKeys[$physical]))
		{
			throw self::failure(25967005, 'acquire', 'reentry');
		}
		if (count(self::$reservations) >= 16)
		{
			throw self::failure(25967007, 'acquire', 'capacity');
		}
		self::$reservations[$canonical] = true;
		self::$physicalKeys[$physical] = true;
		$owner = (object)[
			'link' => null, 'master' => $master, 'pg' => $isPg, 'keys' => $keys, 'hash' => $hash,
			'attempted' => false, 'acquired' => false, 'ambiguous' => false,
		];
		$id = ++self::$sequence;
		self::$owners[$id] = $owner;
		if (!self::$shutdownRegistered)
		{
			self::$shutdownRegistered = true;
			register_shutdown_function(static function (): void {
				foreach (self::$owners as $id => $owner)
				{
					self::cleanup($owner, $id);
				}
			});
		}
		$original = null;
		try
		{
			try
			{
				self::native(function () use ($owner): void {
					$owner->pg ? $this->connectPg($owner) : $this->connectMysql($owner);
					$this->acquire($owner);
				});
			}
			catch (SystemException $error)
			{
				throw $error;
			}
			catch (\Throwable)
			{
				throw self::failure(25967007, 'acquire', 'native_fault');
			}
			$critical();
		}
		catch (\Throwable $error)
		{
			$original = $error;
		}
		finally
		{
			$cleanupFailed = self::cleanup($owner, $id);
			unset(self::$reservations[$canonical], self::$physicalKeys[$physical]);
		}
		if ($original !== null)
		{
			throw $original;
		}
		if ($cleanupFailed)
		{
			throw self::failure(25967004, 'release', 'cleanup_fault');
		}
	}

	private function connectMysql(object $owner): void
	{
		$master = $owner->master;
		$config = $master->getConfiguration();
		[$host, $port] = self::endpoint($config, '3306');
		$owner->link = mysqli_init();
		if (!$owner->link instanceof \mysqli
			|| !mysqli_options($owner->link, MYSQLI_OPT_CONNECT_TIMEOUT, 2)
			|| !mysqli_options($owner->link, MYSQLI_OPT_READ_TIMEOUT, 3)
			|| !mysqli_real_connect($owner->link, $host, (string)($config['login'] ?? ''),
				(string)($config['password'] ?? ''), (string)$master->getDatabase(), (int)$port))
		{
			throw self::failure(25967007, 'setup', 'connect_failed');
		}
		$main = $master->getResource();
		$charset = mysqli_character_set_name($main);
		if (!mysqli_set_charset($owner->link, $charset) || $owner->link->thread_id === $main->thread_id)
		{
			throw self::failure(25967007, 'setup', 'owner_mismatch');
		}
	}

	private function connectPg(object $owner): void
	{
		$master = $owner->master;
		PgsqlValidationCrmEscapingBridge::repoint($master);
		$main = $master->getResource();
		$encoding = pg_parameter_status($main, 'client_encoding');
		$version = pg_parameter_status($main, 'server_version');
		$config = $master->getConfiguration();
		[$host, $port] = self::endpoint($config, '5432');
		$values = [
			'host' => $host, 'port' => $port, 'dbname' => (string)$master->getDatabase(),
			'user' => (string)($config['login'] ?? ''), 'password' => (string)($config['password'] ?? ''),
			'connect_timeout' => '2',
			'options' => '--client_encoding=' . $encoding . ' --standard_conforming_strings=on --statement_timeout=1000',
			'application_name' => 'sign_validation_guard_' . bin2hex(random_bytes(12)) . '_' . self::$sequence,
		];
		$fields = [];
		foreach ($values as $key => $value)
		{
			if (str_contains($value, "\0"))
			{
				throw self::failure(25967007, 'setup', 'invalid_configuration');
			}
			$fields[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
		}
		$owner->link = pg_connect(implode(' ', $fields), PGSQL_CONNECT_FORCE_NEW);
		if (!$owner->link instanceof \PgSql\Connection)
		{
			throw self::failure(25967007, 'setup', 'connect_failed');
		}
		PgsqlValidationCrmEscapingBridge::repoint($master);
		if (pg_parameter_status($owner->link, 'client_encoding') !== $encoding
			|| pg_parameter_status($owner->link, 'standard_conforming_strings') !== 'on'
			|| pg_parameter_status($owner->link, 'server_version') !== $version
			|| pg_get_pid($owner->link) === pg_get_pid($main))
		{
			throw self::failure(25967007, 'setup', 'owner_mismatch');
		}
	}

	private function acquire(object $owner): void
	{
		$owner->attempted = true;
		$owner->ambiguous = true;
		if ($owner->pg)
		{
			$start = hrtime(true);
			do
			{
				if (hrtime(true) - $start >= 2_000_000_000)
				{
					break;
				}
				$owner->ambiguous = true;
				$value = self::pgScalar($owner->link,
					'SELECT pg_catalog.pg_try_advisory_lock($1::int,$2::int)', $owner->keys);
				if ($value !== 't' && $value !== 'f')
				{
					throw self::failure(25967007, 'acquire', 'invalid_result');
				}
				$owner->acquired = $value === 't';
				$owner->ambiguous = false;
				if (hrtime(true) - $start >= 2_000_000_000 || $owner->acquired)
				{
					break;
				}
				usleep(20000);
			}
			while (true);
			if (!$owner->acquired || hrtime(true) - $start >= 2_000_000_000
				|| pg_connection_status($owner->link) !== PGSQL_CONNECTION_OK
				|| self::pgScalar($owner->link, 'SELECT 1', []) !== '1')
			{
				throw self::failure(25967007, 'acquire', 'ownership_failed');
			}
		}
		else
		{
			$value = self::mysqlScalar($owner->link, "SELECT GET_LOCK('{$owner->hash}',2)");
			if ($value === 1 || $value === '1')
			{
				$owner->acquired = true;
				$owner->ambiguous = false;
			}
			elseif ($value === 0 || $value === '0')
			{
				$owner->ambiguous = false;
			}
			if (!$owner->acquired
				|| (string)self::mysqlScalar($owner->link, "SELECT IS_USED_LOCK('{$owner->hash}')")
					!== (string)$owner->link->thread_id)
			{
				throw self::failure(25967007, 'acquire', 'ownership_failed');
			}
		}
	}

	private static function cleanup(object $owner, int $id): bool
	{
		if ($owner->link === null || $owner->link === false)
		{
			unset(self::$owners[$id]);

			return false;
		}
		$failed = false;
		if ($owner->pg && $owner->attempted)
		{
			try
			{
				self::native(static function () use ($owner): void {
					if (pg_transaction_status($owner->link) === PGSQL_TRANSACTION_INERROR)
					{
						$result = pg_query($owner->link, 'ROLLBACK');
						if ($result === false)
						{
							throw new \RuntimeException();
						}
						pg_free_result($result);
					}
				});
			}
			catch (\Throwable)
			{
				$failed = true;
				self::logFault('rollback');
			}
		}

		if ($owner->attempted)
		{
			try
			{
				self::native(static function () use ($owner): void {
					if ($owner->pg)
					{
						$value = self::pgScalar($owner->link,
							'SELECT pg_catalog.pg_advisory_unlock($1::int,$2::int)', $owner->keys);
						$valid = $value === 't' || (!$owner->acquired && !$owner->ambiguous && $value === 'f');
					}
					else
					{
						$value = self::mysqlScalar($owner->link, "SELECT RELEASE_LOCK('{$owner->hash}')");
						$valid = $value === 1 || $value === '1'
							|| (!$owner->acquired && !$owner->ambiguous
								&& ($value === 0 || $value === '0' || $value === null));
					}
					if (!$valid)
					{
						throw new \RuntimeException();
					}
				});
			}
			catch (\Throwable)
			{
				$failed = true;
				self::logFault('unlock');
			}
		}
		if ($owner->pg)
		{
			try
			{
				PgsqlValidationCrmEscapingBridge::repoint($owner->master);
			}
			catch (\Throwable)
			{
				$failed = true;
				self::logFault('repoint');
			}
		}
		$closed = false;
		for ($attempt = 0; $attempt < 2; $attempt++)
		{
			try
			{
				$closed = self::native(static fn(): bool => $owner->pg
					? pg_close($owner->link) : mysqli_close($owner->link));
			}
			catch (\Throwable)
			{
				$closed = false;
			}
			if ($closed)
			{
				unset(self::$owners[$id]);
				break;
			}
			$failed = true;
			self::logFault('close');
		}
		if (!$closed)
		{
			self::$poisoned = true;
		}

		return $failed;
	}

	private static function mysqlScalar(\mysqli $link, string $sql): mixed
	{
		$result = mysqli_query($link, $sql);
		if (!$result instanceof \mysqli_result)
		{
			throw new \RuntimeException();
		}
		try
		{
			$row = mysqli_fetch_row($result);
			if (!is_array($row) || count($row) !== 1)
			{
				throw new \RuntimeException();
			}

			return $row[0];
		}
		finally
		{
			mysqli_free_result($result);
		}
	}

	private static function pgScalar(\PgSql\Connection $link, string $sql, array $keys): mixed
	{
		$result = pg_query_params($link, $sql, $keys);
		if ($result === false)
		{
			throw new \RuntimeException();
		}
		try
		{
			return pg_fetch_result($result, 0, 0);
		}
		finally
		{
			pg_free_result($result);
		}
	}

	private static function endpoint(array $config, string $defaultPort): array
	{
		$host = (string)($config['host'] ?? '');
		if (str_starts_with($host, 'p:'))
		{
			$host = substr($host, 2);
		}
		if (!preg_match('/^([^\s,:;=]+)(?::([0-9]+))?$/D', $host, $parts))
		{
			throw self::failure(25967007, 'setup', 'invalid_endpoint');
		}

		return [$parts[1], $parts[2] ?? $defaultPort];
	}

	private static function native(\Closure $operation): mixed
	{
		set_error_handler(static fn(): bool => true);
		try
		{
			return $operation();
		}
		finally
		{
			restore_error_handler();
		}
	}

	private static function failure(int $code, string $phase, string $reason): SystemException
	{
		self::logFault($reason, $phase, $code);

		return new SystemException('Validation CRM guard failed.', $code);
	}

	private static function logFault(string $reason, string $phase = 'release', int $code = 25967004): void
	{
		try
		{
			Container::instance()->getLogger()->error('Validation CRM guard failed.', [
				'code' => match ($code) {
					25967005 => 'SIGN_VALIDATION_CRM_GUARD_REENTRY',
					25967007 => 'SIGN_VALIDATION_CRM_GUARD_ACQUIRE_FAILED',
					default => 'SIGN_VALIDATION_CRM_GUARD_RELEASE_FAILED',
				},
				'phase' => $phase, 'reason' => $reason,
			]);
		}
		catch (\Throwable)
		{
			// Logging cannot replace the original failure or interrupt physical cleanup.
		}
	}
}
