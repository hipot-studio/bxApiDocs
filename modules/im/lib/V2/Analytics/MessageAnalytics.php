<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\FileMessageEvent;
use Bitrix\Im\V2\Analytics\Event\MessageEvent;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\FavoriteChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Reaction\ReactionService;
use Bitrix\Im\V2\Message\Sticker\StickerItem;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;

class MessageAnalytics extends ChatAnalytics
{
	protected const SEND_MESSAGE = 'send_message';
	protected const SEND_STICKER = 'send_sticker';
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

			// Every emitter below (send_message / send_sticker / attach_file and the copilot
			// v2 pair) is gated by the same base chat-type restriction, so bail out before
			// classifying content and loading files for chats that emit nothing.
			if (!$this->isChatTypeAllowed($this->chat))
			{
				return;
			}

			$componentName = (new MessageContent($this->message))->getComponentName();
			$files = $this->message->getFiles();
			$fileType = $files->count() > 0 ? FileMessageEvent::resolveFileType($files) : null;

			$this
				->createMessageEvent(self::SEND_MESSAGE)
				?->setType($componentName)
				?->send()
			;

			$this->addSendSticker($this->message->getSticker());
			(new FileAnalytics($this->chat))->addAttachFilesEvent($this->message, $fileType);

			// BitrixGPT v2 ai-labels: send_message and attach_file for copilot chats under flag.
			// Bot replies are tracked separately (receive labels on the aiassistant side),
			// so any bot author must stay out of the send funnel — see isMessageAuthorBot().
			// The labels take the message context (pinned to the author in prepareMessage),
			// not the lazy global one: for messages sent on behalf of an explicit user the
			// background job must attribute the event to the actual author.
			if (
				$this->chat instanceof CopilotChat
				&& Features::isBitrixGptV2Available()
				&& !$this->isMessageAuthorBot()
			)
			{
				$copilotAnalytics = (new CopilotAnalytics($this->chat))->withContext($this->message->getContext());
				$copilotAnalytics->addSendMessage($this->message, $componentName);
				if ($fileType !== null)
				{
					$copilotAnalytics->addAttachFile($this->message, $fileType);
				}
			}
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
				?->setSection((new MessageContent($this->message))->getComponentName())
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

	protected function addSendSticker(?StickerItem $sticker): void
	{
		if ($sticker === null)
		{
			return;
		}

		$this
			->createChatEvent(self::SEND_STICKER)
			?->setType($sticker->packType->value)
			?->setP2(null)
			?->setP4(null)
			?->setP5(null)
			?->send()
		;
	}

	protected function getCopilotBotId(): int
	{
		return Loader::includeModule('imbot') ? (int)CopilotChatBot::getBotId() : 0;
	}

	/**
	 * The copilot bot id comparison is the cheap fast path: the dominant excluded case —
	 * the copilot reply — is filtered without loading the user entity. The isBot() fallback
	 * closes the rest: the v2 pipeline may post the answer from the aiassistant bot, and
	 * without the imbot module the copilot bot id resolves to 0, so any other bot author
	 * must be excluded by the user entity check. The message context user is the author
	 * (pinned in Chat::prepareMessage), so the v2 entity is used instead of the legacy one.
	 */
	protected function isMessageAuthorBot(): bool
	{
		$authorId = $this->message->getAuthorId();
		$copilotBotId = $this->getCopilotBotId();
		if ($copilotBotId !== 0 && $authorId === $copilotBotId)
		{
			return true;
		}

		return $this->message->getContext()->getUser()->isBot();
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
