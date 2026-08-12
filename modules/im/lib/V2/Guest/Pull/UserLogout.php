<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

/**
 * Tells the listed user(s) to drop their session and redirect; the recipient list is
 * authoritative, the client redirects unconditionally.
 */
class UserLogout extends BaseEvent
{
	/**
	 * @param list<int> $userIds
	 */
	public function __construct(
		private readonly array $userIds,
	)
	{
		parent::__construct();
	}

	public function getTarget(): ?Chat
	{
		return null;
	}

	protected function getRecipients(): array
	{
		return $this->userIds;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	public function shouldSendImmediately(): bool
	{
		return true;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [];
	}

	protected function getType(): EventType
	{
		return EventType::UserLogout;
	}
}
