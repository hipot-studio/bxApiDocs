<?php

declare(strict_types = 1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Pull\EventType;

class SetCopilotTitle extends BaseChatEvent
{
	use ContextCustomer;
	use DialogIdFiller;

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->chat->getId(),
			'dialogId' => $this->getBaseDialogId(),
		];
	}

	protected function getType(): EventType
	{
		return EventType::SetCopilotTitle;
	}

	protected function getRecipients(): array
	{
		return $this->chat->getRelations()->filterActive()->getUserIds();
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return false;
	}

	public function shouldSendImmediately(): bool
	{
		return true;
	}
}
