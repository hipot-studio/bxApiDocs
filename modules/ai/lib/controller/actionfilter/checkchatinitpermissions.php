<?php declare(strict_types=1);

namespace Bitrix\AI\Controller\ActionFilter;

use Bitrix\AI\Chatbot\Service\ChatService;
use Bitrix\AI\Facade\User;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\SystemException;

class CheckChatInitPermissions extends Base
{
	/**
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		/** @var Action $action */
		$action = $event->getParameter('action');

		$arguments = $action->getArguments();
		$chatService = $arguments['chatService'] ?? null;

		if (empty($chatService) || !($chatService instanceof ChatService))
		{
			$this->addError(new Error('Missing chatService.'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$chatId = (int)$action->getController()->getRequest()->get('chatId');
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = $chatService->getChatById($chatId);
		if ($chat === null)
		{
			return null;
		}

		$userId = User::getCurrentUserId();
		if ($chat->getAuthorId() !== $userId)
		{
			$this->addError(new Error("User '{$userId}' does not have permissions to chat {$chat->getId()}."));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
