<?php

namespace Bitrix\Voximplant\Agent;

class StatisticReindexer
{
	private const MODULE_ID = 'voximplant';

	public static function reindexByUser(int $userId): string
	{
		return '';
	}

	public static function reindexByCrmEntity(string $type, int $id): string
	{
		return '';
	}

	public static function reindexByConfig(string $searchId): string
	{
		return '';
	}

	public static function scheduleForUser(int $userId): void
	{
		return;
	}

	public static function scheduleForCrmEntity(string $type, int $id): void
	{
		return;
	}

	public static function scheduleForConfig(string $searchId): void
	{
		return;
	}

	private static function reindexByFilter(array $filter): void
	{
		return;
	}

	private static function schedule(string $callable): void
	{
		return;
	}
}
