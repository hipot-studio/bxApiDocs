<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Copilot;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\Copilot\DraftChatService;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Chat\Message;
use Bitrix\Im\V2\Controller\Chat\Pin;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\GlobalAction;

class DraftChat extends BaseController
{
	/**
	 * @restMethod im.v2.Copilot.DraftChat.get
	 */
	public function getAction(
		int $messageLimit = Message::DEFAULT_LIMIT,
		int $pinLimit = Pin::DEFAULT_LIMIT,
	): ?array
	{
		if (!Features::isCopilotDraftChatAvailable())
		{
			$this->addError(new CopilotError(CopilotError::DRAFT_CHAT_NOT_AVAILABLE));

			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		if (!Permission::canDoGlobalAction($userId, GlobalAction::CreateChat, ['TYPE' => Chat::IM_TYPE_COPILOT]))
		{
			$this->addError(new ChatError(ChatError::ACCESS_DENIED));

			return null;
		}

		$addResult = (new DraftChatService())->getOrCreate($userId);

		if (!$addResult->isSuccess())
		{
			$this->addErrors($addResult->getErrors());

			return null;
		}

		$chat = $addResult->getChat();

		return $this->load($chat, $messageLimit, $pinLimit);
	}
}
