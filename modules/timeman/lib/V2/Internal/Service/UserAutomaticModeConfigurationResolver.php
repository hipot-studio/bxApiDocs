<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use Bitrix\Main\UserFieldTable;
use Bitrix\Timeman\V2\Internal\Repository\AutomaticModeDepartmentRepository;
use Bitrix\Timeman\Model\Schedule\Assignment\Department\ScheduleDepartmentTable;
use Bitrix\Timeman\Model\Schedule\Assignment\User\ScheduleUserTable;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\V2\Internal\Repository\DepartmentTimeManSettingsProvider;

class UserAutomaticModeConfigurationResolver
{
	private const MAX_USER_IDS = 300;
	private readonly AutomaticModeConfigurationCache $cache;

	public function __construct(
		private readonly ?DepartmentTimeManSettingsProvider $departmentSettingsProvider = null,
		?AutomaticModeConfigurationCache $cache = null,
	)
	{
		$this->cache = $cache ?? new AutomaticModeConfigurationCache();
	}

	/**
	 * @return array<int, array{trusted: bool, hasSchedule: bool, hasFlexibleSchedule: bool, timeTrackingEnabled: bool}>
	 */
	public function resolveForUserIds(array $userIds): array
	{
		return $this->resolve($userIds, true);
	}

	/**
	 * @return array<int, array{trusted: bool, hasSchedule: bool, hasFlexibleSchedule: bool, timeTrackingEnabled: bool}>
	 */
	public function resolveFreshForUserIds(array $userIds): array
	{
		return $this->resolve($userIds, false);
	}

	private function resolve(array $userIds, bool $useCache): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if (count($userIds) > self::MAX_USER_IDS)
		{
			throw new ArgumentOutOfRangeException('userIds', null, self::MAX_USER_IDS);
		}

		if ($userIds === [])
		{
			return [];
		}
		$entries = [];
		$pendingUserIds = $userIds;
		for ($attempt = 0; $attempt < 2 && $pendingUserIds !== []; $attempt++)
		{
			$entries = array_replace($entries, $this->resolveBatch($pendingUserIds, $useCache && $attempt === 0));
			$pendingUserIds = [];
			foreach ($entries as $userId => $entry)
			{
				if ($entry !== null && !$this->cache->isFresh($entry))
				{
					$pendingUserIds[] = $userId;
				}
			}
		}

		$result = [];
		foreach ($userIds as $userId)
		{
			$entry = $entries[$userId] ?? null;
			$result[$userId] = $this->cache->isFresh($entry)
				? $entry['configuration']
				: $this->untrustedConfiguration();
		}

