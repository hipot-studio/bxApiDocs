<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM;

use Bitrix\Main\Application;
use Bitrix\Socialnetwork\Helper\InstanceTrait;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;

/**
 * Collects everything one membership edit added to or removed from a project and publishes a single message
 * per key on flush. A record holds both people (recipient ids) and organizational structure nodes
 * (departments and teams with their names), so one edit gives one message no matter how the members were
 * picked.
 */
class ActionMessageBuffer
{
	use InstanceTrait;

	/**
	 * Parameter key under which the collected structure nodes reach ActionMessageInterface::send().
	 * The value is a list of MemberEntity; the key is absent when the record has no node.
	 */
	public const STRUCTURE_NODES_PARAMETER = 'structureNodes';

	/**
	 * Parameter key that leaves a record with everything it does (the chat membership above all) but
	 * without the text: the very key AddBotActionMessage has always read.
	 */
	public const WITHOUT_MESSAGE_PARAMETER = 'withoutMessage';

	/**
	 * Parameter key telling how the membership was initiated. It decides both whether the people are named
	 * one by one or by the node that brought them (AddUserActionMessage) and the reason their chat relation
	 * is created with (MessageTrait::addUsersToChat), so records that disagree on it cannot share a message.
	 */
	private const INITIATED_BY_TYPE_PARAMETER = 'initiatedByType';

	/**
	 * Sender of a record nobody in particular stands behind: a node unlinked from the project, or a node
	 * linked outside a web request, where the author of the link is unknown.
	 */
	private const NO_SENDER = 0;

	protected static array $stack = [];

	/**
	 * Projects whose composition is named by the creation card.
	 *
	 * @var array<int, true>
	 */
	protected static array $suppressedCollabIds = [];

	/**
	 * Number of edits that asked the buffer to wait.
	 */
	protected static int $holdDepth = 0;

	protected static bool $isSuppressionReleaseScheduled = false;

	/**
	 * @param int[] $recipients
	 * @param MemberEntity[] $nodes departments and teams named in the message; entries without an id,
	 *        a node type or a name are dropped
	 */
	public function put(
		ActionType $action,
		$collabId,
		$senderId,
		array $recipients = [],
		array $parameters = [],
		array $nodes = [],
	): static
	{
		$key = $this->buildKey($action, (int)$collabId, $parameters);
		if (!isset(static::$stack[$key]))
		{
			static::$stack[$key] = [
				'action' => $action,
				'collabId' => (int)$collabId,
				'senderId' => (int)$senderId,
				'recipients' => [],
				'parameters' => [],
				'nodes' => [],
			];
		}
		elseif (static::$stack[$key]['senderId'] === self::NO_SENDER)
		{
			// The record speaks for whichever of the two senders is known: a node linked outside a web request
			// has no author, while the command that added the people alongside it does.
			static::$stack[$key]['senderId'] = (int)$senderId;
		}

		static::$stack[$key]['recipients'] = array_merge(static::$stack[$key]['recipients'], $recipients);
		static::$stack[$key]['parameters'] = array_merge(static::$stack[$key]['parameters'], $parameters);
		static::$stack[$key]['nodes'] = $this->mergeNodes(static::$stack[$key]['nodes'], $nodes);

		return $this;
	}

	/**
	 * One save of the composition is one record per project and action. The initiator is data of the record
	 * and not part of its identity: a node carries the author of its link (the current user), the people
	 * carry the initiator the command was given, and the two are the same only by chance - keying by the
	 * sender would split one save into two messages as soon as they differ.
	 */
	private function buildKey(ActionType $action, int $collabId, array $parameters): string
	{
		$initiatedByType = (string)(
			$parameters[self::INITIATED_BY_TYPE_PARAMETER] ?? UserToGroupTable::INITIATED_BY_GROUP
		);

		return "{$action->value}:{$collabId}:{$initiatedByType}";
	}

	/**
	 * Explicit sign of a project being created, set by the creation pipeline once the card carrying the
	 * composition is out. Nothing here is guessed from the age of the project or the emptiness of its chat.
	 */
	public function suppressMemberMessages(int $collabId): static
	{
		static::$suppressedCollabIds[$collabId] = true;

		$this->scheduleSuppressionRelease();

		return $this;
	}

