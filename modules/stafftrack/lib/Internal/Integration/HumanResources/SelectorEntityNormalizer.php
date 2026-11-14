<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Item\OfficeStaffSelection;

/**
 * The codes of the entity selector as the module reads them: a person, a department - with the nested
 * ones or without them - and every employee of the portal.
 *
 * Reading the codes is shared, expanding them is not: the showcase of the selector collects one array of
 * people with a ceiling on it, while a write streams the set. Only the reading lives here.
 *
 * The parse truncates everything past {@see self::MAX_SELECTOR_ENTITIES} without a word. That is right
 * for a showcase and wrong for a write, so a caller that may not lose codes counts the raw ones against
 * the same constant before asking for the parse. {@see self::parseStrict()} is the parse that loses nothing.
 */
class SelectorEntityNormalizer
{
	public const MAX_SELECTOR_ENTITIES = 50;

	private const ALL_EMPLOYEES_TYPE = 'meta-user';
	private const ALL_EMPLOYEES_ID = 'all-users';
	private const FLAT_SUFFIX = ':F';

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
	 * Every code is either taken or reported back, nothing is truncated or skipped. A department of the
	 * selector names a section of the old structure, so it is translated into its node by the access code:
	 * the identifiers of sections and nodes differ. The root of the structure in any form, every-employee
	 * and anything unknown are unsupported, and the caller refuses the whole request on them.
	 */
	public function parseStrict(array $entities): OfficeStaffSelection
	{
		$rootNodeId = $this->getRootNodeId();

		$userIds = [];
		$flatNodeIds = [];
		$deepNodeIds = [];
		$unsupportedCodes = [];
		$rawKeys = [];
		$resolvedKeys = [];

		foreach ($entities as $entity)
		{
			$code = self::describeCode($entity);
			$rawKey = $code['type'] . ':' . $code['id'];
			if (isset($rawKeys[$rawKey]))
			{
				continue;
			}

			$rawKeys[$rawKey] = true;

			$resolved = $this->resolveStrictEntity($entity, $rootNodeId);
			if ($resolved === null)
			{
				$unsupportedCodes[] = $code;

				continue;
			}

			[$kind, $targetId] = $resolved;
			$resolvedKey = $kind . ':' . $targetId;
			if (isset($resolvedKeys[$resolvedKey]))
			{
				continue;
			}

			$resolvedKeys[$resolvedKey] = true;

			if ($kind === 'user')
			{
				$userIds[] = $targetId;
			}
			elseif ($kind === 'flat')
			{
				$flatNodeIds[] = $targetId;
			}
			else
			{
				$deepNodeIds[] = $targetId;
			}
		}

		return new OfficeStaffSelection($userIds, $flatNodeIds, $deepNodeIds, $unsupportedCodes);
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

	protected function getRootNodeId(): ?int
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		$rootNodeId = Container::getNodeService()->getRootNode()?->id;

		return $rootNodeId > 0 ? $rootNodeId : null;
	}

	protected function findNodeIdByDepartmentId(int $departmentId): ?int
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		$accessCode = DepartmentBackwardAccessCode::makeById($departmentId);
		$nodeId = Container::getNodeService()->getByAccessCode($accessCode)?->id;

		return $nodeId > 0 ? $nodeId : null;
	}

	/**
	 * @return array{0: 'user'|'flat'|'deep', 1: int}|null
	 */
	private function resolveStrictEntity(mixed $entity, ?int $rootNodeId): ?array
	{
		if (!is_array($entity))
		{
			return null;
		}

		$type = $entity['type'] ?? '';
		$id = $entity['id'] ?? '';

		if ($type === 'user')
		{
			$userId = self::normalizePositiveInt($id);

			return $userId === null ? null : ['user', $userId];
		}

		if ($type !== 'structure-node' && $type !== 'department')
		{
			return null;
		}

		$isFlat = is_string($id) && str_ends_with($id, self::FLAT_SUFFIX);
		$targetId = self::normalizePositiveInt($isFlat ? substr($id, 0, -strlen(self::FLAT_SUFFIX)) : $id);
		if ($targetId === null)
		{
			return null;
		}

		$nodeId = $type === 'department' ? $this->findNodeIdByDepartmentId($targetId) : $targetId;
		if ($nodeId === null || $nodeId === $rootNodeId)
		{
			return null;
		}

		return [$isFlat ? 'flat' : 'deep', $nodeId];
	}

	/**
	 * @return array{type: string, id: int|string}
	 */
	private static function describeCode(mixed $entity): array
	{
		$type = is_array($entity) ? ($entity['type'] ?? '') : '';
		$id = is_array($entity) ? ($entity['id'] ?? '') : '';

		return [
			'type' => is_string($type) ? $type : '',
			'id' => is_int($id) || is_string($id) ? $id : '',
		];
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
