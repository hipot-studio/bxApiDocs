<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller\Chat;

use Bitrix\Im\Bot;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\PlatformContext;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message\Delete\DeleteService;
use Bitrix\Im\V2\Message\Delete\DeletionMode;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Message\MessageService;
use Bitrix\Im\V2\Message\Send\FieldsValidationService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Message\Forward\ForwardService;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Imbot\V2\Controller\BotController;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;

class Message extends BotController
{
	private const MAX_FORWARD_MESSAGES = 100;
	private const MESSAGE_ON_PAGE_COUNT = 50;

	private const ALLOWED_FIELDS_SEND = [
		'MESSAGE',
		'ATTACH',
		'SYSTEM',
		'KEYBOARD',
		'URL_PREVIEW',
		'SKIP_CONNECTOR',
		'SILENT_CONNECTOR',
		'TEMPLATE_ID',
		'REPLY_ID',
	];

	private const ALLOWED_FIELDS_UPDATE = [
		'MESSAGE',
		'ATTACH',
		'KEYBOARD',
		'URL_PREVIEW',
	];

	public function getAutoWiredParameters(): array
	{
		return array_merge([
			new ExactParameter(
				MessageCollection::class,
				'forwardMessages',
				function ($className, array $fields) {
					$forwardIds = $fields['forwardIds'] ?? [];

					if (empty($forwardIds))
					{
						return null;
					}

					if (count($forwardIds) > self::MAX_FORWARD_MESSAGES)
					{
						$this->addError(new MessageError(MessageError::TOO_MANY_MESSAGES));

						return null;
					}

					$forwardIds = array_map('intval', $forwardIds);
					$messageCollection = new MessageCollection($forwardIds);

					if ($this->botId > 0)
					{
						foreach ($messageCollection as $message)
						{
							$message->setContextUser($this->botId);
						}
					}

					foreach ($messageCollection as $message)
					{
						$messageId = $message->getId();
						$uuid = array_search($messageId, $forwardIds, true);
						if ($uuid)
						{
							$message->setForwardUuid($uuid);

							if ($message->getForwardUuid() === null)
							{
								$this->addError(new MessageError(MessageError::WRONG_UUID));

								return null;
							}
						}
					}

					return $messageCollection;
				}
			),
		], parent::getAutoWiredParameters());
	}

