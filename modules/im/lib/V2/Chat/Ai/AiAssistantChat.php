<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Ai;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Message\Send\SendingService;
use Bitrix\Main\Localization\Loc;

class AiAssistantChat extends AiAssistantBaseChat
{
	protected bool $isConvertedRecently = false;

	protected function getDefaultType(): string
	{
		return self::IM_TYPE_AI_ASSISTANT;
	}

	public function markAsConverted(): self
	{
		$this->isConvertedRecently = true;

		return $this;
	}

	protected function onAfterMessageSend(Message $message, SendingService $sendingService): void
	{
		$this->handleJustConversion();

		parent::onAfterMessageSend($message, $sendingService);
	}

	protected function handleJustConversion(): void
	{
		if (!$this->isConvertedRecently)
		{
			return;
		}

		$this->isConvertedRecently = false;
		$this->sendAssistantGreetingMessage();
	}

	protected function sendAssistantGreetingMessage(): void
	{
		$assistantBotId = $this->aiAssistantService->getBotId();
		$messageText =  Loc::getMessage('IM_CHAT_CONVERSION_TO_AI_ASSISTANT_MESSAGE');

		if ($messageText)
		{
			$message =
				(new Message())
					->setMessage($messageText)
					->setAuthorId(0)
					->setContextUser($assistantBotId)
					->disableNotify()
			;

			$sendingConfig = new SendingConfig(['PUSH' => 'N']);

			$this->sendMessage($message, $sendingConfig);
		}
	}
}
