<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\SendMessageDto;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationService;

class SendMessageTool extends BaseImTool
{
	public function getName(): string
	{
		return 'send_message';
	}

	public function getDescription(): string
	{
		return "Sends a message to a specified chat or user. 
		Use this tool when you need to send a prepared text to a specific dialog.
		IMPORTANT: This action is irreversible and requires user confirmation.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dialogId' => [
					'description' => 'The unique identifier of the dialog. 
					For a private chat, this is the user\'s ID (e.g., "123"). 
					For a group chat, it is the chat\'s ID prefixed with "chat" (e.g., "chat456").',
					'type' => 'string',
				],
				'message' => [
					'description' => 'The full content of the message to be sent. 
						You must formulate this text based on the user\'s original request.',
					'type' => 'string',
				],
			],
			'additionalProperties' => false,
			'required' => ['dialogId', 'message'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		/** @var ValidationService $validation */
		$validation = ServiceLocator::getInstance()->get('main.validation.service');

		$sendMessageDto = SendMessageDto::createFromParams($args);
		$validationResult = $validation->validate($sendMessageDto);

		if (!$validationResult->isSuccess())
		{
			return "Validation error occurred. Please check your input and try again.";
		}

		$result = $this->sendMessage($userId, $sendMessageDto);

		return $result->isSuccess()
			? "Message successfully sent to dialog: '{$sendMessageDto->dialogId}'"
			: "Error sending message " . implode(", ", $result->getErrorMessages());
	}

	private function sendMessage(int $authorId, SendMessageDto $sendMessageDto): Result
	{
		$result = new Result();
		$chatId = Dialog::getChatId($sendMessageDto->dialogId, $authorId);

		if (!$chatId)
		{
			return $result->addError(new Error(Chat\ChatError::NOT_FOUND));
		}

		$chat = Chat::getInstance($chatId);

		if (!$chat->isExist())
		{
			return $result->addError(new Error(Chat\ChatError::NOT_FOUND));
		}

		$context = (new Context())->setUserId($authorId);
		$chat->setContext($context);

		if (!$chat->canDo(Action::Send))
		{
			return $result->addError(new Error(Chat\ChatError::ACCESS_DENIED));
		}

		$message = (new Message())
			->setMessage($sendMessageDto->message)
			->setChat($chat)
			->setAuthorId($authorId)
		;

		return $chat->sendMessage($message);
	}
}
