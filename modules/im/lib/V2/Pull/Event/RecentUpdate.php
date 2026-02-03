<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\Type\DateTime;

class RecentUpdate extends BaseChatEvent
{
	protected array $recipients;
	protected array $counters;
	protected DateTime $lastActivity;

	/**
	 * @param int[] $recipients
	 */
	public function __construct(Chat $chat, array $recipients, ?DateTime $lastActivity = null)
	{
		$this->recipients = array_map('intval', $recipients);
		$this->counters = (new CounterService())->getByChatForEachUsers($chat->getChatId(), $recipients);
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
		$restAdapter = new RestAdapter($messages);
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

	protected function getDiffByUser(int $userId): Diff
	{
		$diffParams = [
			'counter' => $this->counters[$userId] ?? 0,
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