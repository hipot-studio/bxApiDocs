<?php

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

enum AutoTaskType: string
{
	case TaskCreation = 'taskCreation';
	case TaskCreationMulti = 'taskCreationMulti';

	/**
	 * Identity of the dedup slot: the messages of the selection and nothing else. The initiator is
	 * deliberately not part of it, so that an automatic launch and a manual one over the same
	 * source cannot both create a task - which also makes the slot shared by every initiator of the
	 * chat. A launch that finds it taken owns no operation and must be refused explicitly,
	 * @see AbstractCreator::refuseTakenSlot().
	 */
	public function buildEntityId(array $messageIds): string
	{
		return match ($this)
		{
			self::TaskCreation => (string)(int)current($messageIds),
			self::TaskCreationMulti => self::buildMessageSetHash($messageIds),
		};
	}

	private static function buildMessageSetHash(array $messageIds): string
	{
		$ids = array_unique(array_map('intval', $messageIds));
		sort($ids);

		return sha1(implode(',', $ids));
	}
}
