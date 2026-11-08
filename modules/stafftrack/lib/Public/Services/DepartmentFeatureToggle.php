<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

class DepartmentFeatureToggle
{
	private const CACHE_TTL = 3600;
	private const CACHE_DIR = '/stafftrack/department_feature_toggle';
	private const USER_CHUNK_SIZE = 500;

	/** @var array<string, int[]> */
	private static array $subtreeCache = [];

	/** @var array<int, int[]> */
	private static array $userDeptsCache = [];

	public function isEnabledForUser(
		int $userId,
		string $moduleId,
		string $companyOptionKey,
		string $deptsOptionKey,
	): bool
	{
		$map = $this->filterEnabledForUsers([$userId], $moduleId, $companyOptionKey, $deptsOptionKey);

		return $map[$userId] ?? false;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>
	 */
	public function filterEnabledForUsers(
		array $userIds,
		string $moduleId,
		string $companyOptionKey,
		string $deptsOptionKey,
	): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if (empty($userIds))
		{
			return [];
		}

		if ($this->getCompanyEnabled($moduleId, $companyOptionKey))
		{
			return array_fill_keys($userIds, true);
		}

		$enabledDepts = $this->readEnabledDepartments($moduleId, $deptsOptionKey);
		if (empty($enabledDepts))
		{
			return array_fill_keys($userIds, false);
		}

		$expandedDepts = array_fill_keys($this->getExpandedSubtree($deptsOptionKey, $enabledDepts), true);
		$deptsByUser = $this->getDepartmentIdsByUsers($userIds);

		$map = [];
		foreach ($userIds as $userId)
		{
			$map[$userId] = false;
			foreach ($deptsByUser[$userId] ?? [] as $departmentId)
			{
				if (isset($expandedDepts[$departmentId]))
				{
					$map[$userId] = true;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function enableForCompany(string $moduleId, string $companyOptionKey): void
	{
		Option::set($moduleId, $companyOptionKey, 'Y');
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function disableForCompany(string $moduleId, string $companyOptionKey): void
	{
		Option::set($moduleId, $companyOptionKey, 'N');
	}

	/**
	 * @param int[]  $nodeIds
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function setEnabledDepartments(string $moduleId, string $deptsOptionKey, array $nodeIds): void
	{
		$normalized = array_values(array_unique(array_filter(array_map('intval', $nodeIds), static fn(int $id) => $id > 0)));
		Option::set($moduleId, $deptsOptionKey, json_encode($normalized));
		$this->invalidateSubtreeCache($deptsOptionKey);
	}

	public function getCompanyEnabled(string $moduleId, string $companyOptionKey): bool
	{
		return Option::get($moduleId, $companyOptionKey, 'N') === 'Y';
	}

	/**
	 * @return int[]
	 */
	public function getEnabledDepartments(string $moduleId, string $deptsOptionKey): array
	{
		return $this->readEnabledDepartments($moduleId, $deptsOptionKey);
	}

	/**
	 * @return int[]
	 */
	private function readEnabledDepartments(string $moduleId, string $deptsOptionKey): array
	{
		$raw = Option::get($moduleId, $deptsOptionKey, '[]');
		$decoded = json_decode($raw, true);

		if (!is_array($decoded))
		{
			return [];
		}

		return array_values(array_filter(array_map('intval', $decoded), static fn(int $id) => $id > 0));
	}

	/**
	 * @param int[] $enabledDepts
	 * @return int[]
	 */
	private function getExpandedSubtree(string $deptsOptionKey, array $enabledDepts): array
	{
		if (array_key_exists($deptsOptionKey, self::$subtreeCache))
		{
			return self::$subtreeCache[$deptsOptionKey];
		}

		$cacheId = 'stafftrack.dft.subtree.v1.' . md5($deptsOptionKey . ':' . implode(',', $enabledDepts));
		$cache = Cache::createInstance();

		if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$result = $cache->getVars();
			self::$subtreeCache[$deptsOptionKey] = $result;

			return $result;
		}

		$expanded = $this->expandSubtree($enabledDepts);

		if ($cache->startDataCache())
		{
			try
			{
				$cache->endDataCache($expanded);
			}
			catch (\Throwable $e)
			{
				$cache->abortDataCache();
			}
		}

		self::$subtreeCache[$deptsOptionKey] = $expanded;

		return $expanded;
	}

	/**
	 * @param int[] $nodeIds
	 * @return int[]
	 */
	private function expandSubtree(array $nodeIds): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return $nodeIds;
		}

		$nodeService = HrPublicContainer::getNodeService();
		$childNodes = $nodeService->findChildrenByNodeIds(
			nodeIds: $nodeIds,
			depthLevel: DepthLevel::FULL,
		);

		$resultMap = array_fill_keys($nodeIds, true);
		foreach ($childNodes as $node)
		{
			if ($node->id !== null)
			{
				$resultMap[$node->id] = true;
			}
		}

		return array_keys($resultMap);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int[]>
	 */
	private function getDepartmentIdsByUsers(array $userIds): array
	{
		$missingIds = array_values(array_diff($userIds, array_keys(self::$userDeptsCache)));
		foreach (array_chunk($missingIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			self::$userDeptsCache += $this->loadDepartmentIdsByUsers($chunk);
		}

		return array_intersect_key(self::$userDeptsCache, array_flip($userIds));
	}

	/**
	 * Membership in DEPARTMENT nodes, one query per chunk regardless of the user count.
	 *
	 * @param int[] $userIds
	 * @return array<int, int[]>
	 */
	private function loadDepartmentIdsByUsers(array $userIds): array
	{
		$deptsByUser = array_fill_keys($userIds, []);
		if (!Loader::includeModule('humanresources'))
		{
			return $deptsByUser;
		}

		$members = HrPublicContainer::getNodeMemberService()->findAllByEntityIds(
			entityIds: $userIds,
			memberEntityType: MemberEntityType::USER,
			nodeTypes: [NodeEntityType::DEPARTMENT],
		);

		foreach ($members as $member)
		{
			$deptsByUser[$member->entityId][] = $member->nodeId;
		}

		return $deptsByUser;
	}

	private function invalidateSubtreeCache(string $deptsOptionKey): void
	{
		unset(self::$subtreeCache[$deptsOptionKey]);
	}

}
