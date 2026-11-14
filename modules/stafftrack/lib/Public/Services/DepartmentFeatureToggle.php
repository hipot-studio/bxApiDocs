<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

class DepartmentFeatureToggle
{
	private const CACHE_TTL = 3600;
	private const CACHE_DIR = '/stafftrack/department_feature_toggle';
	private const USER_DEPTS_CACHE_DIR = '/stafftrack/department_feature_toggle/user_departments';
	private const USER_DEPTS_CACHE_TAG = 'stafftrack_department_feature_toggle';
	private const HR_STRUCTURE_CACHE_TAG = 'intranet_department_structure';
	private const USER_CHUNK_SIZE = 500;

	/**
	 * The store answers one employee per read, while the structure answers a whole chunk of
	 * USER_CHUNK_SIZE per query. One read is cheaper than one query, but nowhere near five hundred times
	 * cheaper, so the store pays off only while the list is short - around ten employees is where the
	 * round trips it saves start costing more than the single query they replace. Above that the list
	 * goes to the structure as it did before the store existed. The path the store was introduced for
	 * asks about one employee: the verdict of availability on every page of the portal and every start
	 * of the application.
	 */
	private const STORED_LOOKUP_MAX_USERS = 10;

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
		$this->invalidateUserDepartmentsCache();
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function disableForCompany(string $moduleId, string $companyOptionKey): void
	{
		Option::set($moduleId, $companyOptionKey, 'N');
		$this->invalidateUserDepartmentsCache();
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
		$this->invalidateUserDepartmentsCache();
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
		$storeIsWorthReading = count($missingIds) <= self::STORED_LOOKUP_MAX_USERS;

		if ($storeIsWorthReading)
		{
			$missingIds = $this->takeStoredDepartments($missingIds);
		}

		$structureIsAvailable = Loader::includeModule('humanresources');
		foreach (array_chunk($missingIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			$loaded = $this->loadDepartmentIdsByUsers($chunk);
			self::$userDeptsCache += $loaded;

			// a long list writes nothing for the same reason it read nothing. and without the structure
			// the load answers "no departments" for everybody, which is an answer of the moment, not of
			// the employee: storing it would keep the whole portal outside the rollout until the ttl runs out
			if (!$storeIsWorthReading || !$structureIsAvailable)
			{
				continue;
			}

			foreach ($loaded as $userId => $departmentIds)
			{
				$this->writeUserDepartments($userId, $departmentIds);
			}
		}

		return array_intersect_key(self::$userDeptsCache, array_flip($userIds));
	}

	/**
	 * @param int[] $userIds
	 * @return int[] The ones the store knows nothing about.
	 */
	private function takeStoredDepartments(array $userIds): array
	{
		$missingIds = [];
		foreach ($userIds as $userId)
		{
			$stored = $this->readUserDepartments($userId);
			if ($stored === null)
			{
				$missingIds[] = $userId;

				continue;
			}

			self::$userDeptsCache[$userId] = $stored;
		}

		return $missingIds;
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

	/**
	 * Membership is asked on every page of the portal and on every start of the mobile application, so
	 * the answer outlives the request: humanresources clears the structure tag it is registered under on
	 * any change of a node or of a membership.
	 *
	 * @return int[]|null Null when nothing is stored. An employee outside every department is a
	 *                    legitimate empty list and has to stay apart from a miss.
	 */
	private function readUserDepartments(int $userId): ?array
	{
		$cache = Cache::createInstance();

		if (!$cache->initCache(self::CACHE_TTL, $this->getUserDeptsCacheId($userId), self::USER_DEPTS_CACHE_DIR))
		{
			return null;
		}

		$stored = $cache->getVars();

		return is_array($stored) && isset($stored['departmentIds']) && is_array($stored['departmentIds'])
			? $stored['departmentIds']
			: null
		;
	}

	/**
	 * @param int[] $departmentIds
	 */
	private function writeUserDepartments(int $userId, array $departmentIds): void
	{
		$cache = Cache::createInstance();
		$cache->noOutput();

		if (!$cache->startDataCache(self::CACHE_TTL, $this->getUserDeptsCacheId($userId), self::USER_DEPTS_CACHE_DIR))
		{
			return;
		}

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache(self::USER_DEPTS_CACHE_DIR);
		$taggedCache->registerTag(self::HR_STRUCTURE_CACHE_TAG);
		$taggedCache->registerTag(self::USER_DEPTS_CACHE_TAG);
		$taggedCache->endTagCache();

		$cache->endDataCache(['departmentIds' => $departmentIds]);
	}

	private function getUserDeptsCacheId(int $userId): string
	{
		return 'stafftrack.dft.user_departments.v1.' . $userId;
	}

	private function invalidateSubtreeCache(string $deptsOptionKey): void
	{
		unset(self::$subtreeCache[$deptsOptionKey]);
	}

	/**
	 * A change of the rollout is the moment somebody looks at the verdict this membership is read for, so
	 * the stored copy is dropped with it instead of standing until the tag of the structure or the ttl
	 * clears it. Rare enough to cost nothing: the same change already clears the menu cache of the portal.
	 */
	private function invalidateUserDepartmentsCache(): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::USER_DEPTS_CACHE_TAG);
	}

}
