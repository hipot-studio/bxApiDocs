<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller\Chat\Message;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Reaction\ReactionItem;
use Bitrix\Im\V2\Message\Reaction\ReactionService;
use Bitrix\Imbot\V2\Controller\BotController;

class Reaction extends BotController
{
	/**
	 * @restMethod imbot.v2.Chat.Message.Reaction.add
	 */
	public function addAction(
		Message $message,
		string $reaction,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$reaction = ReactionItem::getSnakeCaseName($reaction);

		$reactionService = new ReactionService($message);
		$reactionService->setContext($this->getBotContext());
		$result = $reactionService->addReaction($reaction);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.Reaction.delete
	 */
	public function deleteAction(
		Message $message,
		string $reaction,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$reaction = ReactionItem::getSnakeCaseName($reaction);

		$reactionService = new ReactionService($message);
		$reactionService->setContext($this->getBotContext());
		$result = $reactionService->deleteReaction($reaction);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
