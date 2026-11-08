<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\ArgumentException;

class CreatorFactory
{
	/**
	 * @param int $requestedMessagesCount how many ids the request carried, which the collection
	 *        no longer tells: deleted and foreign-chat messages drop out before the factory.
	 *
	 * @throws ArgumentException when the selection is empty: the public entry point
	 *         (Task::createByAiAction) already rejects an empty collection, so this guards
	 *         the contract and fails fast instead of building a creator with a null anchor.
	 */
	public function createByMessages(
		MessageCollection $messageCollection,
		int $requestedMessagesCount
	): AbstractCreator
	{
		if ($messageCollection->isEmpty())
		{
			throw new ArgumentException('Message collection is empty');
		}

		$creator = match (true)
		{
			$this->isTranscriptMessage($messageCollection) => $this->createTranscriptionCreator($messageCollection),
			$messageCollection->count() === 1 => new SingleMessageCreator($messageCollection->getAny()),
			default => new MultipleMessagesCreator($messageCollection),
		};

		return $creator->setRequestedMessagesCount($requestedMessagesCount);
	}

	protected function isTranscriptMessage(MessageCollection $messageCollection): bool
	{
		if ($messageCollection->count() > 1)
		{
			return false;
		}

		foreach ($messageCollection as $message)
		{
			if ($message->isVoiceNote() || $message->isVideoNote())
			{
				return true;
			}
		}

		return false;
	}

	protected function createTranscriptionCreator(MessageCollection $messageCollection): TranscriptionCreator
	{
		$message = $messageCollection->getAny();
		$file = $message->getFiles()->getAny();

		return new TranscriptionCreator(
			$message,
			$file->getOriginalFileId(),
			$file->getDiskFileId()
		);
	}
}
