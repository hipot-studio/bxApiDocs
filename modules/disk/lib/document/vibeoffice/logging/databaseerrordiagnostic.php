<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Main\DB\ConnectionException;
use Bitrix\Main\DB\SqlException;
use Bitrix\Main\DB\SqlQueryException;
use Throwable;

final class DatabaseErrorDiagnostic
{
	public const TABLES = [
		'b_disk_vibeoffice_delivery', 'b_disk_vo_webhook_policy', 'b_disk_vo_document',
		'b_disk_vo_saved_snapshot', 'b_disk_vo_session', 'b_disk_document_session',
		'b_disk_object', 'b_disk_version', 'b_disk_object_lock', 'b_disk_object_path',
		'b_disk_storage', 'b_disk_right', 'b_disk_simple_right', 'b_file', 'b_option', 'b_persistent_storage',
	];

	public static function fromException(Throwable $exception): array
	{
		for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious())
		{
			if ($cause instanceof AuditTransactionException)
			{
				foreach ($cause->getResult()->getExceptions() as $auditException)
				{
					$diagnostic = self::fromException($auditException);
					if ($diagnostic !== [])
					{
						return $diagnostic;
					}
				}

				return [];
			}
			if ($cause instanceof \mysqli_sql_exception)
			{
				return self::format($cause->getSqlState(), (int)$cause->getCode(), $cause->getMessage(), '') + [
					'db_error_kind' => 'query',
				];
			}
			if ($cause instanceof SqlException || $cause instanceof ConnectionException)
			{
				$message = (string)$cause->getDatabaseMessage();
				$query = $cause instanceof SqlQueryException ? (string)$cause->getQuery() : '';
				$state = null;
				$code = null;
				if (preg_match('/\A(?:ERROR|FATAL|PANIC):\s+([0-9A-Z]{5}):\s*/', $message, $match))
				{
					$state = $match[1];
					$message = substr($message, strlen($match[0]));
				}
				elseif (preg_match('/\A\(([1-9][0-9]{0,5})\) /', $message, $match))
				{
					$code = (int)$match[1];
					$message = substr($message, strlen($match[0]));
				}

				return self::format($state, $code, $message, $query) + [
					'db_error_kind' => $cause instanceof ConnectionException ? 'connection' : 'query',
				];
			}
		}

		return [];
	}

	private static function format(?string $state, ?int $code, string $message, string $query): array
	{
		$tablePattern = match (true)
		{
			$code === 1146 => "/\ATable '(?:[a-z0-9_]+\\.)?([a-z0-9_]+)' doesn't exist/i",
			$state === '42P01' => '/\Arelation "(?:[a-z0-9_]+\.)?([a-z0-9_]+)" does not exist/i',
			default => null,
		};
		$table = $tablePattern !== null && preg_match($tablePattern, $message, $match) ? $match[1] : null;
		$columnPattern = match (true)
		{
			$code === 1054 => "/\AUnknown column '(?:[a-z0-9_]+\\.)?([a-z0-9_]+)' in /i",
			$code === 1048 => "/\AColumn '([a-z0-9_]+)' cannot be null/i",
			$code === 1364 => "/\AField '([a-z0-9_]+)' doesn't have a default value/i",
			$code === 1406 => "/\AData too long for column '([a-z0-9_]+)' at row /i",
			$state === '42703' => '/\Acolumn (?:[a-z0-9_]+\.)?"?([a-z0-9_]+)"?'
				. '(?: of relation "([a-z0-9_]+)")? does not exist/i',
			$state === '23502' => '/\Anull value in column "([a-z0-9_]+)"'
				. '(?: of relation "([a-z0-9_]+)")? violates not-null constraint/i',
			default => null,
		};
		$column = $columnPattern !== null && preg_match($columnPattern, $message, $match) ? $match[1] : null;
		$table ??= $column !== null ? ($match[2] ?? null) : null;
		$state = $state === '00000' ? null : $state;

		return [
			'sql_state' => $state,
			'db_error_code' => $code ?? $state,
			'db_table' => $table === null ? self::queryTable($query) : strtolower($table),
			'db_column' => $column,
		];
	}

	private static function queryTable(string $query): ?string
	{
		// Ignore string literals and comments before looking for a single known table.
		$structure = preg_replace(<<<'REGEX'
			~'(?:''|\\.|[^'\\])*'|/\*.*?\*/|--[^\r\n]*|#[^\r\n]*~s
			REGEX, ' ', $query);
		if ($structure === null)
		{
			return null;
		}
		preg_match_all(
			'/\b(?:FROM|JOIN|INTO|UPDATE)\s+[\x60"]?([a-z0-9_]+)[\x60"]?(?![a-z0-9_.])/i',
			$structure,
			$matches,
		);
		$tables = array_unique(array_map('strtolower', $matches[1]));
		$table = count($tables) === 1 ? reset($tables) : null;

		return in_array($table, self::TABLES, true) ? $table : null;
	}
}
