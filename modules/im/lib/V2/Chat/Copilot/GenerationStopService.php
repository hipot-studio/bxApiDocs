<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\AiAssistant\Core\Service\SessionManager;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

/**
 * Requests a stop of the active BitrixGPT generation in a one-to-one copilot chat. This
 * service stays thin: it resolves the active aiassistant session (for idempotency, the
 * initiator check and the event payload) and publishes the im `OnCopilotGenerationStop`
 * event. The actual stop — finalizing the pending "thinking" placeholder, dropping the
 * session, marking the context as user-stopped, the system message and the terminal pull
 * signal — is performed by the aiassistant subscriber synchronously inside the event send,
 * so the stop is already done when this method returns "Stopped". Idempotent: a repeated
 * stop finds no active session and only reports the current state.
 */
class GenerationStopService
{
	private const MODULE_ID = 'im';
	private const EVENT_ON_GENERATION_STOP = 'OnCopilotGenerationStop';

	// A one-to-one copilot chat holds exactly the user and the bot; CopilotChat extends
	// GroupChat, so the type alone does not exclude multi-user copilot chats.
	private const ONE_TO_ONE_USER_COUNT = 2;

	/**
	 * @return Result<array{status: string, initiatorId: ?int}> actual state after the operation
	 */
	public function stop(Chat $chat, int $userId): Result
	{
		/** @var Result<array{status: string, initiatorId: ?int}> $result */
		$result = new Result();

		if (!$chat instanceof CopilotChat || $chat->getUserCount() > self::ONE_TO_ONE_USER_COUNT)
		{
			return $result->addError(new CopilotError(CopilotError::WRONG_CHAT_TYPE));
		}

		if (!Features::isBitrixGptStopGenerationAvailable() || !Loader::includeModule('aiassistant'))
		{
			return $result->addError(new CopilotError(CopilotError::AI_NOT_AVAILABLE));
		}

		$chatId = (int)$chat->getChatId();
		$session = $this->findActiveSession($chatId);
		if ($session === null)
		{
			// Idempotent repeat / generation already finished on its own: no event.
			return $result->setResult($this->getCurrentState($chatId));
		}

		if ((int)$session['userId'] !== $userId)
		{
			// Not the caller's generation: report the neutral "nothing of mine to stop" state
			// instead of ACCESS_DENIED, so the response does not reveal that a foreign generation
			// is active. In a one-to-one copilot chat (the only supported scenario here) a foreign
			// session cannot occur; this is a defensive branch for any future multi-user reuse.
			return $result->setResult([
				'status' => GenerationStatus::Completed->value,
				'initiatorId' => null,
			]);
		}

		// The aiassistant subscriber performs the stop synchronously inside send(): finalize
		// placeholder, drop session, mark context, system message and the terminal pull signal.
		$this->sendStopEvent(
			(string)$session['contextId'],
			$chatId,
			$userId,
			(int)$session['messageId'],
		);

		return $result->setResult([
			'status' => GenerationStatus::Stopped->value,
			'initiatorId' => $userId,
		]);
	}

	/**
	 * Posts the stop notice as a plain "from System" message (author_id=0 => isSystem on clients),
	 * so web and mobile render it with their native system style. The phrase is owned by the caller
	 * (aiassistant) and passed in — im does not localize it here.
	 */
	public function postStopSystemMessage(Chat $chat, string $text): void
	{
		$chatId = (int)$chat->getChatId();
		if ($chatId <= 0 || $text === '')
		{
			return;
		}

		\CIMMessenger::Add([
			'TO_CHAT_ID' => $chatId,
			'MESSAGE_TYPE' => $chat->getType(),
			'FROM_USER_ID' => 0,
			'SYSTEM' => 'Y',
			'MESSAGE' => $text,
			'PUSH' => 'N',
			'SKIP_USER_CHECK' => 'Y',
			'SKIP_COUNTER_INCREMENTS' => 'Y',
			'PARAMS' => [
				Params::NOTIFY => 'N',
			],
		]);
	}

	/**
	 * @return array{status: string, initiatorId: ?int}
	 */
	private function getCurrentState(int $chatId): array
	{
		$session = $this->findActiveSession($chatId);
		if ($session !== null)
		{
			return [
				'status' => GenerationStatus::Generating->value,
				'initiatorId' => (int)$session['userId'],
			];
		}

		// No active session: the generation has already reached a terminal state on its own.
		return [
			'status' => GenerationStatus::Completed->value,
			'initiatorId' => null,
		];
	}

	private function sendStopEvent(string $contextId, int $chatId, int $userId, int $messageId): void
	{
		$event = new Event(
			self::MODULE_ID,
			self::EVENT_ON_GENERATION_STOP,
			[
				'contextId' => $contextId,
				'chatId' => $chatId,
				'userId' => $userId,
				'messageId' => $messageId,
			]
		);
		$event->send();
	}

	/**
	 * The aiassistant half of the stop contract (this lookup plus the OnCopilotGenerationStop
	 * subscriber) ships with the aiassistant module on its own schedule. Until it lands, treat the
	 * missing lookup as "no active session" so enabling the feature flag early cannot fatal.
	 *
	 * @return array{contextId: string, chatId: int, userId: int, messageId: int}|null
	 */
	private function findActiveSession(int $chatId): ?array
	{
		if (!method_exists(SessionManager::class, 'findActiveByChatId'))
		{
			return null;
		}

		return $this->getSessionManager()->findActiveByChatId($chatId);
	}

	private function getSessionManager(): SessionManager
	{
		return ServiceLocator::getInstance()->get(SessionManager::class);
	}
}
