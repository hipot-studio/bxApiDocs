<?php

declare(strict_types = 1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Pull\RecentPreviewPullTrait;

class ChatPin extends BaseChatEvent
{
	use RecentPreviewPullTrait;

	protected int $userId;
	protected bool $active;

	/**
	 * The two facts of one change travel together (EVENT-01): $folderId names the folder whose pin
	 * set moved, $globalPinned the new value of the overall pinned flag. Either may be null — the
	 * fact it carries simply did not change — and the field is then left out of the payload, so a
	 * client applies only what actually happened and derives neither fact from the other.
	 */
	public function __construct(
		Chat $chat,
		bool $active,
		$userId,
		protected readonly ?int $folderId = null,
		protected readonly ?bool $globalPinned = null,
	)
	{
		parent::__construct($chat);

		$this->userId = $userId;
		$this->active = $active;
	}

	protected function getBasePullParamsInternal(): array
	{
		$payload = array_merge(
			$this->getBaseRecentPreviewParams(
				$this->chat,
				$this->chat->getLastMessage(),
				$this->resolveUserDateLastActivity($this->chat, $this->userId),
			),
			[
				'active' => $this->active,
			]
		);

		if ($this->folderId !== null)
		{
			$payload['folderId'] = $this->folderId;
		}

		if ($this->globalPinned !== null)
		{
			$payload['globalPinned'] = $this->globalPinned;
		}

		return $payload;
	}

	protected function getDiffByUser(int $userId): Diff
	{
		return new Diff($userId, $this->getRecentPreviewUserDiffParams($this->chat, $userId));
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
