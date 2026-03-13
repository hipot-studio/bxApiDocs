<?php

declare(strict_types = 1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Rest\RestAdapter;

class ChatPin extends BaseChatEvent
{
	protected int $userId;
	protected bool $active;

	public function __construct(Chat $chat, bool $active, $userId)
	{
		parent::__construct($chat);

		$this->userId = $userId;
		$this->active = $active;
	}

	protected function getBasePullParamsInternal(): array
	{
		$messages = new MessagePopupItem([$this->chat->getLastMessageId()], true);
		$users = $this->getUsersForRest();

		$restAdapter = new RestAdapter($messages, $users);
		$pull = $restAdapter->toRestFormat([
			'WITHOUT_OWN_REACTIONS' => true,
			'MESSAGE_ONLY_COMMON_FIELDS' => true,
		]);

		$pull['chat'] = $this->chat->toPullFormat();
		$pull['counterType'] = $this->chat->getCounterType();
		$pull['recentConfig'] = $this->chat->getRecentConfig()->toPullFormat();

		$pull['active'] = $this->active;
		$pull['dialogId'] = $this->chat->getDialogId();

		return $pull;
	}

	protected function getUsersForRest(): UserCollection
	{
		if ($this->chat instanceof PrivateChat)
		{
			return new UserCollection([$this->chat->getCompanionId()]);
		}

		return new UserCollection();
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getType(): EventType
	{
		return EventType::ChatPin;
	}
}