	public function areMemberMessagesSuppressed(int $collabId): bool
	{
		return isset(static::$suppressedCollabIds[$collabId]);
	}

	/**
	 * Keeps the buffer collecting while an edit is still running. Linking a node to a project brings its
	 * members in, and that nested edit finishes with a flush of its own (CollabService): publishing there
	 * would announce the part of the save already collected and leave the rest for another message.
	 */
	public function holdFlush(): static
	{
		static::$holdDepth++;

		return $this;
	}

	/**
	 * Releasing does not publish anything: what is collected waits for the flush that closes the whole edit -
	 * the project pipeline, or the end of the request for an edit made in the org structure. Publishing on
	 * release would bring the split back, because a save holds the buffer once per node.
	 */
	public function releaseFlush(): static
	{
		if (static::$holdDepth > 0)
		{
			static::$holdDepth--;
		}

		return $this;
	}

	/**
	 * Drops the sign of a project being created. Called at the end of the request, because a creation that
	 * broke off in between never reaches the flush that would have dropped it, and a process serving more
	 * than one request would carry the sign into the next edit of the same project and silence it.
	 */
	public function releaseMemberMessagesSuppression(): void
	{
		static::$suppressedCollabIds = [];
		static::$isSuppressionReleaseScheduled = false;
	}

	/**
	 * A flush closes the edit cycle, and the suppression goes with it: editing the composition of the same
	 * project afterwards is announced as usual.
	 *
	 * Does nothing while the buffer is held: the edit that asked it to wait is still running, and its records
	 * stay for the flush that closes it. The records being published leave the buffer before the first
	 * message is sent, so that a message starting an edit of its own neither re-enters the publication nor
	 * loses what that edit collects.
	 */
	public function flush(): void
	{
		if (static::$holdDepth > 0)
		{
			return;
		}

		$records = static::$stack;
		static::$stack = [];

		$factory = ActionMessageFactory::getInstance();

		$this->holdFlush();

		try
		{
			foreach ($records as $data)
			{
				$parameters = $data['parameters'];

				if (!empty($data['nodes']))
				{
					$parameters[self::STRUCTURE_NODES_PARAMETER] = array_values($data['nodes']);
				}

				$factory
					->getActionMessage($data['action'], $data['collabId'], $data['senderId'])
					->send($data['recipients'], $parameters);
			}
		}
		finally
		{
			$this->releaseFlush();

			static::$suppressedCollabIds = [];
		}
	}

	private function scheduleSuppressionRelease(): void
	{
		if (static::$isSuppressionReleaseScheduled || !Application::hasInstance())
		{
			return;
		}

		static::$isSuppressionReleaseScheduled = true;

		Application::getInstance()->addBackgroundJob(
			static fn() => ActionMessageBuffer::getInstance()->releaseMemberMessagesSuppression(),
		);
	}

	/**
	 * Nodes keep the order they were put in, which is what keeps the enumeration stable: people first (they
	 * travel as recipients), then departments and teams as they arrived. Keyed by type and id, because the
	 * same node may be put by more than one handler within a single edit, and a legacy department id may
	 * collide with a team node id.
	 *
	 * @param MemberEntity[] $newNodes
	 */
	private function mergeNodes(array $nodes, array $newNodes): array
	{
		foreach ($newNodes as $node)
		{
			if (!$this->isNamedStructureNode($node))
			{
				continue;
			}

			$nodes["{$node->type->value}:{$node->id}"] = $node;
		}

		return $nodes;
	}

	/**
	 * Only a department or a team can be named in the message, and only when its name is known: the message
	 * lists nodes by name, so a nameless node has nothing to contribute.
	 */
	private function isNamedStructureNode(mixed $node): bool
	{
		return $node instanceof MemberEntity
			&& $node->id !== null && $node->id > 0
			&& $node->type !== null && $node->type !== MemberEntityType::User
			&& trim((string)$node->name) !== ''
		;
	}
}
