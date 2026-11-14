<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources\Service;

use Bitrix\HumanResources;
use Bitrix\HumanResources\Public\Service\NodeMemberService;
use Bitrix\HumanResources\Public\Service\NodeSettingsService;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

/**
 * Whether the departments of each employee collect check-ins, for a whole team in two queries: the per-user
 * NodeSettingsService walks the structure once per employee. As there, a department collects them when its auto
 * check-in is on: a check-in it demands at the start of the day asks for no routes and does not count. A failure
 * leaves the employees untrusted.
 */
class NodeSettingsBatchService
{
	/**
	 * @param int[] $userIds
	 * @return array<int, array{trusted: bool, collects: bool}>
	 */
	public function isCheckInCollectedForUsers(array $userIds): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		try
		{
			// without the structure no department collects check-ins, as in NodeSettingsService
			if (!$this->isHumanResourcesAvailable())
			{
				return self::nobodyCollects($userIds);
			}

			$nodeIdsByUserId = $this->readDepartmentsOfUsers($userIds);
			$collectingNodeIds = $this->readCollectingNodeIds(self::collectNodeIds($nodeIdsByUserId));
		}
		catch (\Throwable)
		{
			return self::untrusted($userIds);
		}

		$collected = [];
		foreach ($userIds as $userId)
		{
			$collected[$userId] = [
				'trusted' => true,
				'collects' => self::anyNodeCollects($nodeIdsByUserId[$userId] ?? [], $collectingNodeIds),
			];
		}

		return $collected;
	}

	/**
	 * The departments of every employee off one query.
	 *
	 * @param int[] $userIds
	 * @return array<int, int[]>
	 */
	protected function readDepartmentsOfUsers(array $userIds): array
	{
		$nodeIdsByUserId = array_fill_keys($userIds, []);

		$members = $this->getMemberService()->findAllByEntityIds(
			entityIds: $userIds,
			memberEntityType: MemberEntityType::USER,
			nodeTypes: [NodeEntityType::DEPARTMENT],
		);

		foreach ($members as $member)
		{
			$entityId = (int)$member->entityId;
			if (isset($nodeIdsByUserId[$entityId]))
			{
				$nodeIdsByUserId[$entityId][] = (int)$member->nodeId;
			}
		}

		return $nodeIdsByUserId;
	}

	/**
	 * The departments with the auto check-in on, all of them read by one query.
	 *
	 * @param int[] $nodeIds
	 * @return array<int, true>
	 */
	protected function readCollectingNodeIds(array $nodeIds): array
	{
		if ($nodeIds === [])
		{
			return [];
		}

		return self::switchedOnNodeIds($this->getSettingsService()->getAutoCheckinSettingsForNodes($nodeIds));
	}

	protected function isHumanResourcesAvailable(): bool
	{
		return Loader::includeModule('humanresources');
	}

	private function getMemberService(): NodeMemberService
	{
		$service = Loader::includeModule('humanresources')
			? HumanResources\Public\Service\Container::getNodeMemberService()
			: null
		;

		if ($service === null)
		{
			throw new SystemException('humanresources node member service is not available');
		}

		return $service;
	}

	private function getSettingsService(): NodeSettingsService
	{
		$service = Loader::includeModule('humanresources')
			? HumanResources\Public\Service\Container::getNodeSettingsService()
			: null
		;

		if ($service === null)
		{
			throw new SystemException('humanresources node settings service is not available');
		}

		return $service;
	}

	/**
	 * @param array<int, ?bool> $flagByNodeId
	 * @return array<int, true>
	 */
	private static function switchedOnNodeIds(array $flagByNodeId): array
	{
		$nodeIds = [];
		foreach ($flagByNodeId as $nodeId => $flag)
		{
			if ($flag === true)
			{
				$nodeIds[(int)$nodeId] = true;
			}
		}

		return $nodeIds;
	}

	/**
	 * @param int[] $nodeIds
	 * @param array<int, true> $collectingNodeIds
	 */
	private static function anyNodeCollects(array $nodeIds, array $collectingNodeIds): bool
	{
		foreach ($nodeIds as $nodeId)
		{
			if (isset($collectingNodeIds[$nodeId]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, int[]> $nodeIdsByUserId
	 * @return int[]
	 */
	private static function collectNodeIds(array $nodeIdsByUserId): array
	{
		$nodeIds = [];
		foreach ($nodeIdsByUserId as $userNodeIds)
		{
			foreach ($userNodeIds as $nodeId)
			{
				$nodeIds[$nodeId] = true;
			}
		}

		return array_keys($nodeIds);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array{trusted: bool, collects: bool}>
	 */
	private static function untrusted(array $userIds): array
	{
		return array_fill_keys($userIds, ['trusted' => false, 'collects' => false]);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array{trusted: bool, collects: bool}>
	 */
	private static function nobodyCollects(array $userIds): array
	{
		return array_fill_keys($userIds, ['trusted' => true, 'collects' => false]);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private static function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
