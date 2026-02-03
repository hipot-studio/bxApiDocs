<?php

namespace Bitrix\Im\V2\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;
use Bitrix\Pull\Error;

/**
 * @internal
 */
class Sender
{
	/**
	 * @internal
	 * @see \Bitrix\Im\V2\Pull\Event::send()
	 * @param Event $event
	 * @return Result
	 */
	public function send(Event $event): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new \Bitrix\Im\V2\Error(\Bitrix\Im\V2\Error::PULL_NOT_INSTALLED));
		}

		$results = [];

		if ($event->isGlobal())
		{
			return $this->sendGlobal($event);
		}

		$chat = $event->getTarget();

		if ($chat !== null && !$event->shouldSendToOnlySpecificRecipients())
		{
			$results = $this->processPublicSending($chat, $event);
		}

		foreach ($event->getPullByUsers() as $group)
		{
			$results[] = $this->sendPull($group->getRecipients(), $group->getParams());
		}

		if ($this->shouldSendMobilePush($chat, $event))
		{
			foreach ($event->getMobilePushByUsers() as $group)
			{
				$results[] = $this->sendPush($group->getRecipients(), $group->getParams());
			}
		}

		if ($event->shouldSendImmediately())
		{
			$results[] = $this->sendImmediately();
		}

		return Result::merge(...$results);
	}

	protected function processPublicSending(Chat $chat, Event $event): array
	{
		$results = [];
		$pull = $event->getPullForPublic();

		if ($chat->needToSendPublicPull())
		{
			$results[] = $this->sendByTag('IM_PUBLIC_'. $chat->getChatId(), $pull);
		}
		if ($chat->getType() === Chat::IM_TYPE_OPEN_CHANNEL)
		{
			$results[] = $this->sendSharedPull($pull);
		}
		if ($chat->getType() === Chat::IM_TYPE_COMMENT)
		{
			$results[] = $this->sendByTag('IM_PUBLIC_COMMENT_' . $chat->getParentChatId(), $pull);
		}

		return $results;
	}

	protected function shouldSendMobilePush(?Chat $chat, Event $event): bool
	{
		return
			$event->shouldSendMobilePush()
			&& $chat !== null
			&& !($chat instanceof Chat\CommentChat)
		;
	}

	protected function sendGlobal(Event $event): Result
	{
		return self::getPullEventResult(\CPullStack::AddShared($event->getPullForPublic()));
	}

	protected function sendByTag(string $tag, array $pull): Result
	{
		return self::getPullEventResult(\CPullWatch::AddToStack($tag, $pull));
	}

	protected function sendSharedPull(array $pull): Result
	{
		return self::getPullEventResult(Chat\OpenChannelChat::sendSharedPull($pull));
	}

	protected function sendPull(array $recipients, array $pull): Result
	{
		return self::getPullEventResult(\Bitrix\Pull\Event::add($recipients, $pull));
	}

	protected function sendPush(array $recipients, array $push): Result
	{
		return self::getPullEventResult(\Bitrix\Pull\Push::add($recipients, $push));
	}

	protected static function getPullEventResult(bool $isSuccess): Result
	{
		if ($isSuccess)
		{
			return new Result();
		}

		$error = \Bitrix\Pull\Event::getLastError();
		if ($error instanceof Error)
		{
			return (new Result())->addError(new \Bitrix\Main\Error($error->msg, $error->code, $error->params));
		}

		return new Result();
	}

	protected function sendImmediately(): Result
	{
		$result = new Result();

		try
		{
			\Bitrix\Pull\Event::send();
		}
		catch (\Exception $exception)
		{
			$result->addError(new \Bitrix\Main\Error($exception->getMessage(), $exception->getCode()));
		}

		return $result;
	}
}
