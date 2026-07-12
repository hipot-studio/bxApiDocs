<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Humanresources;

use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\NodeRelation;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Main\Event;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper\DepartmentRelationMapper;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ProjectChatAncestorResolver;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;

/**
 * Handles events from the humanresources module.
 * Method names are corresponding to the event names.
 */
class EventHandler
{
	public static function OnRelationAdded(Event $event): void
	{
		/** @var NodeRelation $relation */
		$relation = $event->getParameter('relation');
		if ($relation->node === null)
		{
			return;
		}

		if ($relation->entityType === RelationEntityType::CHAT)
		{
			self::synchronizeRelationFromChatToProject($relation);

			return;
		}

		if ($relation->entityType !== RelationEntityType::COLLAB)
		{
			return;
		}

		Container::getInstance()
			->getStructureSyncService()
			->enqueueRelationAdded(
				nodeId: $relation->nodeId,
				entityId: $relation->entityId,
				createdBy: $relation->createdBy,
				withChildNodes: $relation->withChildNodes,
			)
		;
	}

	private static function synchronizeRelationFromChatToProject(NodeRelation $relation): void
	{
		$container = Container::getInstance();

		$collabId = $container->get(ProjectChatAncestorResolver::class)->getProjectIdByChatId($relation->entityId);
		if ($collabId === null)
		{
			return;
		}

		$department = $container->get(DepartmentRelationMapper::class)->map($relation);
		if ($department === null)
		{
			return;
		}

		$accessCode = $container->get(MemberEntityMapper::class)->toAccessCode($department);
		if ($accessCode === null)
		{
			return;
		}

		$container->getStructureRelationService()->linkDepartments([$accessCode], $collabId);
	}

	public static function OnMemberAdded(Event $event): void
	{
		/** @var NodeMember $member */
		$member = $event->getParameter('member');
		if ($member->entityType !== MemberEntityType::USER)
		{
			return;
		}

		Container::getInstance()
			->getStructureSyncService()
			->handleMemberAdded(nodeId: $member->nodeId, userId: $member->entityId)
		;
	}

	public static function OnRelationDeleted(Event $event): void
	{
		/** @var NodeRelation $relation */
		$relation = $event->getParameter('relation');
		if (
			$relation->entityType !== RelationEntityType::COLLAB
			|| $relation->node === null
		)
		{
			return;
		}

		Container::getInstance()
			->getStructureSyncService()
			->enqueueRelationDeleted(
				nodeId: $relation->nodeId,
				entityId: $relation->entityId,
				createdBy: $relation->createdBy,
				withChildNodes: $relation->withChildNodes,
			)
		;

		self::unlinkRelatedChatRelations($relation);
	}

	private static function unlinkRelatedChatRelations(NodeRelation $relation): void
	{
		$container = Container::getInstance();

		$structureRelationService = $container->getStructureRelationService();
		$resolver = $container->get(ProjectChatAncestorResolver::class);

		$collabId = $relation->entityId;
		$nodeId = $relation->nodeId;

		$chatIdsToUnlink = [];
		foreach ($structureRelationService->getChatRelations($nodeId) as $chatId)
		{
			if ($resolver->getProjectIdByChatId($chatId) !== $collabId)
			{
				continue;
			}

			$chatIdsToUnlink[] = $chatId;
		}

		$structureRelationService->unlinkChatRelations($nodeId, $chatIdsToUnlink);
	}

	public static function OnMemberDeleted(Event $event): void
	{
		/** @var NodeMember $member */
		$member = $event->getParameter('member');
		if ($member->entityType !== MemberEntityType::USER)
		{
			return;
		}

		Container::getInstance()
			->getStructureSyncService()
			->handleMemberDeleted(nodeId: $member->nodeId, userId: $member->entityId)
		;
	}

	public static function onMemberUpdated(Event $event): void
	{
		/** @var NodeMember $member */
		$member = $event->getParameter('member');
		/** @var NodeMember|null $previousMember */
		$previousMember = $event->getParameter('previousMember');

		if ($previousMember === null
			|| $member->entityType !== MemberEntityType::USER
			|| $previousMember->entityType !== MemberEntityType::USER
			|| $member->nodeId === $previousMember->nodeId
		)
		{
			return;
		}

		// Order matters: add first, then delete. The delete handler checks getUsersNotInOtherRelations()
		// and must see the new relation created by add — otherwise the user would briefly lose access.
		$service = Container::getInstance()->getStructureSyncService();
		$service->handleMemberAdded(nodeId: $member->nodeId, userId: $member->entityId);
		$service->handleMemberDeleted(nodeId: $previousMember->nodeId, userId: $previousMember->entityId);
	}
}
