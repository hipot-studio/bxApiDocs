<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;

class ChatMute extends BaseChatEvent
{
	protected int $userId;
	protected bool $isMuted;

	public function __construct(Chat $chat, int $userId, bool $isMuted)
	{
		$this->userId = $userId;
		$this->isMuted = $isMuted;
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->chat->getId(),
			'muted' => $this->isMuted,
			'mute' => $this->isMuted, // TODO remove this later
			'lines' => $this->chat instanceof Chat\OpenLineChat,
			'counterType' => $this->chat->getCounterType(),
			'recentConfig' => $this->chat->getRecentConfig()->toPullFormat(),
		];
	}

	protected function getDiffByUser(int $userId): Diff
	{
		return new Diff($userId, [
			'dialogId' => $this->chat->getDialogId($userId),
			'counter' => $this->chat->withContextUser($userId)->getUserCounter(),
			'unread' => Recent::isUnread($userId, $this->chat->getType(), $this->chat->getDialogId($userId)),
		]);
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
		return EventType::ChatMute;
	}
}
