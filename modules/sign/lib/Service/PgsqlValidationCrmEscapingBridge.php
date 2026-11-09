<?php

namespace Bitrix\Sign\Service;

use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\SystemException;

/** @internal */
final class PgsqlValidationCrmEscapingBridge
{
	private static ?\PgSql\Connection $auxiliary = null;
	private static ?string $dsn = null;
	private static ?string $fingerprint = null;
	private static ?int $pid = null;

	public static function repoint(PgsqlConnection $master): void
	{
		foreach (['hash', 'pg_connect', 'pg_parameter_status', 'pg_connection_status', 'pg_get_pid'] as $function)
		{
			if (!function_exists($function))
			{
				throw new SystemException('Validation CRM guard failed.', 25967007);
			}
		}
		if (!defined('PGSQL_CONNECTION_OK'))
		{
			throw new SystemException('Validation CRM guard failed.', 25967007);
		}
		set_error_handler(static fn(): bool => true);
		try
		{
			$resource = $master->getResource();
			$encoding = pg_parameter_status($resource, 'client_encoding');
			$strings = pg_parameter_status($resource, 'standard_conforming_strings');
			$version = pg_parameter_status($resource, 'server_version');
			if (!is_string($encoding) || !self::validEncoding($encoding) || $strings !== 'on'
				|| !is_string($version) || !preg_match('/^(1[1-9]|[2-9][0-9])(?:\.|$)/D', $version))
			{
				throw new SystemException('Validation CRM guard failed.', 25967007);
			}
			$config = $master->getConfiguration();
			$host = (string)($config['host'] ?? '');
			if (str_starts_with($host, 'p:'))
			{
				$host = substr($host, 2);
			}
			if (!preg_match('/^([^\s,:;=]+)(?::([0-9]+))?$/D', $host, $parts))
			{
				throw new SystemException('Validation CRM guard failed.', 25967007);
			}
			$values = [
				'host' => $parts[1],
				'port' => $parts[2] ?? '5432',
				'dbname' => (string)$master->getDatabase(),
				'user' => (string)($config['login'] ?? ''),
				'password' => (string)($config['password'] ?? ''),
				'connect_timeout' => '2',
				'options' => '--client_encoding=' . $encoding . ' --standard_conforming_strings=on',
			];
			$fingerprint = hash('sha256', serialize([spl_object_id($resource), $values, $version]));
			if (self::$dsn === null)
			{
				$values['application_name'] = 'sign_validation_aux_' . bin2hex(random_bytes(12));
				$fields = [];
				foreach ($values as $key => $value)
				{
					if (str_contains($value, "\0"))
					{
						throw new SystemException('Validation CRM guard failed.', 25967007);
					}
					$fields[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
				}
				self::$dsn = implode(' ', $fields);
				self::$fingerprint = $fingerprint;
				$auxiliary = pg_connect(self::$dsn);
				if (!$auxiliary instanceof \PgSql\Connection)
				{
					throw new SystemException('Validation CRM guard failed.', 25967007);
				}
				self::$auxiliary = $auxiliary;
				self::$pid = pg_get_pid($auxiliary);
			}
			else
			{
				if (self::$fingerprint !== $fingerprint || self::$auxiliary === null
					|| pg_connection_status(self::$auxiliary) !== PGSQL_CONNECTION_OK)
				{
					throw new SystemException('Validation CRM guard failed.', 25967007);
				}
				if (pg_connect(self::$dsn) !== self::$auxiliary)
				{
					throw new SystemException('Validation CRM guard failed.', 25967007);
				}
			}
			if (pg_get_pid(self::$auxiliary) !== self::$pid
				|| pg_get_pid(self::$auxiliary) === pg_get_pid($resource)
				|| pg_parameter_status(self::$auxiliary, 'client_encoding') !== $encoding
				|| pg_parameter_status(self::$auxiliary, 'standard_conforming_strings') !== 'on'
				|| pg_parameter_status(self::$auxiliary, 'server_version') !== $version)
			{
				throw new SystemException('Validation CRM guard failed.', 25967007);
			}
		}
		finally
		{
			restore_error_handler();
		}
	}

	private static function validEncoding(string $encoding): bool
	{
		return preg_match(
			'/^(SQL_ASCII|UTF8|LATIN(?:[1-9]|10)|WIN(?:866|874|125[0-8])|ISO_8859_[5-8]|KOI8[RU]'
			. '|EUC_(?:JP|CN|KR|TW|JIS_2004)|MULE_INTERNAL|SJIS|BIG5|GBK|UHC|GB18030|JOHAB|SHIFT_JIS_2004)$/D',
			$encoding,
		) === 1;
	}
}
