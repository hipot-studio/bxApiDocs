<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Humanresources;

use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\NodeRelation;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper\DepartmentRelationMapper;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ChatMessageSender;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ProjectDepartmentMembersAdded;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ProjectDepartmentMembersRemoved;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;

/**
 * Handles events from the humanresources module.
 * Method names are corresponding to the event names.
 *
 * Department↔chat-tree links are owned by the im module. This handler only bridges the project chat
 * (CollabChat) to the project and keeps the COLLAB⟺CHAT invariant in both directions: linking a department
 * to the project (COLLAB relation) mirrors it onto the collab chat as a CHAT relation, a CHAT relation on
 * the collab chat is mirrored back as a COLLAB relation, and removing the project's COLLAB relation removes
 * that CHAT relation off the collab chat. Keeping the CHAT relation in place is what lets im own the chat
 * membership (add now, remove on the eventual COLLAB unlink via its own CHAT-relation sync). The chat tree
 * (child chats) is im's responsibility — see Im\V2\Chat\Tree\ChatTreeDepartmentSynchronizer.
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

		self::linkRelatedChatRelations($relation);

		self::announceProjectDepartment($relation, added: true);
	}

	/**
	 * Up-cascade complement of unlinkRelatedChatRelations: a department linked to the project (COLLAB relation)
	 * is mirrored onto the project chat (CollabChat) as a CHAT relation, so the COLLAB⟺CHAT invariant holds no
	 * matter which side initiated the link. Without it, a department attached through the project (creation or
	 * member edit) leaves the collab chat without a CHAT relation, so on unlink im's CHAT-relation sync never
	 * runs and the department members are dropped from the project but stay in the chat.
	 *
	 * Idempotent: NodeRelationRepository::create() skips an already-linked relation and raises no event, so the
	 * mirrored CHAT relation re-entering OnRelationAdded (and re-linking the already-present COLLAB relation)
	 * terminates without a loop.
	 */
	private static function linkRelatedChatRelations(NodeRelation $relation): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$collabChatId = GroupRegistry::getInstance()->get((int)$relation->entityId)?->getChatId() ?? 0;
		if ($collabChatId <= 0)
		{
			return;
		}

		$chat = Chat::getInstance($collabChatId);
		if (!$chat instanceof CollabChat)
		{
			return;
		}

		$container = Container::getInstance();

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

		(new Structure($chat))->link([$accessCode]);
	}

	private static function synchronizeRelationFromChatToProject(NodeRelation $relation): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		// React only when the department is linked to the project chat itself (CollabChat); the chat tree puts
		// it there. Child-chat links are handled by im, not here.
		$chat = Chat::getInstance((int)$relation->entityId);
		if (!$chat instanceof CollabChat)
		{
			return;
		}

		$collabId = (int)$chat->getEntityId();
		if ($collabId <= 0)
		{
			return;
		}

		$container = Container::getInstance();

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

		self::announceProjectDepartment($relation, added: false);
	}

	private static function unlinkRelatedChatRelations(NodeRelation $relation): void
	{
		$collabChatId = GroupRegistry::getInstance()->get((int)$relation->entityId)?->getChatId() ?? 0;
		if ($collabChatId <= 0)
		{
			return;
		}

		Container::getInstance()
			->getStructureRelationService()
			->unlinkChatRelations((int)$relation->nodeId, [$collabChatId])
		;
	}

	/**
	 * Project-phrased announcement in the collab chat that a department's members joined/left the project.
	 * The "Project" wording lives here (socialnetwork), not in im — im's chat-phrased finish message is
	 * suppressed for CollabChat.
	 */
	private static function announceProjectDepartment(NodeRelation $relation, bool $added): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$collabChatId = GroupRegistry::getInstance()->get((int)$relation->entityId)?->getChatId() ?? 0;
		if ($collabChatId <= 0)
		{
			return;
		}

		$departmentName = (string)(
			\Bitrix\HumanResources\Service\Container::getNodeService()
				->getNodeInformation((int)$relation->nodeId)?->name
			?? ''
		);
		if ($departmentName === '')
		{
			return;
		}

		$messageData = $added
			? new ProjectDepartmentMembersAdded($departmentName)
			: new ProjectDepartmentMembersRemoved($departmentName);

		Container::getInstance()
			->get(ChatMessageSender::class)
			->sendMessage($collabChatId, $messageData)
		;
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
