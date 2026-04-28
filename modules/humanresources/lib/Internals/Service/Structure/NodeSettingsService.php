<?php

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Contract\Repository\NodeMemberRepository;
use Bitrix\HumanResources\Item\Collection\NodeSettingsCollection;
use Bitrix\HumanResources\Item\NodeSettings;
use Bitrix\HumanResources\Repository\NodeSettingsRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Result;

class NodeSettingsService
{
	private NodeSettingsRepository $nodeSettingsRepository;
	private NodeMemberRepository $nodeMemberRepository;

	public function __construct(?NodeSettingsRepository $nodeSettingsRepository = null)
	{
		$this->nodeSettingsRepository = $nodeSettingsRepository ?? Container::getNodeSettingsRepository();
		$this->nodeMemberRepository = Container::getNodeMemberRepository();
	}

	public function save(int $nodeId, array $settingsMap): Result
	{
		$settingsCollection = new NodeSettingsCollection();
		$typesToDelete = [];

		foreach ($settingsMap as $type => $settings)
		{
			$settingsType = NodeSettingsType::from($type);

			if ($settings['replace'] ?? false)
			{
				$typesToDelete[] = $settingsType;
			}

			if ($settingsType->isAuthorityType())
			{
				foreach ($settings['values'] ?? [] as $value)
				{
					$settingsCollection->add(
						new NodeSettings(
							$nodeId,
							$settingsType,
							$value,
						),
					);
				}
			}
			else if ($settingsType->isUserIdsType() && isset($settings['values']) && is_array($settings['values']))
			{
				// check if values contain userIds of this node employees
				$nodeUserIds = InternalContainer::getNodeMemberRepository()
					->findAllByEntityIds(
						entityIds: $settings['values'],
						nodeIds: [$nodeId],
						nodeTypes: [NodeEntityType::TEAM, NodeEntityType::DEPARTMENT]
					)
					->getEntityIds()
				;

				foreach ($nodeUserIds as $nodeUserId)
				{
					$settingsCollection->add(
						new NodeSettings(
							$nodeId,
							$settingsType,
							$nodeUserId,
						),
					);
				}
			}
			else if ($settingsType->isBooleanType())
			{
				$settingsCollection->add(
					new NodeSettings(
						$nodeId,
						$settingsType,
						$settings['value'],
					),
				);
			}
		}

		$this->nodeSettingsRepository->removeByTypeAndNodeId($nodeId, $typesToDelete);
		$this->nodeSettingsRepository->createByCollection($settingsCollection);

		return new Result();
	}

	public function deleteByNodeMemberId(int $nodeMemberId): void
	{
		$nodeMember = $this->nodeMemberRepository->findById($nodeMemberId);

		if (isset($nodeMember) && $nodeMember->entityType === MemberEntityType::USER)
		{
			$this->nodeSettingsRepository->removeByTypeAndNodeId(
				$nodeMember->nodeId,
				NodeSettingsType::getCasesWithUserIdsValue(),
				$nodeMember->entityId,
			);
		}
	}
}