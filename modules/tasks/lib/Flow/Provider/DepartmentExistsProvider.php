<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Provider;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Flow\Provider\Trait\CacheTrait;
use Bitrix\Tasks\Integration\Intranet\Flow\Department;

class DepartmentExistsProvider
{
	use CacheTrait;

	/** @var array<int, bool> */
	private static array $cache = [];

	/**
	 * @param int[] $departmentIds
	 * @return int[]
	 */
	public function filterExists(array $departmentIds): array
	{
		Collection::normalizeArrayValuesByInt($departmentIds, false);
		if (empty($departmentIds))
		{
			return [];
		}

		$this->load(...$departmentIds);

		return array_filter(
			$departmentIds,
			static fn(int $departmentId): bool => self::$cache[$departmentId],
		);
	}

	public function exists(int $departmentId): bool
	{
		if ($departmentId <= 0)
		{
			return false;
		}

		$this->load($departmentId);

		return self::$cache[$departmentId];
	}

	private function load(int ...$departmentIds): void
	{
		$notLoaded = $this->getNotLoaded(...$departmentIds);
		if (empty($notLoaded))
		{
			return;
		}

		$departments = Department::getDepartmentsData(...$notLoaded);
		foreach ($notLoaded as $departmentId)
		{
			$departmentTitle = $departments[$departmentId] ?? null;

			$this->store($departmentId, ($departmentTitle !== null));
		}
	}
}
