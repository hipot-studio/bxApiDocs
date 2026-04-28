<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

class RecentUpdate extends BaseChatEvent
{
	protected array $recipients;
	protected UsersCounterMap $counters;
	protected DateTime $lastActivity;

	/**
	 * @param int[] $recipients
	 */
	public function __construct(Chat $chat, array $recipients, ?DateTime $lastActivity = null)
	{
		$this->recipients = array_map('intval', $recipients);
		$this->counters = ServiceLocator::getInstance()->get(CountersProvider::class)->getForUsers($chat->getChatId(), $recipients);
		$this->lastActivity = $lastActivity ?? new DateTime();

		parent::__construct($chat);
	}

	protected function getRecipients(): array
	{
		return $this->recipients;
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
		$pull['lastActivityDate'] = $this->lastActivity;
		$pull['counterType'] = $this->chat->getCounterType();
		$pull['recentConfig'] = $this->chat->getRecentConfig()->toPullFormat();

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

	protected function getDiffByUser(int $userId): Diff
	{
		$diffParams = [
			'counter' => $this->counters->getByUserId($userId),
			'dialogId' => $this->chat->getDialogId($userId),
		];

		return new Diff($userId, $diffParams);
	}

	protected function getType(): EventType
	{
		return EventType::RecentUpdate;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}
}
