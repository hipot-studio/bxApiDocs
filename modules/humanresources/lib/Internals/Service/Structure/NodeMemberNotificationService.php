<?php

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class NodeMemberNotificationService
{
	/** @var array<int, string> entityId => formatted name */
	private array $employeeNameCache = [];

	/** @var array<int, int[]> nodeId => manager user IDs, reset per public method call */
	private array $managerCache = [];

	public function __construct() {}

	/**
	 * Structure-change notifications are gated behind a module option,
	 * disabled by default ({@see Feature::areStructureChangeNotificationsAvailable}).
	 * Every public entry point of this service must check it first.
	 */
	private function isEnabled(): bool
	{
		return Feature::instance()->areStructureChangeNotificationsAvailable();
	}

	/**
	 * Preloads employee names for a collection of node members in a single query.
	 */
	public function preloadEmployeeNames(Item\Collection\NodeMemberCollection $collection): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$entityIds = $collection->getEntityIds();
		$missingIds = array_diff($entityIds, array_keys($this->employeeNameCache));

		if (empty($missingIds))
		{
			return;
		}

		$userService = Container::getUserService();
		$users = Container::getUserRepository()->getByIds($missingIds);

		foreach ($users as $user)
		{
			$this->employeeNameCache[$user->id] = $userService->getUserName($user);
		}
	}

	/**
	 * Sends all move-related IM notifications: to the moved user, to the new manager,
	 * to the previous manager, to the head (if deputy was moved), to all members
	 * (if new head was assigned), and to parent node managers (if head changed).
	 */
	public function sendAllMoveMemberNotifications(
		Item\NodeMember $nodeMember,
		Item\Node $sourceNode,
		Item\Node $targetNode,
		int $sourceRoleId,
		?Item\Role $role,
	): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$this->managerCache = [];
		$isHeadOrDeputy = $this->isHeadRole($targetNode, $role)
			|| $this->isDeputyRole($targetNode, $role);

		$this->sendMoveMemberNotification($nodeMember, $sourceNode, $targetNode);
		if (!$isHeadOrDeputy)
		{
			$this->sendMoveMemberNotificationToManager($nodeMember, $targetNode);
		}
		$this->sendMoveMemberNotificationToPreviousManager($nodeMember, $sourceNode, $targetNode, $sourceRoleId);
		$this->sendDeputyMovedNotificationToHead($nodeMember, $sourceNode, $sourceRoleId);
		$this->sendNewHeadOrDeputyNotificationToMembers($nodeMember, $targetNode, $role);
		$this->sendHeadChangedNotificationToParentManager($nodeMember, $targetNode, $role);
		$this->sendHeadLeftNotificationToParentManager($nodeMember, $sourceNode, $sourceRoleId);
		$this->sendDeputyRemovedNotificationToMembers($nodeMember, $sourceNode, $sourceRoleId);
	}

	/**
	 * Sends IM notifications when new members are added to a node:
	 * notifies the node manager about each new employee, and if a HEAD or DEPUTY role
	 * is assigned — notifies all existing members and the parent node manager.
	 */
	public function sendAllAddMemberNotifications(
		Item\Collection\NodeMemberCollection $nodeMemberCollection,
		Item\Node $node,
		?Item\Role $role = null,
	): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$this->managerCache = [];
		$isHeadOrDeputy = $this->isHeadRole($node, $role)
			|| $this->isDeputyRole($node, $role);

		foreach ($nodeMemberCollection as $nodeMember)
		{
			$this->sendAddedMemberNotification($nodeMember, $node);
			if (!$isHeadOrDeputy)
			{
				$this->sendMoveMemberNotificationToManager($nodeMember, $node);
			}
			$this->sendNewHeadOrDeputyNotificationToMembers($nodeMember, $node, $role);
			$this->sendHeadChangedNotificationToParentManager($nodeMember, $node, $role);
		}
	}

	/**
	 * Sends IM notifications when a member is removed from a node:
	 * notifies the removed user, the node manager, and if the removed member
	 * was a HEAD — notifies all remaining members and the parent node manager.
	 * If the removed member was a DEPUTY — notifies the HEAD and all members.
	 */
	public function sendAllRemoveMemberNotifications(
		Item\NodeMember $nodeMember,
		Item\Node $node,
	): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$this->managerCache = [];
		$sourceRoleId = (int)($nodeMember->roles[0] ?? 0);

		$this->sendRemovedMemberNotification($nodeMember, $node);
		$this->sendRemovedMemberNotificationToManager($nodeMember, $node);
		$this->sendHeadRemovedNotificationToMembers($nodeMember, $node, $sourceRoleId);
		$this->sendDeputyRemovedNotificationToMembers($nodeMember, $node, $sourceRoleId);
		$this->sendDeputyMovedNotificationToHead($nodeMember, $node, $sourceRoleId);
		$this->sendHeadLeftNotificationToParentManager($nodeMember, $node, $sourceRoleId);
	}

	/**
	 * Sends IM notifications when members' roles are changed within a node:
	 * notifies the member about the role change, and handles head/deputy
	 * assignment and removal notifications for other members and parent managers.
	 *
	 * @param array<array{member: Item\NodeMember, oldRoleId: int, newRole: Item\Role}> $roleChanges
	 */
	public function sendAllRoleChangedNotifications(
		array $roleChanges,
		Item\Node $node,
	): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$this->managerCache = [];
		foreach ($roleChanges as $change)
		{
			$nodeMember = $change['member'];
			$oldRoleId = $change['oldRoleId'];
			$newRole = $change['newRole'];

			$this->sendNewHeadOrDeputyNotificationToMembers($nodeMember, $node, $newRole);
			$this->sendHeadChangedNotificationToParentManager($nodeMember, $node, $newRole);
			$this->sendHeadRemovedNotificationToMembers($nodeMember, $node, $oldRoleId);
			$this->sendDeputyRemovedNotificationToMembers($nodeMember, $node, $oldRoleId);
			$this->sendHeadLeftNotificationToParentManager($nodeMember, $node, $oldRoleId);
			$this->sendDeputyMovedNotificationToHead($nodeMember, $node, $oldRoleId);
		}
	}

	/**
	 * Sends IM notifications after a department has been removed and all its members
	 * have been moved to the parent department:
	 *  - to each moved user that is not a parent department manager: a personal
	 *    "department X was removed, you've been moved to <parent>" message;
	 *  - to every parent department manager (HEAD/DEPUTY): a single aggregated
	 *    "N employees from the removed department X were moved to your department"
	 *    message. Parent managers do not also get the personal notification —
	 *    the aggregated one takes precedence for them.
	 *
	 * Only DEPARTMENT removal is supported — TEAM removal does not trigger member
	 * relocation in StructureWalkerService::removeNode.
	 *
	 * @param int[] $movedUserIds User IDs taken as a snapshot before the move;
	 *                            only USER members of the removed department.
	 */
	public function sendAllNodeRemovedNotifications(
		Item\Node $removedNode,
		Item\Node $parentNode,
		array $movedUserIds,
	): void
	{
		if (
			!$this->isEnabled()
			|| empty($movedUserIds)
			|| $removedNode->type !== NodeEntityType::DEPARTMENT
			|| !Loader::includeModule('im')
		)
		{
			return;
		}

		$this->managerCache = [];

		$parentManagerUserIds = $this->findNodeManagerUserIds($parentNode);
		$parentManagerLookup = array_flip($parentManagerUserIds);

		$personalReplacements = [
			'#REMOVED_NAME#' => $removedNode->name,
			'#PARENT_NAME#' => $parentNode->name,
		];

		foreach ($movedUserIds as $userId)
		{
			if (isset($parentManagerLookup[$userId]))
			{
				continue;
			}

			$this->sendImNotification(
				$userId,
				'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NODE_REMOVED_MOVED_TO_DEPARTMENT',
				$personalReplacements,
			);
		}

		if (empty($parentManagerUserIds))
		{
			return;
		}

		$batchReplacements = [
			'#REMOVED_NAME#' => $removedNode->name,
			'#COUNT#' => count($movedUserIds),
		];

		$movedCount = count($movedUserIds);
		foreach ($parentManagerUserIds as $managerUserId)
		{
			$this->sendImNotification(
				$managerUserId,
				'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NODE_REMOVED_BATCH_TO_PARENT_MANAGER',
				$batchReplacements,
				$movedCount,
			);
		}
	}

	/**
	 * Sends an IM notification to the moved user informing them about the transfer
	 * from the source node to the target node.
	 */
	private function sendMoveMemberNotification(
		Item\NodeMember $nodeMember,
		Item\Node $sourceNode,
		Item\Node $targetNode,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$messageCode = $targetNode->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_MOVED_TO_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_MOVED_TO_DEPARTMENT'
		;

		$this->sendImNotification(
			$nodeMember->entityId,
			$messageCode,
			[
				'#SOURCE_NAME#' => $sourceNode->name,
				'#TARGET_NAME#' => $targetNode->name,
			],
		);
	}

	/**
	 * Sends an IM notification to the user informing them they have been
	 * added to a department or team.
	 */
	private function sendAddedMemberNotification(
		Item\NodeMember $nodeMember,
		Item\Node $node,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$messageCode = $node->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_ADDED_TO_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_ADDED_TO_DEPARTMENT'
		;

		$this->sendImNotification(
			$nodeMember->entityId,
			$messageCode,
			['#NAME#' => $node->name],
		);
	}

	/**
	 * Sends an IM notification to the user informing them they have been
	 * removed from a department or team.
	 */
	private function sendRemovedMemberNotification(
		Item\NodeMember $nodeMember,
		Item\Node $node,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$messageCode = $node->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_REMOVED_FROM_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_REMOVED_FROM_DEPARTMENT'
		;

		$this->sendImNotification(
			$nodeMember->entityId,
			$messageCode,
			['#NAME#' => $node->name],
		);
	}

	/**
	 * Sends an IM notification to the manager (HEAD or DEPUTY_HEAD) of the node
	 * informing them that an employee has been removed from their department or team.
	 */
	private function sendRemovedMemberNotificationToManager(
		Item\NodeMember $nodeMember,
		Item\Node $node,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$managerUserIds = $this->findNodeManagerUserIds($node);

		if (empty($managerUserIds))
		{
			return;
		}

		$messageCode = $node->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_EMPLOYEE_REMOVED_FROM_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_EMPLOYEE_REMOVED_FROM_DEPARTMENT'
		;

		$replacements = [
			'#NAME#' => $node->name,
			'#EMPLOYEE_NAME#' => $this->getEmployeeName($nodeMember),
		];

		foreach ($managerUserIds as $managerUserId)
		{
			if ($managerUserId === $nodeMember->entityId)
			{
				continue;
			}

			$this->sendImNotification($managerUserId, $messageCode, $replacements);
		}
	}

	/**
	 * Sends an IM notification to the manager (HEAD or DEPUTY_HEAD) of the target node
	 * informing them that a new employee has been moved into their department or team.
	 */
	private function sendMoveMemberNotificationToManager(
		Item\NodeMember $nodeMember,
		Item\Node $targetNode,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$managerUserIds = $this->findNodeManagerUserIds($targetNode);

		if (empty($managerUserIds))
		{
			return;
		}

		$messageCode = $targetNode->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NEW_EMPLOYEE_IN_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NEW_EMPLOYEE_IN_DEPARTMENT'
		;

		$replacements = [
			'#NAME#' => $targetNode->name,
			'#EMPLOYEE_NAME#' => $this->getEmployeeName($nodeMember),
		];

		foreach ($managerUserIds as $managerUserId)
		{
			if ($managerUserId === $nodeMember->entityId)
			{
				continue;
			}

			$this->sendImNotification($managerUserId, $messageCode, $replacements);
		}
	}

	/**
	 * Sends an IM notification to the manager of the source node informing them
	 * that an employee has been moved out to another department or team.
	 * Skipped when the moved member was a DEPUTY_HEAD to avoid duplicating
	 * the dedicated deputy notification sent by {@see sendDeputyMovedNotificationToHead}.
	 */
	private function sendMoveMemberNotificationToPreviousManager(
		Item\NodeMember $nodeMember,
		Item\Node $sourceNode,
		Item\Node $targetNode,
		int $sourceRoleId,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$deputyRoleId = $this->getDeputyRoleId($sourceNode);

		if ($deputyRoleId !== null && $sourceRoleId === $deputyRoleId)
		{
			return;
		}

		$previousManagerUserIds = $this->findNodeManagerUserIds($sourceNode);

		if (empty($previousManagerUserIds))
		{
			return;
		}

		$newManagerUserIds = $this->findNodeManagerUserIds($targetNode);

		$messageCode = $targetNode->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_EMPLOYEE_LEFT_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_EMPLOYEE_LEFT_DEPARTMENT'
		;

		$replacements = [
			'#SOURCE_NAME#' => $sourceNode->name,
			'#TARGET_NAME#' => $targetNode->name,
			'#EMPLOYEE_NAME#' => $this->getEmployeeName($nodeMember),
		];

		foreach ($previousManagerUserIds as $previousManagerUserId)
		{
			if (
				$previousManagerUserId === $nodeMember->entityId
				|| in_array($previousManagerUserId, $newManagerUserIds, true)
			)
			{
				continue;
			}

			$this->sendImNotification($previousManagerUserId, $messageCode, $replacements);
		}
	}

	/**
	 * Sends a dedicated IM notification to the HEAD of the source node when
	 * the moved member was their DEPUTY_HEAD, so the head is aware the deputy has changed.
	 */
	private function sendDeputyMovedNotificationToHead(
		Item\NodeMember $nodeMember,
		Item\Node $sourceNode,
		int $sourceRoleId,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$deputyRoleId = $this->getDeputyRoleId($sourceNode);

		if ($deputyRoleId === null || $sourceRoleId !== $deputyRoleId)
		{
			return;
		}

		$headRoleId = $this->getHeadRoleId($sourceNode);

		if ($headRoleId === null)
		{
			return;
		}

		$heads = InternalContainer::getNodeMemberRepository()
			->findAllByRoleIdAndNodeId($headRoleId, $sourceNode->id)
		;

		if ($heads->empty())
		{
			return;
		}

		$messageCode = $sourceNode->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_DEPUTY_LEFT_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_DEPUTY_LEFT_DEPARTMENT'
		;

		$replacements = [
			'#NAME#' => $sourceNode->name,
			'#EMPLOYEE_NAME#' => $this->getEmployeeName($nodeMember),
		];

		foreach ($heads as $head)
		{
			if ($head->entityId === $nodeMember->entityId)
			{
				continue;
			}

			$this->sendImNotification($head->entityId, $messageCode, $replacements);
		}
	}

	/**
	 * Sends an IM notification to every existing member of the target node when
	 * the moved member is assigned the HEAD or DEPUTY_HEAD role, so all employees
	 * know about the new leader or deputy.
	 */
	private function sendNewHeadOrDeputyNotificationToMembers(
		Item\NodeMember $nodeMember,
		Item\Node $targetNode,
		?Item\Role $role,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		if ($this->isHeadRole($targetNode, $role))
		{
			$messageCode = $targetNode->type === NodeEntityType::TEAM
				? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NEW_HEAD_TEAM'
				: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NEW_HEAD_DEPARTMENT'
			;
		}
		elseif ($this->isDeputyRole($targetNode, $role))
		{
			$messageCode = $targetNode->type === NodeEntityType::TEAM
				? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NEW_DEPUTY_TEAM'
				: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_NEW_DEPUTY_DEPARTMENT'
			;
		}
		else
		{
			return;
		}

		$replacements = [
			'#NAME#' => $targetNode->name,
			'#EMPLOYEE_NAME#' => $this->getEmployeeName($nodeMember),
		];

		$this->broadcastMessageToNodeMembers(
			$targetNode,
			$messageCode,
			$replacements,
			[$nodeMember->entityId],
		);
	}

	/**
	 * Sends an IM notification to every remaining member of the node when
	 * the removed member was a HEAD, so all employees know the head has left.
	 */
	private function sendHeadRemovedNotificationToMembers(
		Item\NodeMember $nodeMember,
		Item\Node $node,
		int $sourceRoleId,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$headRoleId = $this->getHeadRoleId($node);

		if ($headRoleId === null || $sourceRoleId !== $headRoleId)
		{
			return;
		}

		$messageCode = $node->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_HEAD_REMOVED_FROM_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_HEAD_REMOVED_FROM_DEPARTMENT'
		;

		$replacements = ['#NAME#' => $node->name];

		$this->broadcastMessageToNodeMembers(
			$node,
			$messageCode,
			$replacements,
			[$nodeMember->entityId],
		);
	}

	/**
	 * Sends an IM notification to the manager of the target node's parent when
	 * a new HEAD is assigned to the target node, informing them that the head
	 * of their subdepartment or subteam has changed.
	 */
	private function sendHeadChangedNotificationToParentManager(
		Item\NodeMember $nodeMember,
		Item\Node $targetNode,
		?Item\Role $role,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		if (!$this->isHeadRole($targetNode, $role))
		{
			return;
		}

		$this->notifyParentNodeManager($nodeMember, $targetNode);
	}

	/**
	 * Sends an IM notification to the manager of the source node's parent when
	 * the moved member was a HEAD, informing them that the head of their
	 * subdepartment or subteam has been moved out.
	 */
	private function sendHeadLeftNotificationToParentManager(
		Item\NodeMember $nodeMember,
		Item\Node $sourceNode,
		int $sourceRoleId,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$headRoleId = $this->getHeadRoleId($sourceNode);

		if ($headRoleId === null || $sourceRoleId !== $headRoleId)
		{
			return;
		}

		$this->notifyParentNodeManager($nodeMember, $sourceNode);
	}

	/**
	 * Resolves the parent node and its manager, then sends a notification
	 * about the head of the child node being changed.
	 * Shared by {@see sendHeadChangedNotificationToParentManager}
	 * and {@see sendHeadLeftNotificationToParentManager}.
	 */
	private function notifyParentNodeManager(Item\NodeMember $nodeMember, Item\Node $childNode): void
	{
		if ($childNode->parentId === null)
		{
			return;
		}

		$parentNode = Container::getNodeRepository()->getById($childNode->parentId);
		if ($parentNode === null)
		{
			return;
		}

		$parentManagerUserIds = $this->findNodeManagerUserIds($parentNode);

		if (empty($parentManagerUserIds))
		{
			return;
		}

		$messageCode = $childNode->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_HEAD_CHANGED_SUBTEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_HEAD_CHANGED_SUBDEPARTMENT'
		;

		$replacements = ['#NAME#' => $childNode->name];

		foreach ($parentManagerUserIds as $parentManagerUserId)
		{
			if ($parentManagerUserId === $nodeMember->entityId)
			{
				continue;
			}

			$this->sendImNotification($parentManagerUserId, $messageCode, $replacements);
		}
	}

	/**
	 * Sends an IM notification to every remaining member of the node when
	 * the removed member was a DEPUTY_HEAD, so all employees know the deputy has left.
	 * HEADs are skipped because they already receive a dedicated notification
	 * from {@see sendDeputyMovedNotificationToHead}.
	 */
	private function sendDeputyRemovedNotificationToMembers(
		Item\NodeMember $nodeMember,
		Item\Node $node,
		int $sourceRoleId,
	): void
	{
		if (!$this->canSendImNotification($nodeMember))
		{
			return;
		}

		$deputyRoleId = $this->getDeputyRoleId($node);

		if ($deputyRoleId === null || $sourceRoleId !== $deputyRoleId)
		{
			return;
		}

		$headUserIds = $this->findHeadUserIds($node);

		$messageCode = $node->type === NodeEntityType::TEAM
			? 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_DEPUTY_REMOVED_FROM_TEAM'
			: 'HUMANRESOURCES_NODE_MEMBER_NOTIFICATION_SERVICE_DEPUTY_REMOVED_FROM_DEPARTMENT'
		;

		$replacements = ['#NAME#' => $node->name];

		$this->broadcastMessageToNodeMembers(
			$node,
			$messageCode,
			$replacements,
			[$nodeMember->entityId, ...$headUserIds],
		);
	}

	/**
	 * Checks whether the given role is a HEAD role for the given node type.
	 */
	private function isHeadRole(Item\Node $node, ?Item\Role $role): bool
	{
		if ($role === null)
		{
			return false;
		}

		$headRoleId = $this->getHeadRoleId($node);

		return $headRoleId !== null && $role->id === $headRoleId;
	}

	/**
	 * Checks whether the given role is a DEPUTY_HEAD role for the given node type.
	 */
	private function isDeputyRole(Item\Node $node, ?Item\Role $role): bool
	{
		if ($role === null)
		{
			return false;
		}

		$deputyRoleId = $this->getDeputyRoleId($node);

		return $deputyRoleId !== null && $role->id === $deputyRoleId;
	}

	/**
	 * Returns the formatted full name of the employee represented by the given node member.
	 * Uses preloaded cache when available, falls back to a single query otherwise.
	 */
	private function getEmployeeName(Item\NodeMember $nodeMember): string
	{
		if (isset($this->employeeNameCache[$nodeMember->entityId]))
		{
			return $this->employeeNameCache[$nodeMember->entityId];
		}

		// ToDo: switch to internal container method
		$userService = Container::getUserService();
		$user = $userService->getUserById($nodeMember->entityId);

		if ($user === null)
		{
			return '';
		}

		$name = $userService->getUserName($user);
		$this->employeeNameCache[$nodeMember->entityId] = $name;

		return $name;
	}

	/**
	 * Checks whether an IM notification can be sent for the given node member:
	 * the member must be a USER and the im module must be available.
	 */
	private function canSendImNotification(Item\NodeMember $nodeMember): bool
	{
		return $nodeMember->entityType === MemberEntityType::USER
			&& Loader::includeModule('im');
	}

	/**
	 * Builds the notification fields (including FROM_USER_ID when a current user
	 * is present) and sends the IM notification via \CIMNotify::Add.
	 *
	 * Uses gender-specific phrase suffixes (_SUBJECT_M/_SUBJECT_F, _MESSAGE_M/_MESSAGE_F)
	 * for personal notifications and _SYSTEM suffix for system notifications.
	 *
	 * When $pluralValue is not null, phrases are resolved via Loc::getMessagePlural
	 * (the phrase keys must provide _PLURAL_N variants), so numerals are declined
	 * according to the language plural rules.
	 */
	private function sendImNotification(
		int $toUserId,
		string $messageCode,
		array $replacements = [],
		?int $pluralValue = null,
	): void
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$resolveMessage = static fn (string $code): ?string => $pluralValue === null
			? Loc::getMessage($code, $replacements)
			: Loc::getMessagePlural($code, $pluralValue, $replacements)
		;

		$notifyFields = [
			'TO_USER_ID' => $toUserId,
			'NOTIFY_MODULE' => 'humanresources',
		];

		if ($currentUserId > 0)
		{
			$genderSuffix = $this->getCurrentUserGenderSuffix();

			$notifyFields['FROM_USER_ID'] = $currentUserId;
			$notifyFields['NOTIFY_TYPE'] = IM_NOTIFY_FROM;
			$notifyFields['NOTIFY_MESSAGE'] = $resolveMessage(
				$messageCode . '_MESSAGE' . $genderSuffix,
			);
			$notifyFields['PARAMS'] = [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $resolveMessage(
						$messageCode . '_SUBJECT' . $genderSuffix,
					),
				],
			];
		}
		else
		{
			$notifyFields['NOTIFY_TYPE'] = IM_NOTIFY_SYSTEM;
			$notifyFields['NOTIFY_MESSAGE'] = $resolveMessage(
				$messageCode . '_SYSTEM',
			);
		}

		if (empty($notifyFields['NOTIFY_MESSAGE']))
		{
			return;
		}

		\CIMNotify::Add($notifyFields);
	}

	private function getCurrentUserGenderSuffix(): string
	{
		static $suffix = null;

		if ($suffix !== null)
		{
			return $suffix;
		}

		$currentUserId = (int)CurrentUser::get()->getId();

		if ($currentUserId <= 0)
		{
			$suffix = '_M';

			return $suffix;
		}

		$row = \Bitrix\Main\UserTable::getList([
			'select' => ['PERSONAL_GENDER'],
			'filter' => ['=ID' => $currentUserId],
			'limit' => 1,
		])->fetch();

		$suffix = ($row['PERSONAL_GENDER'] ?? null) === 'F' ? '_F' : '_M';

		return $suffix;
	}

	/**
	 * Returns the HEAD role ID appropriate for the given node type (department or team).
	 */
	private function getHeadRoleId(Item\Node $node): ?int
	{
		$roleHelperService = Container::getRoleHelperService();

		return match ($node->type) {
			NodeEntityType::DEPARTMENT => $roleHelperService->getHeadRoleId(),
			NodeEntityType::TEAM => $roleHelperService->getTeamHeadRoleId(),
			default => null,
		};
	}

	/**
	 * Returns the DEPUTY_HEAD role ID appropriate for the given node type (department or team).
	 */
	private function getDeputyRoleId(Item\Node $node): ?int
	{
		$roleHelperService = Container::getRoleHelperService();

		return match ($node->type) {
			NodeEntityType::DEPARTMENT => $roleHelperService->getDeputyRoleId(),
			NodeEntityType::TEAM => $roleHelperService->getTeamDeputyRoleId(),
			default => null,
		};
	}

	/**
	 * Returns user IDs of the node's HEADs (without DEPUTY fallback).
	 * Used to exclude HEADs from general member notifications when they
	 * already receive a dedicated one.
	 *
	 * @return int[]
	 */
	private function findHeadUserIds(Item\Node $node): array
	{
		$headRoleId = $this->getHeadRoleId($node);

		if ($headRoleId === null)
		{
			return [];
		}

		$heads = InternalContainer::getNodeMemberRepository()
			->findAllByRoleIdAndNodeId($headRoleId, $node->id)
		;

		return $heads->empty() ? [] : $heads->getEntityIds();
	}

	/**
	 * Returns user IDs of the node's managers: all HEADs first, then all DEPUTY_HEADs as a fallback.
	 * Results are cached per public method call to avoid repeated DB lookups
	 * within a single notification batch, but reset between batches so that
	 * DB changes made by the caller between calls are visible.
	 *
	 * @return int[] User IDs of the managers; empty array if no HEAD or DEPUTY_HEAD exists.
	 */
	private function findNodeManagerUserIds(Item\Node $node): array
	{
		if (array_key_exists($node->id, $this->managerCache))
		{
			return $this->managerCache[$node->id];
		}

		$nodeMemberRepository = InternalContainer::getNodeMemberRepository();

		$headRoleId = $this->getHeadRoleId($node);

		if ($headRoleId !== null)
		{
			$heads = $nodeMemberRepository->findAllByRoleIdAndNodeId($headRoleId, $node->id);
			if (!$heads->empty())
			{
				$this->managerCache[$node->id] = $heads->getEntityIds();

				return $this->managerCache[$node->id];
			}
		}

		$deputyRoleId = $this->getDeputyRoleId($node);

		if ($deputyRoleId !== null)
		{
			$deputies = $nodeMemberRepository->findAllByRoleIdAndNodeId($deputyRoleId, $node->id);
			if (!$deputies->empty())
			{
				$this->managerCache[$node->id] = $deputies->getEntityIds();

				return $this->managerCache[$node->id];
			}
		}

		$this->managerCache[$node->id] = [];

		return [];
	}

	/**
	 * Snapshots USER member IDs of the node and schedules a background job
	 * to deliver the given IM message to each of them (excluding $excludeUserIds).
	 * Used by broadcast notifications to keep the HTTP request fast on large
	 * nodes — sending to 1000+ users synchronously would block the response.
	 *
	 * @param int[] $excludeUserIds User IDs that must not receive the message
	 *                              (typically the actor and/or other recipients
	 *                              that get a dedicated notification).
	 */
	private function broadcastMessageToNodeMembers(
		Item\Node $node,
		string $messageCode,
		array $replacements,
		array $excludeUserIds,
	): void
	{
		$userIds = InternalContainer::getNodeMemberRepository()
			->getUserIdsByNodeId($node->id, $excludeUserIds)
		;
		if (empty($userIds))
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			[$this, 'deliverBroadcastNotifications'],
			[$userIds, $messageCode, $replacements],
		);
	}

	/**
	 * Background-job callable: sends the prepared IM message to each user.
	 * Public because it must be reachable as a callable from the background
	 * job queue; not part of the service's public contract.
	 *
	 * @internal
	 * @param int[] $userIds
	 */
	public function deliverBroadcastNotifications(
		array $userIds,
		string $messageCode,
		array $replacements,
	): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		foreach ($userIds as $userId)
		{
			$this->sendImNotification($userId, $messageCode, $replacements);
		}
	}
}

