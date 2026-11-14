<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\Im\V2\Analytics\CopilotAnalytics;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Copilot\Event\RegenerateAnswerEvent;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;

class CopilotMessageService
{
	/**
	 * The aiassistant subscriber performs the repeat synchronously inside send() and may report
	 * back what it did with the attempt. An empty result means nobody decided: the subscriber is
	 * absent or still returns nothing, and the caller keeps the previous "request accepted" answer.
	 *
	 * @return Result<array{decision?: RegenerateDecision, reason?: string, errorText?: string}>
	 */
	public function sendRegenerateEvent(Chat $chat, Message $message, Message $triggerMessage, int $userId): Result
	{
		$event = new RegenerateAnswerEvent($chat, $message, $triggerMessage, $userId);
		$event->send();

		// BitrixGPT v2 ai-label: message_regenerate for copilot chats under flag
		if ($chat instanceof CopilotChat && Features::isBitrixGptV2Available())
		{
			$context = new Context();
			$context->setUserId($userId);

			(new CopilotAnalytics($chat))
				->withContext($context)
				->addMessageRegenerate($message, $triggerMessage)
			;
		}

		/** @var Result<array{decision?: RegenerateDecision, reason?: string, errorText?: string}> $result */
		$result = new Result();

		$decision = $event->getDecision();
		if ($decision === null)
		{
			return $result->setResult([]);
		}

		$data = ['decision' => $decision];

		$reason = $event->getReason();
		if ($reason !== null)
		{
			$data['reason'] = $reason;
		}

		$errorText = $event->getErrorText();
		if ($errorText !== null)
		{
			$data['errorText'] = $errorText;
		}

		return $result->setResult($data);
	}
}
