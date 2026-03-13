<?php

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Contract\Repository\NodeMemberRepository;
use Bitrix\HumanResources\Internals\Repository\Structure\UserSettingsRepository;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\UserSettingsCollection;
use Bitrix\HumanResources\Item\UserSettings;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\UserSettingsType;
use Bitrix\Main\Result;

class UserSettingsService
{
	private UserSettingsRepository $userSettingsRepository;
	private NodeMemberRepository $nodeMemberRepository;

	public function __construct()
	{
		$this->userSettingsRepository = InternalContainer::getUserSettingsRepository();
		$this->nodeMemberRepository = Container::getNodeMemberRepository();
	}

	public function save(int $userId, array $settingsMap): Result
	{
		$nodeIds = (new NodeMemberDataBuilder)
			->addFilter(
				new NodeMemberFilter(
					entityIdFilter: EntityIdFilter::fromEntityId($userId),
				),
			)
			->getAll()
			->getNodeIds()
		;

		foreach ($settingsMap as $type => $settings)
		{
			$settingsCollection = new UserSettingsCollection();

			if ($settings['replace'] ?? false)
			{
				$this->userSettingsRepository->removeByTypeAndUserId(
					$userId,
					UserSettingsType::from($type),
				);
			}

			foreach ($settings['values'] ?? [] as $value)
			{
				// filter out nodes that the user is not a member of
				if (!in_array((int)$value, $nodeIds))
				{
					continue;
				}

				$settingsCollection->add(
					new UserSettings(
						$userId,
						UserSettingsType::from($type),
						$value,
					),
				);
			}

			$this->userSettingsRepository->createByCollection($settingsCollection);
		}

		return new Result();
	}

	public function deleteByNodeMemberId(int $nodeMemberId): void
	{
		$nodeMember = $this->nodeMemberRepository->findById($nodeMemberId);

		if ($nodeMember->entityType === MemberEntityType::USER)
		{
			$this->userSettingsRepository->removeByUserIdAndNodeId(
				$nodeMember->entityId,
				$nodeMember->nodeId,
			);
		}
	}
}
