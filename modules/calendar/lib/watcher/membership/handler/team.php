<?php

namespace Bitrix\Calendar\Watcher\Membership\Handler;

use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

/**
 * Reacts to humanresources team membership and node lifecycle events and queues
 * a recalculation of events that reference the team code SNT<nodeId>.
 * Only TEAM nodes are handled: HR emits the same events for departments, which
 * are covered by the Department watcher and must be ignored here.
 */
class Team extends Handler
{
	public static function onMemberAdded(Event $event): void
	{
		self::handleMemberEvent($event);
	}

	public static function onMemberUpdated(Event $event): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		$member = $event->getParameter('member');
		if (!($member instanceof NodeMember))
		{
			return;
		}

		// A member moved between teams: both the new and the old team change composition.
		$previousMember = $event->getParameter('previousMember');
		if ($previousMember instanceof NodeMember && $previousMember->nodeId !== $member->nodeId)
		{
			self::queueTeamNodeForMember($member);
			self::queueTeamNodeForMember($previousMember);

			return;
		}

		// HR also emits OnMemberUpdated for role changes and active re-saves that do not touch
		// composition; skip those to avoid needless event recalculation.
		$fields = (array)$event->getParameter('fields');
		if (
			!empty($fields)
			&& !in_array('active', $fields, true)
			&& !in_array('nodeId', $fields, true)
		)
		{
			return;
		}

		if (
			$previousMember instanceof NodeMember
			&& in_array('active', $fields, true)
			&& $previousMember->active === $member->active
		)
		{
			return;
		}

		self::queueTeamNodeForMember($member);
	}

	public static function onMemberDeleted(Event $event): void
	{
		self::handleMemberEvent($event);
	}

	public static function onNodeUpdated(Event $event): void
	{
		self::handleNodeEvent($event);
	}

	public static function onNodeDeleted(Event $event): void
	{
		self::handleNodeEvent($event);
	}

	private static function handleMemberEvent(Event $event): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		$member = $event->getParameter('member');
		if ($member instanceof NodeMember)
		{
			self::queueTeamNodeForMember($member);
		}
	}

	private static function queueTeamNodeForMember(NodeMember $member): void
	{
		// node type is not in the payload; lazy-loaded from the member's node
		$node = $member->node;
		if (!($node instanceof Node) || $node->type !== NodeEntityType::TEAM)
		{
			return;
		}

		self::sendMessageToQueue(self::TEAM_TYPE, $member->nodeId);
	}

	private static function handleNodeEvent(Event $event): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		$node = $event->getParameter('node');
		if (!($node instanceof Node) || $node->type !== NodeEntityType::TEAM)
		{
			return;
		}

		self::sendMessageToQueue(self::TEAM_TYPE, $node->id);
	}
}
