<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Event\BaseChatEvent;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Pull\RecentPreviewPullTrait;
use Bitrix\Im\V2\Recent\RecentItem;

class UnreadChat extends BaseChatEvent
{
	use RecentPreviewPullTrait;

	protected int $expiry = 3600;

	public function __construct(
		protected Chat $chat,
		protected int $userId,
		protected int $counter,
		protected RecentItem $recentItem,
	)
	{
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return array_merge(
			$this->getBaseRecentPreviewParams($this->chat, lastActivityDate: $this->recentItem->getDateLastActivity()),
			[
				'parentChatId' => $this->chat->getParentChatId(),
				'active' => $this->recentItem->isUnread(),
				'muted' => $this->chat->getRelationByUserId($this->userId)?->getNotifyBlock() ?? false,
				'counter' => $this->counter,
				'markedId' => $this->recentItem->getMarkedId(),
				'lines' => $this->chat->getType() === Chat::IM_TYPE_OPEN_LINE,
			],
		);
	}

	protected function getDiffByUser(int $userId): Diff
	{
		return new Diff($userId, $this->getRecentPreviewUserDiffParams($this->chat, $userId));
	}

	protected function getType(): EventType
	{
		return EventType::UnreadChat;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}
}
