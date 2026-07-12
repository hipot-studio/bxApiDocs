<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Socialnetwork\Internals\Counter\CounterFilter;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\Internals\Counter\CounterTable;
use Bitrix\Tasks\Internals\Project\Filter;

class WorkgroupCounterRepository implements WorkgroupCounterRepositoryInterface
{
	public function getGroupIdsByTasksCounter(int $userId, string $counterType): array
	{
		if (
			$userId <= 0
			|| $counterType === ''
			|| !Loader::includeModule('tasks')
		)
		{
			return [];
		}

		$query = WorkgroupTable::query();
		$query
			->setSelect(['ID'])
			->setDistinct(true)
			->registerRuntimeField(
				'UG',
				new Reference(
					'UG',
					UserToGroupTable::getEntity(),
					Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $userId),
					['join_type' => 'LEFT']
				)
			)
		;

		(new Filter($userId))->processFilterCounters($query, $counterType);

		return $this->fetchGroupIds($query);
	}

	public function getGroupIdsByCommonCounter(int $userId, string $counterType): array
	{
		if (
			$userId <= 0
			|| $counterType === ''
		)
		{
			return [];
		}

		return match ($counterType)
		{
			CounterFilter::VALUE_TASKS => $this->getGroupIdsByCommonTasksCounter($userId),
			CounterFilter::VALUE_LIVEFEED => $this->getGroupIdsByLivefeedCounter($userId),
			default => [],
		};
	}

	private function getGroupIdsByCommonTasksCounter(int $userId): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		$query = $this->createTasksCounterQuery($userId);
		$query->whereIn('TS.TYPE', array_values(array_unique(array_merge(
			array_values(CounterDictionary::MAP_EXPIRED),
			array_values(CounterDictionary::MAP_COMMENTS),
		))));

		return $this->fetchGroupIds($query);
	}

	private function getGroupIdsByLivefeedCounter(int $userId): array
	{
		$counters = \CUserCounter::getValues($userId);
		$counterPrefix = \CUserCounter::LIVEFEED_CODE . 'SG';
		$result = [];

		foreach ($counters as $counterName => $counterValue)
		{
			if (
				!is_string($counterName)
				|| !str_starts_with($counterName, $counterPrefix)
				|| (int)$counterValue <= 0
			)
			{
				continue;
			}

			$groupId = (int)mb_substr($counterName, mb_strlen($counterPrefix));
			if ($groupId > 0)
			{
				$result[$groupId] = true;
			}
		}

		return array_keys($result);
	}

	private function createTasksCounterQuery(int $userId): Query
	{
		$query = WorkgroupTable::query();
		$query
			->setSelect(['ID'])
			->setDistinct(true)
			->registerRuntimeField(
				'UG',
				new Reference(
					'UG',
					UserToGroupTable::getEntity(),
					Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $userId),
					['join_type' => 'LEFT']
				)
			)
			->registerRuntimeField(
				'TS',
				new Reference(
					'TS',
					CounterTable::getEntity(),
					Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $userId),
					['join_type' => 'INNER']
				)
			)
			->whereNotNull('UG.ID')
			->whereIn('UG.ROLE', UserToGroupTable::getRolesMember())
		;

		return $query;
	}

	private function fetchGroupIds(Query $query): array
	{
		return array_values(
			array_unique(
				array_map(
					'intval',
					array_column($query->exec()->fetchAll(), 'ID')
				)
			)
		);
	}
}
