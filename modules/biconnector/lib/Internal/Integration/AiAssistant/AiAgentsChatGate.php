<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/** Isolates Copilot chat creation, rollback and the writing of a new message into a chat the user already has. */
class AiAgentsChatGate
{
	public const BOT_NOT_REGISTERED = 'bot_not_registered';
	public const CHAT_NOT_CREATED = 'chat_not_created';
	public const MESSAGE_NOT_WRITTEN = 'message_not_written';

	public const CHAT_ID_KEY = 'chatId';
	public const BOT_ID_KEY = 'botId';

	private const LOGGER_ID = 'biconnector.aiagents.chat_gate';

	/**
	 * @param array{title: string, prompt: string} $card
	 * @param array|null $pageContext COPILOT_PAGE_CONTEXT of the opening message; null keeps the one of the section.
	 * @return Result Chat ID in CHAT_ID_KEY and the id of the bot that answers in BOT_ID_KEY, or a refusal code.
	 */
	public function launch(int $userId, array $card, ?array $pageContext = null): Result
	{
		$result = new Result();

		$botId = $this->getBotId();
		if ($botId <= 0)
		{
			return $result->addError(new Error('Copilot chat bot is not registered.', self::BOT_NOT_REGISTERED));
		}

		$chatId = $this->createChat($userId, $botId, $card['title']);
		if ($chatId <= 0)
		{
			return $result->addError(new Error('Copilot chat was not created.', self::CHAT_NOT_CREATED));
		}

		try
		{
			$messageId = $this->writeMessage($userId, $chatId, $card['prompt'], $pageContext);
		}
		catch (\Throwable $throwable)
		{
			$this->logMessageWriteFailure($chatId, $throwable);
			$messageId = false;
		}

		if ($messageId === false)
		{
			$this->deleteChat($chatId);

			return $result->addError(new Error('Opening message was not written.', self::MESSAGE_NOT_WRITTEN));
		}

		return $result->setData([self::CHAT_ID_KEY => $chatId, self::BOT_ID_KEY => $botId]);
	}

	/**
	 * Writes the card into the chat of an earlier launch. A chat the user can no longer continue
	 * is not reported: a new chat is created instead, as launch() does.
	 *
	 * @param array{title: string, prompt: string} $card
	 * @return Result Id of the chat the card was written to in CHAT_ID_KEY and the id of the bot that answers
	 * in BOT_ID_KEY, or a refusal code.
	 */
	public function launchInChat(int $userId, int $chatId, array $card, ?array $pageContext = null): Result
	{
		$botId = $chatId > 0 ? $this->getBotId() : 0;
		if ($botId <= 0 || !$this->canContinueChat($userId, $chatId, $botId))
		{
			return $this->launch($userId, $card, $pageContext);
		}

		$result = new Result();

		try
		{
			$messageId = $this->writeMessage($userId, $chatId, $card['prompt'], $pageContext);
		}
		catch (\Throwable $throwable)
		{
			$this->logMessageWriteFailure($chatId, $throwable);
			$messageId = false;
		}

		// The chat holds the earlier dialog of the user, so a failed write never removes it.
		if ($messageId === false)
		{
			return $result->addError(new Error('Message was not written.', self::MESSAGE_NOT_WRITTEN));
		}

		return $result->setData([self::CHAT_ID_KEY => $chatId, self::BOT_ID_KEY => $botId]);
	}

	protected function getBotId(): int
	{
		return Loader::includeModule('im') && Loader::includeModule('imbot')
			? CopilotChatBot::getBotId()
			: 0
		;
	}

	protected function createChat(int $userId, int $botId, string $title): int
	{
		$addResult = ChatFactory::getInstance()->addChat([
			'TYPE' => Chat::IM_TYPE_COPILOT,
			'TITLE' => $title,
			'AUTHOR_ID' => $userId,
			'USERS' => [$userId, $botId],
		]);

		return $addResult->isSuccess() && $addResult->hasResult() ? (int)$addResult->getChatId() : 0;
	}

	/**
	 * Only a Copilot chat the user has started, where the user and the Copilot bot are the only members:
	 * once colleagues are added, the chart data must not be written there.
	 */
	protected function canContinueChat(int $userId, int $chatId, int $botId): bool
	{
		try
		{
			$chat = Chat::getInstance($chatId);
			if (
				!$chat->isExist()
				|| $chat->getType() !== Chat::IM_TYPE_COPILOT
				|| (int)$chat->getAuthorId() !== $userId
			)
			{
				return false;
			}

			$memberIds = array_map('intval', $chat->getRelations()->filterActiveMembers()->getUserIds());
			sort($memberIds);
			$expectedIds = [$userId, $botId];
			sort($expectedIds);

			return $memberIds === $expectedIds;
		}
		catch (\Throwable $throwable)
		{
			$this->log('BI AI agents: copilot chat could not be checked', [
				'chatId' => $chatId,
				'exception' => $throwable,
			]);

			return false;
		}
	}

	protected function writeMessage(int $userId, int $chatId, string $prompt, ?array $pageContext = null): int|false
	{
		return $this->addMessage([
			'FROM_USER_ID' => $userId,
			'TO_CHAT_ID' => $chatId,
			'MESSAGE' => $prompt,
			'MESSAGE_TYPE' => IM_MESSAGE_CHAT,
			'PARAMS' => [
				'COPILOT_PAGE_CONTEXT' => $pageContext ?? [
					'system' => [
						'module' => 'biconnector',
						'url' => AiAgentsSection::getPath(),
					],
				],
			],
			// The opening message is stored before the deferred AI request runs.
			'WAIT_FULL_EXECUTION' => 'N',
		]);
	}

	protected function addMessage(array $fields): int|false
	{
		return \CIMMessenger::Add($fields);
	}

	protected function deleteChat(int $chatId): void
	{
		try
		{
			Chat::getInstance($chatId)->deleteChat();
			$this->log('BI AI agents: rollback of the copilot chat was scheduled', [
				'chatId' => $chatId,
			]);
		}
		catch (\Throwable $throwable)
		{
			$this->log('BI AI agents: rollback of the copilot chat failed', [
				'chatId' => $chatId,
				'exception' => $throwable,
			]);
		}
	}

	protected function logMessageWriteFailure(int $chatId, \Throwable $throwable): void
	{
		$this->log('BI AI agents: opening message was not written', [
			'chatId' => $chatId,
			'exception' => $throwable,
		]);
	}

	private function log(string $message, array $context): void
	{
		try
		{
			(new LoggerFactory())
				->createDefault()
				?->warning($message, ['loggerId' => self::LOGGER_ID, ...$context])
			;
		}
		catch (\Throwable)
		{
		}
	}
}
