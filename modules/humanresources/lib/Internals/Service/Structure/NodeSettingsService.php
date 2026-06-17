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
use Bitrix\Main\Application;
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

	/**
	 * Upsert a single boolean setting for the given node, bypassing SaveNodeSettingsCommand.
	 * Intended for types marked as public-API-only ({@see NodeSettingsType::isPublicApiOnly()}).
	 * Caller is responsible for passing a boolean setting type.
	 */
	public function setBooleanSetting(int $nodeId, NodeSettingsType $type, bool $value): void
	{
		$this->nodeSettingsRepository->removeByTypeAndNodeId($nodeId, $type);
		$this->nodeSettingsRepository->create(
			new NodeSettings(
				nodeId: $nodeId,
				settingsType: $type,
				settingsValue: NodeSettingsType::booleanToString($value),
			),
		);
	}

	/**
	 * Upsert a boolean setting for multiple nodes in two SQL statements (one DELETE + one bulk INSERT)
	 * wrapped in a single transaction. Caller is responsible for passing a boolean setting type.
	 *
	 * @param int[] $nodeIds
	 * @param NodeSettingsType $type
	 * @param bool $value
	 */
	public function setBooleanSettingForNodes(array $nodeIds, NodeSettingsType $type, bool $value): void
	{
		if (empty($nodeIds))
		{
			return;
		}

		$stringValue = NodeSettingsType::booleanToString($value);
		$collection = new NodeSettingsCollection();
		foreach (array_unique($nodeIds) as $nodeId)
		{
			$collection->add(new NodeSettings(
				nodeId: $nodeId,
				settingsType: $type,
				settingsValue: $stringValue,
			));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->nodeSettingsRepository->removeByTypeAndNodeIds($nodeIds, $type);
			$this->nodeSettingsRepository->createBatch($collection);
			$connection->commitTransaction();
		}
		catch (\Exception $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}
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