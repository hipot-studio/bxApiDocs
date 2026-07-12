<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Copilot;

use Bitrix\Im\V2\Chat\Copilot\CopilotMessageService;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class Message extends BaseController
{
	/**
	 * @restMethod im.v2.Copilot.Message.regenerate
	 */
	public function regenerateAction(\Bitrix\Im\V2\Message $message): ?array
	{
		if (!Loader::includeModule('imbot'))
		{
			$this->addError(new CopilotError(CopilotError::AI_NOT_AVAILABLE));

			return null;
		}

		if (!$message->getChat() instanceof CopilotChat)
		{
			$this->addError(new CopilotError(CopilotError::WRONG_CHAT_TYPE));

			return null;
		}

		if ($message->getAuthorId() !== CopilotChatBot::getBotId())
		{
			$this->addError(new CopilotError(CopilotError::NOT_BOT_MESSAGE));

			return null;
		}

		$triggerMessageId = $message->getParams()
			->get(Params::COPILOT_TRIGGER_MESSAGE_ID)
			->getValue()
		;

		if (empty($triggerMessageId))
		{
			$this->addError(new CopilotError(CopilotError::REGENERATE_NOT_AVAILABLE));

			return null;
		}

		$triggerMessage = new \Bitrix\Im\V2\Message($triggerMessageId);
		if (
			!$triggerMessage->getMessageId()
			|| !$triggerMessage->checkAccess($this->getCurrentUser()->getId())->isSuccess()
		)
		{
			$this->addError(new CopilotError(CopilotError::REGENERATE_NOT_AVAILABLE));

			return null;
		}

		ServiceLocator::getInstance()->get(CopilotMessageService::class)->sendRegenerateEvent(
			$message->getChat(),
			$message,
			$triggerMessage,
			$this->getCurrentUser()->getId()
		);

		return ['result' => true];
	}
}
