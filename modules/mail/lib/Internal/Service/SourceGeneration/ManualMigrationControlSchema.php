<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MigrationManualControlTable;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\Connection;

final class ManualMigrationControlSchema
{
	private const STATE_MISSING = 'missing';
	private const STATE_READY = 'ready';
	private const STATE_INCOMPLETE = 'incomplete';
	private const CACHE_KEY = 'mail_migration_manual_control_schema_v1';
	private const CACHE_DIR = '/mail/source_generation/migration_manual_control_schema/';
	private const READY_CACHE_TTL = 86400;
	private const NEGATIVE_CACHE_TTL = 300;

	/** @var \WeakMap<Connection, string>|null */
	private static ?\WeakMap $readiness = null;

	public function __construct(
		private readonly ?Connection $connection = null,
		private readonly ?Cache $cache = null,
	)
	{
	}

	public static function clearCache(?Cache $cache = null): void
	{
		self::$readiness = null;
		($cache ?? Cache::createInstance())->clean(self::CACHE_KEY, self::CACHE_DIR);
	}

	public function isReady(): bool
	{
		$connection = $this->connection ?? Application::getConnection();
		self::$readiness ??= new \WeakMap();
		if (isset(self::$readiness[$connection]))
		{
			return $this->resolveCachedState(self::$readiness[$connection]);
		}

		$state = $this->readPersistentState();
		if ($state !== null)
		{
			self::$readiness[$connection] = $state;

			return $this->resolveCachedState($state);
		}

		$state = $this->detectState($connection);
		self::$readiness[$connection] = $state;
		$this->writePersistentState($state);

		return $this->resolveCachedState($state);
	}

	private function detectState(Connection $connection): string
	{
		$tableName = MigrationManualControlTable::getTableName();
		if (!$connection->isTableExists($tableName))
		{
			return self::STATE_MISSING;
		}

		try
		{
			$fields = array_change_key_case($connection->getTableFields($tableName), CASE_UPPER);
		}
		catch (\Throwable $exception)
		{
			if (!$connection->isTableExists($tableName))
			{
				return self::STATE_MISSING;
			}

			throw $exception;
		}

		foreach (array_keys(MigrationManualControlTable::getMap()) as $field)
		{
			if (!array_key_exists(strtoupper((string)$field), $fields))
			{
				return self::STATE_INCOMPLETE;
			}
		}

		return self::STATE_READY;
	}

	private function readPersistentState(): ?string
	{
		$cache = $this->cache ?? Cache::createInstance();
		if (!$cache->initCache(self::READY_CACHE_TTL, self::CACHE_KEY, self::CACHE_DIR))
		{
			return null;
		}

		$data = $cache->getVars();
		$state = is_array($data) ? ($data['state'] ?? null) : null;
		$expiresAt = is_array($data) ? ($data['expiresAt'] ?? null) : null;
		if (
			!is_string($state)
			|| !in_array($state, [self::STATE_MISSING, self::STATE_READY, self::STATE_INCOMPLETE], true)
			|| !is_int($expiresAt)
			|| $expiresAt <= time()
		)
		{
			$cache->clean(self::CACHE_KEY, self::CACHE_DIR);

			return null;
		}

		return $state;
	}

	private function writePersistentState(string $state): void
	{
		$ttl = $state === self::STATE_READY ? self::READY_CACHE_TTL : self::NEGATIVE_CACHE_TTL;
		$cache = $this->cache ?? Cache::createInstance();
		if ($cache->startDataCache($ttl, self::CACHE_KEY, self::CACHE_DIR))
		{
			$cache->endDataCache([
				'state' => $state,
				'expiresAt' => time() + $ttl,
			]);
		}
	}

	private function resolveCachedState(string $state): bool
	{
		return match ($state)
		{
			self::STATE_MISSING => false,
			self::STATE_READY => true,
			default => throw new \UnexpectedValueException('The manual migration control schema is incomplete'),
		};
	}
}
