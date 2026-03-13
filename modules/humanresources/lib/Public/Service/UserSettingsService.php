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
	 * Get nodes that should participate in business processes for the given user.
	 * These are nodes that user is a part of, except for nodes that are
	 * in UserSettingsType::BusinessProcExcludeNodes settings values
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

		$excludedNodeIds = $entityCollection->map(static fn($entity) => (int)$entity->settingsValue);

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
				->getAll()
		;

		return $nodeMemberCollection->getNodeIds();
	}
}
