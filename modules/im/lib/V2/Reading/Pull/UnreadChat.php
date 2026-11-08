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
use Bitrix\Main\Type\DateTime;

class UnreadChat extends BaseChatEvent
{
	use RecentPreviewPullTrait;

	protected int $expiry = 3600;

	/**
	 * A chat without a recent row (comment chat) has no item to read the row-owned fields from:
	 * `active` stays false so the client does not add a row, the read-later mark is zero and the
	 * activity date falls back to the chat's last message.
	 */
	public function __construct(
		protected Chat $chat,
		protected int $userId,
		protected int $counter,
		protected ?RecentItem $recentItem = null,
	)
	{
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return array_merge(
			$this->getBaseRecentPreviewParams($this->chat, $this->chat->getLastMessage(), $this->getDateLastActivity()),
			[
				'parentChatId' => $this->chat->getParentChatId(),
				'active' => $this->recentItem?->isUnread() ?? false,
				'muted' => $this->chat->getRelationByUserId($this->userId)?->getNotifyBlock() ?? false,
				'counter' => $this->counter,
				'markedId' => $this->recentItem?->getMarkedId() ?? 0,
				'lines' => $this->chat->getType() === Chat::IM_TYPE_OPEN_LINE,
			],
		);
	}

	private function getDateLastActivity(): ?DateTime
	{
		return $this->recentItem?->getDateLastActivity() ?? $this->chat->getLastMessage()?->getDateCreate();
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
		if ($this->recentItem === null || !Features::isCollabPreviewSourceEnabled())
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
