<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\ChatEvent;
use Bitrix\Im\V2\Analytics\Event\CopilotEvent;
use Bitrix\Im\V2\Analytics\Event\CopilotMentionEvent;
use Bitrix\Im\V2\Analytics\Event\Event;
use Bitrix\Im\V2\Analytics\Event\FileMessageEvent;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Vote\Vote;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class CopilotAnalytics extends AbstractAnalytics
{
	protected const GENERATE = 'generate';
	protected const RECEIVED_RESULT = 'received_result';
	protected const MENTION = 'copilot_mention';
	protected const RECEIVED_MENTION_RESULT = 'copilot_reply';
	protected const ADD_USER = 'add_user';
	protected const DELETE_USER = 'delete_user';
	protected const CHANGE_ROLE = 'change_role';
	protected const CHANGE_MODEL = 'change_model';
	protected const ADD_REACTION = 'add_reaction';
	protected const SEND_MESSAGE = 'send_message';
	protected const ATTACH_FILE = 'attach_file';
	protected const CREATE_NEW_CHAT = 'create_new_chat';
	protected const MESSAGE_REGENERATE = 'message_regenerate';
	protected const ANALYTICS_STATUS = [
		'SUCCESS' => 'success',
		'ERROR_PROVIDER' => 'error_provider',
		'ERROR_B24' => 'error_b24',
		'ERROR_LIMIT_DAILY' => 'error_limit_daily',
		'ERROR_LIMIT_MONTHLY' => 'error_limit_monthly',
		'ERROR_AGREEMENT' => 'error_agreement',
		'ERROR_TURNEDOFF' => 'error_turnedoff',
		'ERROR_LIMIT_BAAS' => 'error_limit_baas',
	];

	public function addGenerate(Result $result, bool $byMention, Message $targetMessage): void
	{
		$this->async(function () use ($targetMessage, $result, $byMention) {
			$this->sendCopilotEvent(self::GENERATE, $result, $targetMessage);
			if ($byMention)
			{
				$this->sendMentionEvent(self::MENTION, $result, $targetMessage);
			}
			$hasAnswer = ($result->getData()['MESSAGE'] ?? '') !== '';
			if ($hasAnswer)
			{
				$this->sendReceiveEvent($result, $targetMessage, $byMention);
			}
		});
	}

	public function addReceive(Result $result, Message $targetMessage, bool $byMention): void
	{
		$this->async(function () use ($result, $targetMessage, $byMention) {
			$this->sendReceiveEvent($result, $targetMessage, $byMention);
		});
	}

	/**
	 * generate / received_result keep the legacy format in both flag states:
	 * under the v2 flag the message flow is routed to the aiassistant module,
	 * which emits these labels itself. The only backend path left here is the
	 * pre-routing restriction-error generate.
	 */
	protected function sendCopilotEvent(string $name, Result $result, Message $targetMessage): void
	{
		$status = $this->getCopilotStatus($result);
		$promptCode = $targetMessage->getParams()->get(Message\Params::COPILOT_PROMPT_CODE)->getValue();
		$this
			->createCopilotEvent($name, $promptCode)
			?->ensureLegacyFormat()
			?->setType($this->getTypeForEvent($targetMessage))
			?->setStatus($status)
			?->send()
		;
	}

	protected function sendMentionEvent(string $name, Result $result, Message $targetMessage): void
	{
		$status = $this->getCopilotStatus($result);
		$this
			->createCopilotMentionEvent($name, $targetMessage)
			?->setStatus($status)
			?->send()
		;
	}

	protected function sendReceiveEvent(Result $result, Message $targetMessage, bool $byMention): void
	{
		$this->sendCopilotEvent(self::RECEIVED_RESULT, $result, $targetMessage);
		if ($byMention)
		{
			$this->sendMentionEvent(self::RECEIVED_MENTION_RESULT, $result, $targetMessage);
		}
	}

	/**
	 * Emits add_reaction ai-label for a copilot vote (like/dislike).
	 *
	 * type = vote value, p3 = messageId_<id>, p4 = modes snapshot from message, p5 = chatId.
	 */
	public function addAddReaction(Message $message, Vote $vote): void
	{
		$this->async(fn () => $this->buildAddReactionEvent($message, $vote)?->send());
	}

	protected function buildAddReactionEvent(Message $message, Vote $vote): ?CopilotEvent
	{
		if (!$this->isVotableAuthor((int)$message->getAuthorId()))
		{
			return null;
		}

		$event = $this->createV2CopilotEvent(self::ADD_REACTION);
		if ($event === null)
		{
			return null;
		}

		$event
			->applyV2Format($this->resolveModesSourceMessage($message))
			->setType($vote->value)
			->setP3('messageId_' . $message->getMessageId())
		;

		return $event;
	}

	/**
	 * A vote is only stored for replies of the copilot or the AI assistant bot
	 * (aiassistant MessageVoteService::resolveBotCode gates the OnMessageVoteSend subscriber),
	 * while the REST action accepts any readable message of the chat. Without the same guard
	 * the label would count reactions that were never saved — a user's own message or a
	 * third-party bot's one.
	 */
	protected function isVotableAuthor(int $authorId): bool
	{
		if ($authorId <= 0)
		{
			return false;
		}

		if (Loader::includeModule('imbot') && $authorId === (int)CopilotChatBot::getBotId())
		{
			return true;
		}

		return $authorId === ServiceLocator::getInstance()->get(AiAssistantService::class)->getBotId();
	}

	/**
	 * Resolves the message whose copilot mode params should feed the p4 snapshot.
	 *
	 * A reaction is usually put on the bot's reply, which does not carry the mode
	 * params of the originating request. When the rated message links its trigger
	 * (COPILOT_TRIGGER_MESSAGE_ID), the snapshot is built from that message — the
	 * same source-modes semantics as message_regenerate; otherwise from the message itself.
	 *
	 * Only ID / CHAT_ID / AUTHOR_ID are selected: the snapshot needs nothing else
	 * from the row, and the mode params are lazy-loaded by message id anyway. The
	 * returned Message may therefore be partially hydrated — use it only as the
	 * p4 snapshot source, never as a full message.
	 * The trigger is only trusted within the rated message's chat: the link comes
	 * from a message param, so a stale or foreign reference must not leak another
	 * chat's modes into p4 — outside the chat the snapshot falls back to the rated
	 * message itself.
	 */
	protected function resolveModesSourceMessage(Message $message): Message
	{
		$triggerMessageId = (int)$message->getParams()
			->get(Message\Params::COPILOT_TRIGGER_MESSAGE_ID)
			->getValue()
		;
		if ($triggerMessageId <= 0)
		{
			return $message;
		}

		$triggerMessageRow = MessageTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID'])
			->where('ID', $triggerMessageId)
			->where('CHAT_ID', $message->getChatId())
			->fetch()
		;

		return $triggerMessageRow === false ? $message : new Message($triggerMessageRow);
	}

	/**
	 * Emits send_message ai-label for a copilot chat message.
	 *
	 * type = componentName (mirrors im-label type), p4 = modes snapshot from message, p5 = chatId.
	 */
	public function addSendMessage(Message $message, ?string $componentName = null): void
	{
		$this->async(fn () => $this->buildSendMessageEvent($message, $componentName)?->send());
	}

	protected function buildSendMessageEvent(Message $message, ?string $componentName = null): ?CopilotEvent
	{
		$event = $this->createV2CopilotEvent(self::SEND_MESSAGE);
		if ($event === null)
		{
			return null;
		}

		$event
			->applyV2Format($message)
			->setType($componentName ?? (new MessageContent($message))->getComponentName())
		;

		return $event;
	}

	/**
	 * Emits attach_file ai-label for a copilot message with files.
	 *
	 * type = file category (from FileMessageEvent::resolveFileType), p4 = modes snapshot from message, p5 = chatId.
	 * Only called when the message has files.
	 */
	public function addAttachFile(Message $message, ?string $fileType = null): void
	{
		$this->async(fn () => $this->buildAttachFileEvent($message, $fileType)?->send());
	}

	protected function buildAttachFileEvent(Message $message, ?string $fileType = null): ?CopilotEvent
	{
		$files = $message->getFiles();
		if ($files->count() < 1)
		{
			return null;
		}

		$event = $this->createV2CopilotEvent(self::ATTACH_FILE);
		if ($event === null)
		{
			return null;
		}

		$event
			->applyV2Format($message)
			->setType($fileType ?? FileMessageEvent::resolveFileType($files))
		;

		return $event;
	}

	/**
	 * Emits create_new_chat ai-label for a copilot chat creation (chat-level).
	 *
	 * p4 = reduced modes snapshot (role + chatType), p5 = chatId.
	 */
	public function addCreateNewChat(): void
	{
		$this->async(fn () => $this->buildCreateNewChatEvent()?->send());
	}

	protected function buildCreateNewChatEvent(): ?CopilotEvent
	{
		$event = $this->createV2CopilotEvent(self::CREATE_NEW_CHAT);
		if ($event === null)
		{
			return null;
		}

		$event->applyV2FormatReduced();

		return $event;
	}

	/**
	 * Emits message_regenerate ai-label.
	 *
	 * p3 = messageId_<id> (the regenerated message), p4 = modes snapshot from triggerMessage (source modes), p5 = chatId.
	 */
	public function addMessageRegenerate(Message $message, Message $triggerMessage): void
	{
		$this->async(fn () => $this->buildMessageRegenerateEvent($message, $triggerMessage)?->send());
	}

	protected function buildMessageRegenerateEvent(Message $message, Message $triggerMessage): ?CopilotEvent
	{
		$event = $this->createV2CopilotEvent(self::MESSAGE_REGENERATE);
		if ($event === null)
		{
			return null;
		}

		$event
			->applyV2Format($triggerMessage)
			->setP3('messageId_' . $message->getMessageId())
		;

		return $event;
	}

	public function addChangeRole(string $oldRole): void
	{
		$this->async(function () use ($oldRole) {

			(new ChatEvent(self::CHANGE_ROLE, $this->chat, $this->getContext()->getUserId()))
				->setP1(null)
				->setP3('oldRole_' . Event::convertUnderscore($oldRole))
				->send()
			;
		});
	}

	public function addChangeEngine(string $oldEngineName): void
	{
		$this->async(function () use ($oldEngineName) {
			(new ChatEvent(self::CHANGE_MODEL, $this->chat, $this->getContext()->getUserId()))
				->setP1(null)
				->setP3('oldProvider_' . Event::convertUnderscore($oldEngineName))
				->send()
			;
		});
	}

	public function addAddUser(): void
	{
		if (Features::isBitrixGptV2Available())
		{
			$this
				->createV2CopilotEvent(self::ADD_USER)
				?->applyV2FormatReduced()
				?->send()
			;

			return;
		}

		$this
			->createCopilotEvent(self::ADD_USER)
			?->send()
		;
	}

	public function addDeleteUser(): void
	{
		if (Features::isBitrixGptV2Available())
		{
			$this
				->createV2CopilotEvent(self::DELETE_USER)
				?->applyV2FormatReduced()
				?->send()
			;

			return;
		}

		$this
			->createCopilotEvent(self::DELETE_USER)
			?->send()
		;
	}

	protected function createCopilotEvent(
		string $eventName,
		?string $promptCode = null,
	): ?CopilotEvent
	{
		return (new CopilotEvent($eventName, $this->chat, $this->getContext()->getUserId()))
			->setCopilotP1($promptCode)
		;
	}

	/**
	 * Creates a CopilotEvent for the v2-format ai-labels: overrides the legacy
	 * p1='none' default with null in one place, so every v2 event omits p1 without
	 * each builder having to remember the reset.
	 */
	protected function createV2CopilotEvent(string $eventName): ?CopilotEvent
	{
		$event = $this->createCopilotEvent($eventName);
		$event?->setP1(null);

		return $event;
	}

	protected function createCopilotMentionEvent(string $eventName, Message $message): ?CopilotMentionEvent
	{
		return (new CopilotMentionEvent($eventName, $message, $this->getContext()->getUserId()));
	}

	protected function getCopilotStatus(Result $result): string
	{
		if ($result->isSuccess())
		{
			return self::ANALYTICS_STATUS['SUCCESS'];
		}

		$error = $result->getErrors()[0];

		if (!isset($error))
		{
			return self::ANALYTICS_STATUS['ERROR_B24'];
		}

		return match ($error->getCode()) {
			CopilotChatBot::AI_ENGINE_ERROR_PROVIDER => self::ANALYTICS_STATUS['ERROR_PROVIDER'],
			CopilotChatBot::LIMIT_IS_EXCEEDED_DAILY => self::ANALYTICS_STATUS['ERROR_LIMIT_DAILY'],
			CopilotChatBot::LIMIT_IS_EXCEEDED_MONTHLY => self::ANALYTICS_STATUS['ERROR_LIMIT_MONTHLY'],
			CopilotChatBot::ERROR_AGREEMENT => self::ANALYTICS_STATUS['ERROR_AGREEMENT'],
			CopilotChatBot::LIMIT_IS_EXCEEDED_BAAS => self::ANALYTICS_STATUS['ERROR_LIMIT_BAAS'],
			default => self::ANALYTICS_STATUS['ERROR_B24'],
		};
	}

	protected function getTypeForEvent(Message $message): string
	{
		return match (true)
		{
			$message->getParams()->get(Message\Params::COPILOT_REASONING)->getValue() => 'think',
			default => 'default',
		};
	}
}
