<?php

namespace Bitrix\Im\V2\Chat\TextField;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Pull\Event\ChatFieldsUpdate;

class TextFieldEnabled
{
	protected int $chatId;
	protected Params $params;

	public function __construct(int $chatId)
	{
		$this->chatId = $chatId;
		$this->params = Params::getInstance($chatId);
	}

	public function get(): bool
	{
		$textFieldEnabled = $this->params->get(Params::TEXT_FIELD_ENABLED)?->getValue() ?? true;

		return (bool)$textFieldEnabled;
	}

	public function set(bool $value): self
	{
		if (!$this->chatId)
		{
			return $this;
		}

		if (!$value && $this->params->get(Params::TEXT_FIELD_ENABLED) === null)
		{
			$this->params->addParamByName(Params::TEXT_FIELD_ENABLED, false);
			$this->sendPush();
		}
		elseif ($value && $this->params->get(Params::TEXT_FIELD_ENABLED) !== null)
		{
			$this->params->deleteParam(Params::TEXT_FIELD_ENABLED);
			$this->sendPush();
		}

		return $this;
	}

	protected function sendPush(): void
	{
		$chat = Chat::getInstance($this->chatId);
		$updateField = ['textFieldEnabled' => $this->get()];
		$result = (new ChatFieldsUpdate($chat, $updateField))->send();

		if (!$result->isSuccess())
		{
			$this->params->deleteParam(Params::TEXT_FIELD_ENABLED);
		}
	}
}
