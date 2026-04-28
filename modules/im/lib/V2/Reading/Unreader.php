<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Sync;

class Unreader
{
	public function __construct(
		protected readonly Counter\CountersUpdater $counterUpdater,
		protected readonly Counter\CountersProvider $countersProvider,
	) {}

	public function unreadTo(Message $message, int $userId): UnreadResult
	{
		$relation = $message->getChat()->withContextUser($userId)->getSelfRelation();
		if ($relation === null)
		{
			return UnreadResult::error(new ReadingError(ReadingError::USER_NOT_IN_CHAT));
		}

		$this->counterUpdater->addStartingFrom($message->getMessageId(), $relation);
		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::CHAT_ENTITY, $message->getChatId()),
			$userId,
			$message->getChat()
		);
		$counter = $this->countersProvider->getForUser($message->getChatId(), $userId);

		return new UnreadResult($counter);
	}
}
