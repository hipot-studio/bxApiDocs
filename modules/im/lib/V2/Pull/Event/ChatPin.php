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

	public function __construct(Chat $chat, bool $active, $userId)
	{
		parent::__construct($chat);

		$this->userId = $userId;
		$this->active = $active;
	}

	protected function getBasePullParamsInternal(): array
	{
		return array_merge(
			$this->getBaseRecentPreviewParams($this->chat),
			[
				'active' => $this->active,
			]
		);
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
