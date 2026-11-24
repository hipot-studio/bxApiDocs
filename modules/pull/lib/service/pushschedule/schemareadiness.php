<?php

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\Connection;

final class SchemaReadiness
{
	private const CACHE_TTL = 60;
	private const CACHE_ID = 'schema_v1';
	private const CACHE_DIR = '/pull/push_schedule/schema';
	private const TIME_ZONE_COLUMNS = [
		'TIME_ZONE_ID', 'TIME_ZONE_OFFSET_MINUTES', 'TIME_ZONE_RECEIVED_AT',
	];
	private const SCHEDULE_COLUMNS = [
		'b_pull_push_schedule' => ['USER_ID', 'AUTO_ENABLED', 'REVISION', 'UPDATED_AT'],
		'b_pull_push_schedule_rule' => [
			'ID', 'USER_ID', 'ACTIVE', 'NAME', 'ICON', 'DAYS_MASK', 'START_MINUTE', 'END_MINUTE',
		],
		'b_pull_push_schedule_exception' => ['USER_ID', 'SOURCE_ID'],
		'b_pull_push_schedule_vacation_claim' => [
			'USER_ID', 'ABSENCE_ID', 'PROTOCOL_VERSION', 'CLAIMED_AT', 'CONFIRMED_AT',
		],
	];

	private readonly Connection $connection;
	private readonly Cache $cache;

	public function __construct(?Connection $connection = null, ?Cache $cache = null)
	{
		$this->connection = $connection ?? Application::getConnection();
		$this->cache = $cache ?? Cache::createInstance();
		$this->cache->noOutput();
	}

	public function isReady(): bool
	{
		return $this->getUnavailableReason() === null;
	}

	public function isTimeZoneReady(): bool
	{
		return $this->getState()['timeZoneReason'] === null;
	}

	public function getUnavailableReason(): ?string
	{
		$state = $this->getState();

		return $state['timeZoneReason'] ?? $state['scheduleReason'];
	}

	public static function clearCache(): void
	{
		Cache::createInstance()->cleanDir(self::CACHE_DIR);
		$connection = Application::getConnection();
		foreach (['b_pull_push', ...array_keys(self::SCHEDULE_COLUMNS)] as $table)
		{
			$connection->clearCaches($table);
		}
	}

	private function getState(): array
	{
		try
		{
			if ($this->cache->initCache(self::CACHE_TTL, self::CACHE_ID, self::CACHE_DIR))
			{
				$state = $this->cache->getVars();
				foreach (['timeZoneReason', 'scheduleReason'] as $key)
				{
					if (
						!is_array($state)
						|| !array_key_exists($key, $state)
						|| ($state[$key] !== null && !is_string($state[$key]))
					)
					{
						return ['timeZoneReason' => 'schema:probe_failed', 'scheduleReason' => 'schema:probe_failed'];
					}
				}

				return $state;
			}

			$state = [
				'timeZoneReason' => $this->probe(['b_pull_push' => self::TIME_ZONE_COLUMNS]),
				'scheduleReason' => $this->probe(self::SCHEDULE_COLUMNS),
			];
			if ($this->cache->startDataCache())
			{
				$this->cache->endDataCache($state);
			}

			return $state;
		}
		catch (\Throwable)
		{
			return ['timeZoneReason' => 'schema:probe_failed', 'scheduleReason' => 'schema:probe_failed'];
		}
	}

	private function probe(array $tables): ?string
	{
		try
		{
			foreach ($tables as $table => $columns)
			{
				if (!$this->connection->isTableExists($table))
				{
					return 'schema:' . $table;
				}

				$this->connection->clearCaches($table);
				$fields = array_change_key_case($this->connection->getTableFields($table), CASE_UPPER);
				foreach ($columns as $column)
				{
					if (!array_key_exists($column, $fields))
					{
						return 'schema:' . $table . '.' . $column;
					}
				}
			}
		}
		catch (\Throwable)
		{
			return 'schema:probe_failed';
		}

		return null;
	}
}
