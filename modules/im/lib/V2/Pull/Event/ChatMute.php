<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Pull\RecentPreviewPullTrait;

class ChatMute extends BaseChatEvent
{
	use RecentPreviewPullTrait;

	protected int $userId;
	protected bool $isMuted;
	protected int $counter;

	public function __construct(Chat $chat, int $userId, bool $isMuted, int $counter)
	{
		$this->userId = $userId;
		$this->isMuted = $isMuted;
		$this->counter = $counter;
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return array_merge(
			$this->getBaseRecentPreviewParams($this->chat),
			[
				'muted' => $this->isMuted,
				'mute' => $this->isMuted, // TODO remove this later
				'lines' => $this->chat instanceof Chat\OpenLineChat,
			]
		);
	}

	protected function getDiffByUser(int $userId): Diff
	{
		return new Diff(
			$userId,
			array_merge(
				$this->getRecentPreviewUserDiffParams($this->chat, $userId),
				[
					'counter' => $this->counter,
					'unread' => Recent::isUnread($userId, $this->chat->getType(), $this->chat->getDialogId($userId)),
				],
			),
		);
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
