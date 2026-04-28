<?php

namespace Bitrix\HumanResources\Public\Service;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\ConditionMode;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Internals\Repository\Structure\UserSettingsRepository;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\UserSettingsType;

class UserSettingsService
{
	private UserSettingsRepository $userSettingsRepository;

	public function __construct()
	{
		$this->userSettingsRepository = InternalContainer::getUserSettingsRepository();
	}

	/**
	 * Get nodes whose heads participate in business processes for the given user.
	 *
	 * Relevant for concurrent employment: when a user belongs to multiple departments,
	 * the structure administrator can exclude specific nodes to avoid dual subordination
	 * in business process approvals.
	 *
	 * Returns all user's department nodes minus those listed in BusinessProcExcludeNodes.
	 *
	 * @param int $userId
	 * @return array<int>
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getBusinessProcAuthoritySettings(int $userId): array
	{
		$entityCollection = $this->userSettingsRepository->getByUserAndTypes($userId, [
			UserSettingsType::BusinessProcExcludeNodes
		]);

		return $this->getNodeIdsByExcludedIds($userId, $entityCollection->getIntSettingsValues());
	}

	/**
	 * Get nodes whose heads receive reports from the given user.
	 *
	 * Relevant for concurrent employment: when a user belongs to multiple departments,
	 * the structure administrator can exclude specific nodes to avoid dual subordination
	 * in reports approval.
	 *
	 * Returns all user's department nodes minus those listed in ReportsExcludeNodes.
	 *
	 * @param int $userId
	 * @return int[]
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getReportsAuthoritySettings(int $userId): array
	{
		$entityCollection = $this->userSettingsRepository->getByUserAndTypes($userId, [
			UserSettingsType::ReportsExcludeNodes
		]);

		return $this->getNodeIdsByExcludedIds($userId, $entityCollection->getIntSettingsValues());
	}

	/**
	 * @param int $userId
	 * @param int[] $excludedNodeIds
	 * @return int[]
	 * @throws \Bitrix\Main\ArgumentException
	 */
	private function getNodeIdsByExcludedIds(int $userId, array $excludedNodeIds): array
	{
		$nodeMemberCollection =
			(new NodeMemberDataBuilder())
				->addFilter(
					new NodeMemberFilter(
						entityIdFilter: EntityIdFilter::fromEntityId($userId),
						entityType: MemberEntityType::USER,
						nodeFilter: new NodeFilter(
							idFilter: IdFilter::fromIds($excludedNodeIds, ConditionMode::Exclusion),
							entityTypeFilter: NodeTypeFilter::fromNodeTypes([NodeEntityType::DEPARTMENT]),
							active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
						),
					),
				)
				->getAll();

		return $nodeMemberCollection->getNodeIds();
	}
}
