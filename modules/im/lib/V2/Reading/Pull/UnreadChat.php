<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Pull;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Event\BaseChatEvent;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Pull\RecentPreviewPullTrait;
use Bitrix\Im\V2\Recent\PreviewSource\PreviewSource;
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
			$this->getBaseRecentPreviewParams($this->chat, $this->chat->getLastMessage(), $this->recentItem->getDateLastActivity()),
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
		$params = $this->getRecentPreviewUserDiffParams($this->chat, $userId);

		$collabPreviewDiff = $this->getCollabPreviewDiff();
		if ($collabPreviewDiff !== null)
		{
			$params = array_merge($params, $collabPreviewDiff);
		}

		return new Diff($userId, $params);
	}

	/**
	 * Keep a collab row showing its child preview source when marked unread (the pointer is left intact
	 * on unread), instead of falling back to the row's own last message. Driven by the materialized
	 * pointer, not the chat type.
	 *
	 * @return array|null preview override to merge over the base diff, or null when there is none
	 */
	private function getCollabPreviewDiff(): ?array
	{
		if (!Features::isCollabPreviewSourceEnabled())
		{
			return null;
		}

		$sourceChatId = $this->recentItem->getPreviewSourceCid();
		$sourceMessageId = $this->recentItem->getPreviewSourceMid();
		if ($sourceChatId <= 0 || $sourceMessageId <= 0)
		{
			return null;
		}

		$previewSource = new PreviewSource($sourceChatId, $sourceMessageId, false);

		return $this->getCollabPreviewUserDiffParams($this->chat, $previewSource) ?: null;
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
