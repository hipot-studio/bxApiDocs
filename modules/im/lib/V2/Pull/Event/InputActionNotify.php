<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\InputAction\Type;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Pull\EventType;

class InputActionNotify extends BaseChatEvent
{
	use ContextCustomer;
	use DialogIdFiller;

	protected ?string $customUserName = null;
	protected Type $actionType;
	protected int $expiry = 60;

	public function __construct(Chat $chat, Type $actionType)
	{
		parent::__construct($chat);
		$this->actionType = $actionType;
	}

	protected function getType(): EventType
	{
		return EventType::InputActionNotify;
	}

	public function setCustomUserName(?string $customUserName): self
	{
		if ($customUserName)
		{
			$this->customUserName = $customUserName;
		}

		return $this;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'dialogId' => $this->getBaseDialogId(),
			'userId' => $this->getContext()->getUserId(),
			'userName' => $this->customUserName ?? $this->getContext()->getUser()->getName(),
			'userFirstName' => $this->customUserName ?? $this->getContext()->getUser()->getFirstName(),
			'type' => $this->actionType->value,
		];
	}

	protected function getSkippedUserIds(): array
	{
		return [$this->getContext()->getUserId()];
	}

	protected function getBaseDialogId(): string
	{
		return 'chat' . $this->chat->getId();
	}
}
