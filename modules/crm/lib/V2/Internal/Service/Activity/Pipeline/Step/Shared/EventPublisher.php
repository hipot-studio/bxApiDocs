<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;

/**
 * The single place the pipeline of an activity publishes the events of the domain from: seven events, in
 * the moments, with the payloads and with the semantics legacy publishes them with (EVENT-01).
 *
 * ONE PUBLISHER PER PATH, AND THE PIPELINE OWNS IT. The facade publishes nothing of its own: it converts
 * its input and delegates, and the pipeline publishes. That is the whole reason this class exists instead
 * of the six steps each raising an event of their own - a second publication point is how the same event
 * reaches a subscriber twice once the facade starts delegating, and a subscriber that fires twice is a
 * defect nobody notices until a third-party webhook doubles.
 *
 * WHAT IS NOT NEGOTIABLE ABOUT THE PAYLOADS:
 * - the fields travel BY REFERENCE, because the events before a write exist for subscribers to change
 *   them and the change is expected to reach the row. That rules {@see Event} out for those: its
 *   parameters are values, so a subscriber mutating them would be writing into a copy. What is used
 *   instead is what legacy uses - the handlers of the compatible bus, invoked with a reference;
 * - the shape of the array is the legacy one, upper-case keys and all. It is an external contract: REST
 *   webhooks of third-party applications and fifteen modules read it, so the pipeline hands over the
 *   legacy shape of what it wrote rather than anything typed;
 * - the subscribers of an update see the encoded emoji, because in legacy the event is published after
 *   the encoding. Reproduced as it is, deliberately;
 * - only the deletion has a veto, and only on a strictly false answer: anything else, including the null
 *   a handler of an unavailable module returns, lets the deletion carry on.
 *
 * THE ORDER OF THE TAIL OF AN UPDATE IS PART OF THE CONTRACT. The event of a change and the event of a
 * modified activity are published by one step, in that order, and the cache of the broker is warmed by
 * the second one - which is why both live in one method here rather than in two the step orders itself.
 *
 * @internal
 */
final class EventPublisher
{
	public const EVENT_BEFORE_ADD = 'OnBeforeCrmActivityAdd';

	public const EVENT_BEFORE_UPDATE = 'OnBeforeCrmActivityUpdate';

	public const EVENT_BEFORE_DELETE = 'OnBeforeActivityDelete';

	public const EVENT_ACTIVITY_ADD = 'OnActivityAdd';

	public const EVENT_ACTIVITY_UPDATE = 'OnActivityUpdate';

	public const EVENT_ACTIVITY_MODIFIED = 'OnActivityModified';

	public const EVENT_ACTIVITY_DELETE = 'OnActivityDelete';

	private const MODULE_ID = 'crm';

	/**
	 * Published by an add after the serialization and before the compression of an email description and
	 * the encoding of the emoji, so a subscriber sees the serialized settings and an uncompressed
	 * description. Mutations reach the row; there is no veto and the answers are ignored.
	 *
	 * @param array<string, mixed> $fields the fields of the activity in the legacy shape.
	 */
	public function publishOnBeforeAdd(array &$fields): void
	{
		foreach ($this->findHandlers(self::EVENT_BEFORE_ADD) as $handler)
		{
			ExecuteModuleEventEx($handler, [&$fields]);
		}
	}

	/**
	 * Published by an update after the compression and the encoding and before the write - the inversion
	 * of the moment an add publishes at, which is an invariant of the design rather than a mistake.
	 * Mutations reach the row; there is no veto.
	 *
	 * @param array<string, mixed> $fields the fields being written, in the legacy shape.
	 */
	public function publishOnBeforeUpdate(int $activityId, array &$fields): void
	{
		foreach ($this->findHandlers(self::EVENT_BEFORE_UPDATE) as $handler)
		{
			ExecuteModuleEventEx($handler, [$activityId, &$fields]);
		}
	}

	/**
	 * Published by a delete before the row is even read, so a subscriber that refuses the deletion stops
	 * it before anything has been read or checked.
	 *
	 * @return bool false when a subscriber answered strictly false, which vetoes the deletion. The
	 *         subscribers after the one that refused are not asked, exactly as in legacy.
	 */
	public function publishOnBeforeDelete(int $activityId): bool
	{
		foreach ($this->findHandlers(self::EVENT_BEFORE_DELETE) as $handler)
		{
			if (ExecuteModuleEventEx($handler, [$activityId]) === false)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The last step of an add: the event REST webhooks of third-party applications are delivered from.
	 * The serialized fields are deserialized again by then, the way legacy hands them over.
	 *
	 * @param array<string, mixed> $fields the fields of the created activity, in the legacy shape.
	 */
	public function publishOnActivityAdd(int $activityId, array &$fields): void
	{
		foreach ($this->findHandlers(self::EVENT_ACTIVITY_ADD) as $handler)
		{
			ExecuteModuleEventEx($handler, [$activityId, &$fields]);
		}
	}

	/**
	 * The tail of an update: the event of the webhooks first, the event of a modified activity second.
	 * The order is the contract - see the note of the class.
	 *
	 * @param array<string, mixed> $fields the changed fields, in the legacy shape, settings deserialized.
	 * @param array<string, mixed> $previousRow the state of the activity before the write.
	 * @param array<string, mixed> $currentRow the state of the activity as it has been re-read after it.
	 */
	public function publishOnActivityUpdate(
		int $activityId,
		array &$fields,
		array $previousRow,
		array $currentRow,
	): void
	{
		foreach ($this->findHandlers(self::EVENT_ACTIVITY_UPDATE) as $handler)
		{
			ExecuteModuleEventEx($handler, [$activityId, &$fields]);
		}

		$event = new Event(
			self::MODULE_ID,
			self::EVENT_ACTIVITY_MODIFIED,
			[
				'before' => $previousRow,
				'current' => $currentRow,
			],
		);
		$event->send();
	}

	/**
	 * Published by a delete of a row. NOT published when the activity is only moved to the recycle bin:
	 * the transfer returns from the operation before this point, so a subscriber never learns about a
	 * deletion that can still be undone.
	 */
	public function publishOnActivityDelete(int $activityId): void
	{
		foreach ($this->findHandlers(self::EVENT_ACTIVITY_DELETE) as $handler)
		{
			ExecuteModuleEventEx($handler, [$activityId]);
		}
	}

	/**
	 * @return array<int, array<string, mixed>> the subscribers of the event, in the order of their sort.
	 */
	private function findHandlers(string $eventName): array
	{
		return EventManager::getInstance()->findEventHandlers(self::MODULE_ID, $eventName);
	}
}
