<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Feature;

class SubordinateResolver
{
	private DeputyStructureGateway $gateway;

	/** @var array<int, array<string, int[]>> */
	private array $memo = [];

	public function __construct(?DeputyStructureGateway $gateway = null)
	{
		$this->gateway = $gateway ?? new DeputyStructureGateway();
	}

	/**
	 * @return int[]
	 */
	public function resolve(int $userId, bool $direct): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$isDeputyAccessEnabled = Feature::isCheckInDeputyAccessEnabled();

		$memoKey = (int)$direct . ':' . (int)$isDeputyAccessEnabled;
		if (isset($this->memo[$userId][$memoKey]))
		{
			return $this->memo[$userId][$memoKey];
		}

		$userIds = HrPublicContainer::getUserDepartmentService()->getSubordinateUserIds($userId, $direct);

		if ($isDeputyAccessEnabled)
		{
			$userIds = [...$userIds, ...$this->resolveDeputyScope($userId, $direct)];
		}

		$userIds = array_values(
			array_filter(
				IdNormalizer::normalize($userIds),
				static fn (int $id) => $id !== $userId,
			),
		);

		$this->memo[$userId][$memoKey] = $userIds;

		return $userIds;
	}

	/**
	 * @return int[]
	 */
	private function resolveDeputyScope(int $userId, bool $direct): array
	{
		$deputyNodeIds = $this->gateway->getDepartmentIdsWhereUserIsDeputy($userId);
		if (empty($deputyNodeIds))
		{
			return [];
		}

		if (!$direct)
		{
			return $this->gateway->getMemberIds($deputyNodeIds, [], DepthLevel::FULL);
		}

		return [
			...$this->gateway->getMemberIds(
				$deputyNodeIds,
				[NodeMemberRole::Head, NodeMemberRole::DeputyHead, NodeMemberRole::Employee],
				DepthLevel::NONE,
			),
			...$this->gateway->getMemberIds($deputyNodeIds, [NodeMemberRole::Head], DepthLevel::FIRST),
		];
	}
}