	public function configureActions(): array
	{
		return [
			'send' => [
				'+prefilters' => [
					new CheckActionAccess(Action::Send),
					new PlatformContext(),
				],
			],
			'update' => [
				'+prefilters' => [
					new PlatformContext(),
				],
			],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.send
	 */
	public function sendAction(
		Chat $chat,
		?\CRestServer $restServer = null,
		array $fields = [],
		?MessageCollection $forwardMessages = null,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$rawFields = $this->getRawValue('fields');
		if (isset($rawFields['message']))
		{
			$fields['message'] = $rawFields['message'];
		}
		$fields = $this->prepareFields($fields, self::ALLOWED_FIELDS_SEND);

		foreach (['SYSTEM', 'URL_PREVIEW', 'SKIP_CONNECTOR', 'SILENT_CONNECTOR'] as $boolField)
		{
			if (isset($fields[$boolField]))
			{
				$fields[$boolField] = self::normalizeBooleanVariable($fields[$boolField]) ? 'Y' : 'N';
			}
		}

		$fields['BOT_ID'] = $this->getBotId();

		$result = (new FieldsValidationService($chat, $fields, $restServer))
			->prepareFields($forwardMessages);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$fields = $result->getResult();
		$fields['SKIP_USER_CHECK'] = 'Y';

		$messageId = \CIMMessenger::Add($fields);

		if ($forwardMessages?->count() > 0)
		{
			$forwardResult = (new ForwardService($chat))
				->setContext($this->getBotContext())
				->createMessages($forwardMessages);

			if (!$forwardResult->isSuccess())
			{
				$this->addErrors($forwardResult->getErrors());

				return null;
			}
		}

		if ($messageId === false && !isset($forwardResult))
		{
			$this->addError(new MessageError(MessageError::SENDING_FAILED));

			return null;
		}

		return [
			'id' => $messageId ?: null,
			'uuidMap' => isset($forwardResult) ? $forwardResult->getResult() : [],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.update
	 */
	public function updateAction(
		\Bitrix\Im\V2\Message $message,
		array $fields = [],
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$rawFields = $this->getRawValue('fields');
		if (isset($rawFields['message']))
		{
			$fields['message'] = $rawFields['message'];
		}
		$fields = $this->prepareFields($fields, self::ALLOWED_FIELDS_UPDATE);

		$fields['MESSAGE_ID'] = $message->getMessageId();
		$fields['BOT_ID'] = $this->getBotId();
		$fields['SKIP_USER_CHECK'] = 'Y';

		$updateService = new \Bitrix\Im\V2\Message\Update\UpdateService($message);
		$updateService->setContext($this->getBotContext());
		$result = $updateService->update($fields);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.delete
	 */
	public function deleteAction(
		\Bitrix\Im\V2\Message $message,
		$complete = 'N',
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$messages = new MessageCollection();
		$messages->add($message);
		$deleteService = new DeleteService($messages);
		$deleteService->setContext($this->getBotContext());

		if (self::normalizeBooleanVariable($complete))
		{
			$deleteService->setMode(DeletionMode::Complete);
		}

		$result = $deleteService->delete();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.get
	 */
	public function getAction(
		\Bitrix\Im\V2\Message $message,
	): ?array
	{
		if (!$this->assertObserverBotType())
		{
			return null;
		}

		return $this->filterOutput([
			'message' => $message->toRestFormat(['MESSAGE_ONLY_COMMON_FIELDS' => true]),
			'user' => User::getInstance($message->getAuthorId())->toRestFormat(),
		]);
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.getContext
	 */
	public function getContextAction(
		\Bitrix\Im\V2\Message $message,
		int $range = self::MESSAGE_ON_PAGE_COUNT,
	): ?array
	{
		if (!$this->assertObserverBotType())
		{
			return null;
		}

		$range = min(max($range, 1), 50);

		$messageService = new MessageService($message);
		$messages = $messageService
			->getMessageContext($range, \Bitrix\Im\V2\Message::REST_FIELDS)
			->getResult();

		$formattedMessages = [];
		$userIds = [];
		foreach ($messages as $msg)
		{
			$formattedMessages[] = $msg->toRestFormat(['MESSAGE_ONLY_COMMON_FIELDS' => true]);

			$authorId = $msg->getAuthorId();
			if ($authorId > 0)
			{
				$userIds[$authorId] = $authorId;
			}
		}

		$users = [];
		foreach ($userIds as $userId)
		{
			$users[] = User::getInstance($userId)->toRestFormat();
		}

		$result = [
			'messages' => $formattedMessages,
			'users' => $users,
		];

		$result = $this->filterOutput($result);

		return $messageService->fillContextPaginationData($result, $messages, $range);
	}

	private function assertObserverBotType(): bool
	{
		$botType = $this->botData['TYPE'] ?? '';
		if (!in_array($botType, [Bot::TYPE_SUPERVISOR, Bot::TYPE_PERSONAL], true))
		{
			$this->addError(new Error(
				'Method is available only for supervisor and personal bots',
				'BOT_TYPE_NOT_ALLOWED',
			));

			return false;
		}

		return true;
	}

	/**
	 * @restMethod imbot.v2.Chat.Message.read
	 */
	public function readAction(
		Chat $chat,
		?\Bitrix\Im\V2\Message $message = null,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		// todo: delete later
		if (!class_exists(\Bitrix\Im\V2\Reading\Reader::class))
		{
			$botUserId = $this->getBotUserId();
			$messages = null;

			if ($message !== null)
			{
				$messages = (new MessageCollection())->add($message);
			}

			$readResult = $chat->withContextUser($botUserId)->readMessages($messages);

			$viewedMessages = $readResult->getResult()['VIEWED_MESSAGES'];

			if ($viewedMessages instanceof \Bitrix\Im\V2\MessageCollection)
			{
				$viewedMessagesIds = $viewedMessages->getIds();
			}
			else
			{
				$viewedMessagesIds = $viewedMessages;
			}

			return [
				'chatId' => $chat->getId(),
				'lastId' => $chat->getLastId(),
				'counter' => $readResult->getResult()['COUNTER'],
				'viewedMessages' => $viewedMessagesIds ?? [],
			];
		}

		$reader = \Bitrix\Main\DI\ServiceLocator::getInstance()->get(\Bitrix\Im\V2\Reading\Reader::class);
		$botUserId = $this->getBotUserId();

		if ($message !== null)
		{
			$readResult = $reader->readTo($message, $botUserId);
		}
		else
		{
			$readResult = $reader->readAllInChat($chat->getChatId(), $botUserId);
		}

		return $this->formReadResult($chat, $readResult);
	}
}