		return $result;
	}

	private function resolveBatch(array $userIds, bool $useCache): array
	{
		$entries = [];
		$missingUserIds = [];
		foreach ($userIds as $userId)
		{
			$entries[$userId] = $useCache ? $this->cache->read($userId) : null;
			if ($entries[$userId] === null)
			{
				$missingUserIds[] = $userId;
			}
		}

		if ($missingUserIds !== [])
		{
			$expiresAt = $this->cache->now() + AutomaticModeConfigurationCache::TTL;
			$pool = Application::getInstance()->getConnectionPool();
			$pool->useMasterOnly(true);
			try
			{
				$configurations = $this->loadConfigurations($missingUserIds);
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
			foreach ($configurations as $userId => $configuration)
			{
				if ($configuration['trusted'])
				{
					$entries[$userId] = ['expiresAt' => $expiresAt, 'configuration' => $configuration];
					$this->cache->write($userId, $entries[$userId]);
				}
				elseif (!$useCache)
				{
					$this->cache->invalidateUser($userId);
				}
			}
		}

		return $entries;
	}

	private function untrustedConfiguration(): array
	{
		return [
			'trusted' => false,
			'hasSchedule' => false,
			'hasFlexibleSchedule' => false,
			'timeTrackingEnabled' => false,
		];
	}

	private function loadConfigurations(array $userIds): array
	{
		$result = array_fill_keys($userIds, [
			'trusted' => false,
			'hasSchedule' => false,
			'hasFlexibleSchedule' => false,
			'timeTrackingEnabled' => false,
		]);

		try
		{
			$users = $this->loadUsers($userIds);
			$enumValues = $this->loadTimeManEnumValues();
			$parentByDepartment = $this->loadParentByDepartment();
			$departmentChains = $this->buildDepartmentChains($users, $parentByDepartment);
			$scheduleTypesByUser = $this->loadScheduleTypesByUser($userIds, $departmentChains);
		}
		catch (\Throwable)
		{
			return $result;
		}

		$departmentIds = [];
		foreach ($departmentChains as $chains)
		{
			foreach ($chains as $chain)
			{
				$departmentIds = array_merge($departmentIds, $chain);
			}
		}
		$departmentIds = array_values(array_unique($departmentIds));

		try
		{
			$departmentSettings = ($this->departmentSettingsProvider ?? new DepartmentTimeManSettingsProvider())
				->getForDepartments($departmentIds, $parentByDepartment)
			;
		}
		catch (\Throwable)
		{
			$departmentSettings = [
				'values' => [],
				'failedIds' => array_fill_keys($departmentIds, true),
			];
		}

		foreach ($users as $userId => $user)
		{
			$types = $scheduleTypesByUser[$userId] ?? [];
			$hasSchedule = isset($types[ScheduleTable::SCHEDULE_TYPE_FIXED])
				|| isset($types[ScheduleTable::SCHEDULE_TYPE_SHIFT]);
			$hasFlexibleSchedule = isset($types[ScheduleTable::SCHEDULE_TYPE_FLEXTIME]);
			$personalSetting = $this->normalizeSetting($user['UF_TIMEMAN'] ?? null, $enumValues);
			$timeTrackingEnabled = match ($personalSetting)
			{
				'N' => false,
				'Y' => true,
				default => null,
			};

			if ($timeTrackingEnabled === null)
			{
				$timeTrackingEnabled = $this->resolveDepartmentSetting(
					(array)($user['UF_DEPARTMENT'] ?? []),
					$departmentSettings,
				);
			}

			if ($timeTrackingEnabled === null && !$hasSchedule && !$hasFlexibleSchedule)
			{
				continue;
			}

			$result[$userId] = [
				'trusted' => true,
				'hasSchedule' => $hasSchedule,
				'hasFlexibleSchedule' => $hasFlexibleSchedule,
				'timeTrackingEnabled' => $timeTrackingEnabled ?? false,
			];
		}

		return $result;
	}

	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}

	protected function loadUsers(array $userIds): array
	{
		$users = [];
		$query = UserTable::query()
			->setSelect(['ID', 'UF_TIMEMAN', 'UF_DEPARTMENT'])
			->whereIn('ID', $userIds)
			->exec()
		;

		while ($user = $query->fetch())
		{
			$users[(int)$user['ID']] = $user;
		}

		return $users;
	}

	protected function loadTimeManEnumValues(): array
	{
		$field = UserFieldTable::query()
			->setSelect(['ID'])
			->where('ENTITY_ID', 'USER')
			->where('FIELD_NAME', 'UF_TIMEMAN')
			->fetch()
		;
		if (!is_array($field) || (int)($field['ID'] ?? 0) <= 0)
		{
			return [];
		}

		$values = [];
		$enumResult = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => (int)$field['ID']], false);
		while ($enum = $enumResult->Fetch())
		{
			$values[(string)$enum['ID']] = (string)$enum['XML_ID'];
		}

		return $values;
	}

	private function normalizeSetting(mixed $value, array $enumValues): ?string
	{
		if ($value === null || $value === false || $value === '' || $value === 0 || $value === '0')
		{
			return null;
		}

		$value = $enumValues[(string)$value] ?? $value;
		$value = strtoupper((string)$value);

		return in_array($value, ['Y', 'N'], true) ? $value : null;
	}

	private function resolveDepartmentSetting(array $departmentIds, array $departmentSettings): ?bool
	{
		if ($departmentIds === [])
		{
			return false;
		}

		$hasFailedDepartment = false;
		foreach ($departmentIds as $departmentId)
		{
			$departmentId = (int)$departmentId;
			if (($departmentSettings['values'][$departmentId] ?? false) === true)
			{
				return true;
			}
			if (isset($departmentSettings['failedIds'][$departmentId]))
			{
				$hasFailedDepartment = true;
			}
		}

		return $hasFailedDepartment ? null : false;
	}

	/** @return array<int, int> */
	protected function loadParentByDepartment(): array
	{
		return (new AutomaticModeDepartmentRepository())->getParentByDepartment();
	}

	/**
	 * @return array<int, array<int, int[]>>
	 */
	private function buildDepartmentChains(array $users, array $parentByDepartment): array
	{
		$chains = [];
		foreach ($users as $userId => $user)
		{
			foreach ((array)($user['UF_DEPARTMENT'] ?? []) as $departmentId)
			{
				$chain = [];
				$currentId = (int)$departmentId;
				while ($currentId > 0 && !in_array($currentId, $chain, true))
				{
					$chain[] = $currentId;
					$currentId = $parentByDepartment[$currentId] ?? 0;
				}
				if ($chain !== [])
				{
					$chains[$userId][] = $chain;
				}
			}
		}

		return $chains;
	}

	/**
	 * @return array<int, array<string, true>>
	 */
	protected function loadScheduleTypesByUser(array $userIds, array $departmentChains): array
	{
		$userAssignments = $this->loadUserAssignments($userIds);
		$departmentIds = [];
		foreach ($departmentChains as $chains)
		{
			foreach ($chains as $chain)
			{
				$departmentIds = array_merge($departmentIds, $chain);
			}
		}
		$departmentAssignments = $this->loadDepartmentAssignments(array_values(array_unique($departmentIds)));
		$allUsersSchedules = $this->loadAllUsersSchedules();

		return $this->resolveScheduleTypesByUser(
			$userIds,
			$departmentChains,
			$userAssignments,
			$departmentAssignments,
			$allUsersSchedules,
		);
	}

	/**
	 * @return array<int, array<string, true>>
	 */
	protected function resolveScheduleTypesByUser(
		array $userIds,
		array $departmentChains,
		array $userAssignments,
		array $departmentAssignments,
		array $allUsersSchedules,
	): array
	{
		$typesByUser = [];
		foreach ($userIds as $userId)
		{
			$departmentScheduleIds = [];
			foreach ($departmentChains[$userId] ?? [] as $chain)
			{
				foreach ($chain as $departmentId)
				{
					$departmentScheduleIds = array_merge(
						$departmentScheduleIds,
						array_keys($departmentAssignments[$departmentId] ?? []),
					);
				}
			}

			$scheduleIds = array_unique(array_merge(
				array_keys($userAssignments[$userId] ?? []),
				$departmentScheduleIds,
				array_keys($allUsersSchedules),
			));

			foreach ($scheduleIds as $scheduleId)
			{
				$userAssignment = $userAssignments[$userId][$scheduleId] ?? null;
				if ($userAssignment !== null)
				{
					if ($userAssignment['status'] === ScheduleUserTable::INCLUDED)
					{
						$typesByUser[$userId][$userAssignment['type']] = true;
					}

					continue;
				}

				$resolvedStatuses = [];
				$type = null;
				foreach ($departmentChains[$userId] ?? [] as $chain)
				{
					foreach ($chain as $departmentId)
					{
						$assignment = $departmentAssignments[$departmentId][$scheduleId] ?? null;
						if ($assignment !== null)
						{
							$resolvedStatuses[] = $assignment['status'];
							$type = $assignment['type'];

							break;
						}
					}
				}

				if (in_array(ScheduleDepartmentTable::INCLUDED, $resolvedStatuses, true))
				{
					$typesByUser[$userId][$type] = true;
				}
				elseif ($resolvedStatuses === [] && isset($allUsersSchedules[$scheduleId]))
				{
					$typesByUser[$userId][$allUsersSchedules[$scheduleId]] = true;
				}
			}
		}

		return $typesByUser;
	}

	private function loadUserAssignments(array $userIds): array
	{
		$result = [];
		$query = ScheduleUserTable::query()
			->setSelect(['USER_ID', 'SCHEDULE_ID', 'STATUS', 'TYPE' => 'SCHEDULE.SCHEDULE_TYPE'])
			->whereIn('USER_ID', $userIds)
			->where('SCHEDULE.DELETED', ScheduleTable::DELETED_NO)
			->exec()
		;
		while ($row = $query->fetch())
		{
			$result[(int)$row['USER_ID']][(int)$row['SCHEDULE_ID']] = [
				'status' => (int)$row['STATUS'],
				'type' => (string)$row['TYPE'],
			];
		}

		return $result;
	}

	private function loadDepartmentAssignments(array $departmentIds): array
	{
		if ($departmentIds === [])
		{
			return [];
		}

		$result = [];
		$query = ScheduleDepartmentTable::query()
			->setSelect(['DEPARTMENT_ID', 'SCHEDULE_ID', 'STATUS', 'TYPE' => 'SCHEDULE.SCHEDULE_TYPE'])
			->whereIn('DEPARTMENT_ID', $departmentIds)
			->where('SCHEDULE.DELETED', ScheduleTable::DELETED_NO)
			->exec()
		;
		while ($row = $query->fetch())
		{
			$result[(int)$row['DEPARTMENT_ID']][(int)$row['SCHEDULE_ID']] = [
				'status' => (int)$row['STATUS'],
				'type' => (string)$row['TYPE'],
			];
		}

		return $result;
	}

	private function loadAllUsersSchedules(): array
	{
		$result = [];
		$query = ScheduleTable::query()
			->setSelect(['ID', 'SCHEDULE_TYPE'])
			->where('DELETED', ScheduleTable::DELETED_NO)
			->where('IS_FOR_ALL_USERS', true)
			->exec()
		;
		while ($row = $query->fetch())
		{
			$result[(int)$row['ID']] = (string)$row['SCHEDULE_TYPE'];
		}

		return $result;
	}
}
