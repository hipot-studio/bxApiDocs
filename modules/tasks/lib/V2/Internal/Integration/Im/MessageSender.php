<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Event\Chat\OnAfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Integration\Im\Service\SendResultAdapter;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Result\Result;

class MessageSender implements MessageSenderInterface
{
	private const DEFAULT_AUTHOR_ID = 0;

	public function __construct(
		private readonly Action\CounterRecipientsResolver $counterRecipientsResolver,
		private readonly Action\NotificationRecipientsResolver $notificationRecipientsResolver,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly SendResultAdapter $sendResultAdapter,
		private readonly EventDispatcher $eventDispatcher,
	)
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/V2/Internal/Integration/Im/ChatNotification.php');
	}

	public function sendMessage(Entity\Task $task, Action\AbstractNotify $notification): Result
	{
		if (!Loader::includeModule('im'))
		{
			return $this->makeFailureResult(
				new Error('Module IM is required', 400)
			);
		}

		if ($task->chatId === null)
		{
			$chatEntity = $this->chatRepository->getByTaskId($task->id);

			$task = $task->cloneWith(['chatId' => $chatEntity?->getId()]);
		}

		if ($task->chatId === null)
		{
			return $this->makeFailureResult(
				new Error('Task chat not found')
			);
		}

		$chat = Chat::getInstance($task->chatId);

		$notificationRecipients = $this->notificationRecipientsResolver->resolve($notification, $task)->getIds();
		$config = (new SendingConfig())
			->enableSkipUrlIndex()
			->setCounterRecipients($this->counterRecipientsResolver->resolve($notification, $task)->getIds())
			->setPushRecipients($notificationRecipients)
		;

		if ($notification->shouldDisableGenerateUrlPreview())
		{
			$config->disableGenerateUrlPreview();
		}
		if($notification->shouldDisableAddRecent())
		{
			$config->disableAddRecent();
		}

		$contextUserId = $notification->getTriggeredBy()?->getId() ?? $this->getAuthorId($notification);
		$message = (new Message())
			->setMessage((string)$notification)
			->setAuthorId($this->getAuthorId($notification))
			->setContextUser($contextUserId)
			->disableNotify()
			->setImportantFor($notificationRecipients)
		;

		$keyboard = $notification->getKeyboard();
		if ($keyboard !== null)
		{
			$message->setKeyboard($keyboard);
		}

		$attach = $notification->getAttach();
		if ($attach !== null)
		{
			$message->setAttach($attach);
		}

		if ($notification->getDisableNotify())
		{
			$message->disableNotify();
			$config->disallowSendPush();
		}

		$messageParams = $notification->getMessageParams();
		if (!empty($messageParams))
		{
			foreach ($messageParams as $name => $value)
			{
				$message->addParam($name, $value);
			}
		}

		$sendMessageResult = $chat->withContextUser($contextUserId)->sendMessage($message, $config);

		if ($sendMessageResult->isSuccess())
		{
			$this->eventDispatcher->dispatch(new OnAfterSendMessageEvent($task, $message, $notification));
		}

		return $this->sendResultAdapter->transform($sendMessageResult);
	}

	private function makeFailureResult(Error $error): Result
	{
		$result = new Result();
		$result->addError($error);

		return $result;
	}

	private function getAuthorId(Action\AbstractNotify $notification): int
	{
		if (method_exists($notification, 'getAuthorId'))
		{
			return call_user_func([$notification, 'getAuthorId']);
		}

		return self::DEFAULT_AUTHOR_ID;
	}
}
