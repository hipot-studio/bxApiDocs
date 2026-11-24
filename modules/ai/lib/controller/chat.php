<?php declare(strict_types=1);

namespace Bitrix\AI\Controller;

use Bitrix\AI\Chatbot\Dto\ChatInitDto;
use Bitrix\AI\Chatbot\Enum\ChatInputStatus;
use Bitrix\AI\Chatbot\Message;
use Bitrix\AI\Chatbot\Service\ChatService;
use Bitrix\AI\Controller\ActionFilter\CheckChatInitPermissions;
use Bitrix\AI\Controller\ActionFilter\CheckChatPermissions;
use Bitrix\AI\Facade\User;
use Bitrix\AI\Parameter\DefaultParameter;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;

class Chat extends Controller
{
	private const ERROR_EMPTY_MESSAGE_CONTENT = 'AI_CHAT_MESSAGE_CONTENT_REQUIRED';

	public function configureActions(): array
	{
		return [
			'init' => [
				'+prefilters' => [
					new CheckChatInitPermissions(),
				],
			],
			'getMessages' => [
				'+prefilters' => [
					new CheckChatPermissions(),
				],
			],
			'sendMessage' => [
				'+prefilters' => [
					new CheckChatPermissions(),
				],
			],
			'markMessageAsViewed' => [
				'+prefilters' => [
					new CheckChatPermissions(),
				],
			],
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new DefaultParameter(),
			new ValidationParameter(
				ChatInitDto::class,
				function (): ChatInitDto {
					$request = $this->getRequest();

					return ChatInitDto::createFromRequest($request, User::getCurrentUserId());
				}
			),
		];
	}

	/**
	 * Creates or retrieves a chat of the scenario bound to an entity, or an existing chat by chatId.
	 * Request fields: scenarioCode, entityType, entityId or chatId, parameters.
	 *
	 * @param ChatService $chatService
	 * @param ChatInitDto $chatInitDto
	 * @return array{chat: array{id: int, inputStatus: string}, messages: array, userPhoto: string|null}|null
	 */
	public function initAction(ChatService $chatService, ChatInitDto $chatInitDto): ?array
	{
		try
		{
			$chat = $chatService->initChat($chatInitDto);
			$messages = $chatService->getMessages($chat->getId());
		}
		catch (SystemException $exception)
		{
			$this->addError(new Error($exception->getMessage()));

			return null;
		}

		return [
			'chat' => [
				'id' => $chat->getId(),
				'inputStatus' => $chat->getInputStatus(),
			],
			'messages' => $messages,
			'userPhoto' => User::getUserPhoto(User::getCurrentUserId()),
		];
	}

	/**
	 * Returns chat messages older than offsetMessageId, oldest first.
	 *
	 * @param ChatService $chatService
	 * @param int $chatId
	 * @param int $offsetMessageId Message id to page from; 0 for the latest messages.
	 * @param int $limit
	 * @return array{messages: \Bitrix\AI\Chatbot\Dto\MessageDto[]}
	 */
	public function getMessagesAction(ChatService $chatService, int $chatId, int $offsetMessageId = 0, int $limit = 10): array
	{
		$messages = $chatService->getMessages($chatId, $limit, $offsetMessageId);

		return [
			'messages' => $messages,
		];
	}



	/**
	 * Sends a user message, or a button click when messageId and buttonId are passed, and locks the input.
	 *
	 * @param ChatService $chatService
	 * @param int $chatId
	 * @param array $messageData Keys: content (required non-empty string), messageId, buttonId.
	 * @return array{message: \Bitrix\AI\Chatbot\Dto\MessageDto}|null
	 */
	public function sendMessageAction(ChatService $chatService, int $chatId, array $messageData): ?array
	{
		$content = $messageData['content'] ?? null;
		if (!is_string($content) || $content === '')
		{
			$this->addError(new Error('Message content is required.', self::ERROR_EMPTY_MESSAGE_CONTENT));

			return null;
		}

		if (!empty($messageData['messageId']) && isset($messageData['buttonId']))
		{
			$command = $chatService->getCommandData($chatId, (int)$messageData['messageId'], (int)$messageData['buttonId']);
			$message = new Message\ButtonClickedMessage(
				$content,
				(int)$messageData['messageId'],
				(int)$messageData['buttonId'],
				$command
			);
		}
		else
		{
			$message = new Message\DefaultMessage($content);
		}

		try
		{
			$chatService->setChatInputStatus($chatId,ChatInputStatus::Lock);
			if (!empty($messageData['messageId']) && isset($messageData['buttonId']))
			{
				$chatService->buttonClicked($chatId, (int)$messageData['messageId'], (int)$messageData['buttonId']);
			}
			$messageId = $chatService->sendMessage($chatId, User::getCurrentUserId(), $message);
			$messageDto = $chatService->getMessageById($chatId, $messageId);

			return [
				'message' => $messageDto,
			];
		}
		catch (ObjectNotFoundException|AccessDeniedException|LoaderException|ArgumentException $e)
		{
			$this->addError(new Error($e->getMessage()));

			return null;
		}
	}


	/**
	 * Marks messages as viewed.
	 *
	 * @param ChatService $chatService
	 * @param int $chatId
	 * @param array $messagesIds Numeric message ids.
	 * @return void
	 */
	public function markMessageAsViewedAction(ChatService $chatService, int $chatId, array $messagesIds): void
	{
		foreach ($messagesIds as $messageId)
		{
			if (!is_numeric($messageId))
			{
				$this->addError(new Error('MessageIds contains invalid ids.'));

				return;
			}
		}

		$chatService->markMessageAsViewed($chatId, $messagesIds);
	}
}
