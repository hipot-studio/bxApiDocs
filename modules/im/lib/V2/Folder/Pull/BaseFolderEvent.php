<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Folder\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;

abstract class BaseFolderEvent extends BaseEvent
{
	public function __construct(protected readonly int $userId)
	{
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
