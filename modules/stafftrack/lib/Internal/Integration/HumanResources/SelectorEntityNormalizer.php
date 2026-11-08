<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

/**
 * The codes of the entity selector as the module reads them: a person, a department - with the nested
 * ones or without them - and every employee of the portal.
 *
 * Reading the codes is shared, expanding them is not: the showcase of the selector collects one array of
 * people with a ceiling on it, while a write streams the set. Only the reading lives here.
 *
 * The parse truncates everything past {@see self::MAX_SELECTOR_ENTITIES} without a word. That is right
 * for a showcase and wrong for a write, so a caller that may not lose codes counts the raw ones against
 * the same constant before asking for the parse.
 */
final class SelectorEntityNormalizer
{
	public const MAX_SELECTOR_ENTITIES = 50;

	private const ALL_EMPLOYEES_TYPE = 'meta-user';
	private const ALL_EMPLOYEES_ID = 'all-users';

	/**
	 * @return array{0: int[], 1: int[], 2: int[]} people, flat departments, departments with their nested
	 */
	public static function normalize(array $entities): array
	{
		$userIds = [];
		$flatDepartmentIds = [];
		$recursiveDepartmentIds = [];
		$entityKeys = [];

		foreach ($entities as $entity)
		{
			if (count($entityKeys) >= self::MAX_SELECTOR_ENTITIES || !is_array($entity))
			{
				continue;
			}

			$type = $entity['type'] ?? '';
			$id = $entity['id'] ?? '';

			if ($type === 'user')
			{
				$userId = self::normalizePositiveInt($id);
				if ($userId === null)
				{
					continue;
				}

				$key = "user:{$userId}";
				if (isset($entityKeys[$key]))
				{
					continue;
				}

				$entityKeys[$key] = true;
				$userIds[] = $userId;
			}
			elseif ($type === 'structure-node')
			{
				$isFlat = is_string($id) && str_ends_with($id, ':F');
				$departmentId = self::normalizePositiveInt($isFlat ? substr($id, 0, -2) : $id);
				if ($departmentId === null)
				{
					continue;
				}

				$key = 'structure-node:' . $departmentId . ($isFlat ? ':F' : '');
				if (isset($entityKeys[$key]))
				{
					continue;
				}

				$entityKeys[$key] = true;

				if ($isFlat)
				{
					$flatDepartmentIds[] = $departmentId;
				}
				else
				{
					$recursiveDepartmentIds[] = $departmentId;
				}
			}
		}

		return [$userIds, $flatDepartmentIds, $recursiveDepartmentIds];
	}

	/**
	 * The third kind of code, and the one the parse above says nothing about. An unknown type is skipped
	 * there silently, so every employee of the portal has to be asked for by name rather than expected to
	 * fall out of the parse.
	 */
	public static function containsAllEmployees(array $entities): bool
	{
		foreach ($entities as $entity)
		{
			if (!is_array($entity))
			{
				continue;
			}

			if (
				($entity['type'] ?? '') === self::ALL_EMPLOYEES_TYPE
				&& ($entity['id'] ?? '') === self::ALL_EMPLOYEES_ID
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function normalizePositiveInt(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return null;
		}

		$value = (int)$value;

		return $value > 0 ? $value : null;
	}
}
