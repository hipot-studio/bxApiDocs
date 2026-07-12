<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message;

use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Vote\VoteError;
use Bitrix\Im\V2\Message\Vote\VoteService;
use Bitrix\Main\DI\ServiceLocator;

class Vote extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Message.Vote.send
	 */
	public function sendAction(Message $message, string $value): ?array
	{
		$vote = \Bitrix\Im\V2\Message\Vote\Vote::tryFrom($value);
		if ($vote === null)
		{
			$this->addError(new VoteError(VoteError::INVALID_VALUE));

			return null;
		}

		ServiceLocator::getInstance()
			->get(VoteService::class)
			->withContextUser($this->getCurrentUser()->getId())
			->send($message, $vote)
		;

		return ['result' => true];
	}
}
