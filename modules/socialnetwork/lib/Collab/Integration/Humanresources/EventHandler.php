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
use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper\DepartmentRelationMapper;
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
 *
 * The handler announces nothing by itself: a linked or unlinked node goes into the ActionMessageBuffer, so
 * that one membership edit gives one message listing everyone it added (people, departments and teams),
 * no matter whether the edit came from the project form or from the org structure.
 */
class EventHandler
{
	/**
	 * Unlinking a node is announced impersonally, and $relation->createdBy holds the author of the link,
	 * not the one removing it: the summary of a removal names nobody as its author.
	 */
	private const NO_SENDER = 0;

	private static bool $isBufferFlushScheduled = false;

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

		self::bufferStructureNode($relation, ActionType::AddUser, (int)$relation->createdBy);

		self::holdSummaryWhile(static function () use ($relation): void {
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
		});
	}

	/**
	 * Brings the members of the node into the project - or takes them out of it - while the buffer waits. That
	 * part of the edit ends in a nested project edit (im mirrors the chat membership back onto the project),
	 * and the nested edit flushes the buffer on its way out: a save that links two nodes with people in them
	 * would announce each of them separately, and the people picked by hand a third time. The node itself is
	 * already in the buffer by then, so even a flush that escapes the hold names it together with the rest.
	 *
	 * @param callable(): void $syncNodeMembers
	 */
	private static function holdSummaryWhile(callable $syncNodeMembers): void
	{
		self::scheduleBufferFlush();

		$buffer = ActionMessageBuffer::getInstance();
		$buffer->holdFlush();

		try
		{
			$syncNodeMembers();
		}
		finally
		{
			$buffer->releaseFlush();
		}
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

		$container->getStructureRelationService()->linkNodes([$accessCode], $collabId);
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

		self::bufferStructureNode($relation, ActionType::ExcludeStructureNode, self::NO_SENDER);

		self::holdSummaryWhile(static function () use ($relation): void {
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
		});
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
			->unlinkChatRelations(
				(int)$relation->nodeId,
				[$collabChatId],
				(bool)$relation->withChildNodes,
			)
		;
	}

	/**
	 * Puts the linked or unlinked node into the buffer instead of announcing it right away. The buffer keeps
	 * the project-phrased wording on the socialnetwork side (im's chat-phrased finish message is suppressed
	 * for CollabChat) and merges the node with whatever else the same edit changed.
	 *
	 * A node without a name is dropped by the buffer, so a project keeps its silence exactly as before.
	 *
	 * A department or a team attached while the project is being created says nothing of itself either: the
	 * creation card names it among the members, and the buffer record is kept only for what it does to the chat.
	 */
	private static function bufferStructureNode(NodeRelation $relation, ActionType $action, int $senderId): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$collabId = (int)$relation->entityId;

		$collabChatId = GroupRegistry::getInstance()->get($collabId)?->getChatId() ?? 0;
		if ($collabChatId <= 0)
		{
			return;
		}

		$node = Container::getInstance()->get(DepartmentRelationMapper::class)->map($relation);
		if ($node === null)
		{
			return;
		}

		$buffer = ActionMessageBuffer::getInstance();
		$parameters = $buffer->areMemberMessagesSuppressed($collabId)
			? [ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER => true]
			: []
		;

		$buffer->put($action, $collabId, $senderId, parameters: $parameters, nodes: [$node]);
	}

	/**
	 * A membership edit made in the project flushes the buffer itself (CollabService), but a node linked from
	 * the org structure has no such pipeline, so the summary is published at the end of the request. Both may
	 * happen within one request: the flush empties the buffer, so the later one finds nothing to publish.
	 */
	private static function scheduleBufferFlush(): void
	{
		if (self::$isBufferFlushScheduled)
		{
			return;
		}

		self::$isBufferFlushScheduled = true;

		Application::getInstance()->addBackgroundJob(self::publishBufferedSummary(...));
	}

	/**
	 * Publishes whatever the buffer still holds at the end of the request. The sign of a scheduled publication
	 * is dropped first: a process that outlives one request - a queue consumer, a console script - edits the
	 * composition again, and that edit has to be able to schedule a publication of its own.
	 */
	private static function publishBufferedSummary(): void
	{
		self::$isBufferFlushScheduled = false;

		ActionMessageBuffer::getInstance()->flush();
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
