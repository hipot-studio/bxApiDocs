<?php
namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\Im\V2\Chat;

class Message extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Message.startSession
	 */
	public function startSessionAction(Chat $chat, int $messageId): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->startSessionByMessage($messageId))
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();
		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Message.addSession
	 * @internal
	 */
	public function addSessionAction(Chat $chat, int $messageId): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		$result = $operator->openNewDialogByMessage($messageId);
		if ($result)
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();
		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}
}
