<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\MessageEvent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\FavoriteChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Reaction\ReactionService;

class MessageAnalytics extends ChatAnalytics
{
	protected const SEND_MESSAGE = 'send_message';
	protected const ADD_REACTION = 'add_reaction';
	protected const SHARE_MESSAGE = 'share_message';
	protected const DELETE_MESSAGE = 'delete_message';
	protected const MENTION_ALL = 'mention_all';

	protected Message $message;

	public function __construct(Message $message)
	{
		parent::__construct($message->getChat());
		$this->message = $message;
	}

	public function addSendMessage(): void
	{
		$this->async(function () {
			if ($this->message->getMessageId() === null)
			{
				return;
			}

			if ($this->message->isForward() || $this->message->isSystem())
			{
				return;
			}

			$this
				->createMessageEvent(self::SEND_MESSAGE)
				?->setType((new MessageContent($this->message))->getComponentName())
				?->send()
			;

			(new FileAnalytics($this->chat))->addAttachFilesEvent($this->message);
		});
	}

	public function addAddReaction(string $reaction, int $reactionAuthorId): void
	{
		$this->async(function () use ($reaction, $reactionAuthorId) {
			$reactionCount =
				(new ReactionService($this->message))
					->withContextUser($reactionAuthorId)
					->getReactionCount()
			;

			$this
				->createMessageEvent(self::ADD_REACTION)
				?->setType($reaction)
				?->setReactionP3($reactionCount)
				?->setReactionP4($this->message->getAuthorId())
				?->send()
			;
		});
	}

	public function addShareMessage(Chat $targetChat): void
	{
		$this->async(function () use ($targetChat) {
			$event =
				$this
					->createMessageEvent(self::SHARE_MESSAGE)
					?->setType((new MessageContent($this->message))->getComponentName())
			;

			if ($targetChat instanceof FavoriteChat)
			{
				$event?->setSection('notes');
			}

			$event?->send();
		});
	}

	public function addDeleteMessage(string $messageType): void
	{
		$this->async(function () use ($messageType) {
			$this
				->createMessageEvent(self::DELETE_MESSAGE)
				?->setType($messageType)
				?->send()
			;
		});
	}

	public function addMentionAll(): void
	{
		$this->async(function () {
			if ($this->message->getMessageId() === null)
			{
				return;
			}

			$this
				->createMessageEvent(self::MENTION_ALL)
				?->setType((new MessageContent($this->message))->getComponentName())
				->setP4(null)
				->setP5(null)
				->send()
			;
		});
	}

	protected function createMessageEvent(
		string $eventName,
	): ?MessageEvent
	{
		if (!$this->isChatTypeAllowed($this->chat))
		{
			return null;
		}

		return (new MessageEvent($eventName, $this->chat, $this->getContext()->getUserId()));
	}
}
