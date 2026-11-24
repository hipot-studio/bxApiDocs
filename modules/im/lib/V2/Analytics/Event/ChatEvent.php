<?php

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Ai\AiAssistantPrivateChat;
use Bitrix\Im\V2\Chat\ChannelChat;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\CommentChat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\FavoriteChat;
use Bitrix\Im\V2\Chat\GeneralChannel;
use Bitrix\Im\V2\Chat\GeneralChat;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Chat\OpenChannelChat;
use Bitrix\Im\V2\Chat\OpenChat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Chat\VideoConfChat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserCollaber;
use Bitrix\Im\V2\Entity\User\UserExternal;
use Bitrix\Im\V2\Entity\User\UserExtranet;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Integration\AI\EngineManager;
use Bitrix\Im\V2\Integration\AI\RoleManager;

class ChatEvent extends Event
{
	public const PROJECT_P4_PREFIX = 'projectId_';
	private const CHAT_ID_PREFIX = 'chatId_';
	private const PARENT_CHAT_ID_PREFIX = 'parentChatId_';

	protected const CHAT_TYPE_CONDITIONS = [
		'collab' => ['instanceof' => CollabChat::class],

		'call' => ['entity' => 'CALL'],
		'crm' => ['entity' => 'CRM'],
		'mail' => ['entity' => 'MAIL'],
		'sonetGroup' => ['entity' => 'SONET_GROUP'],
		'tasks' => ['entity' => 'TASKS'],

		'calendar' => ['entity' => 'CALENDAR'],
		'support24Notifier' => ['entity' => 'SUPPORT24_NOTIFIER'],
		'support24Question' => ['entity' => 'SUPPORT24_QUESTION'],
		'thread' => ['entity' => 'THREAD'],
		'announcement' => ['entity' => 'ANNOUNCEMENT'],

		'generalChannel' => ['instanceof' => GeneralChat::class],
		'openChannel' => ['instanceof' => OpenChannelChat::class],
		'channel' => ['instanceof' => ChannelChat::class],

		'comment' => ['instanceof' => CommentChat::class],
		'open' => ['instanceof' => OpenChat::class],
		'general' => ['instanceof' => GeneralChat::class],
		'videoconf' => ['instanceof' => VideoConfChat::class],
		'copilot' => ['instanceof' => CopilotChat::class],
		'chat' => ['instanceof' => GroupChat::class],

		'aiAssistant' => ['instanceof' => AiAssistantPrivateChat::class],
		'user' => ['instanceof' => PrivateChat::class],
	];
	protected const CHAT_P1_CONDITIONS = [
		'chatType_collab' => ['instanceof' => CollabChat::class],

		'chatType_call' => ['entity' => 'CALL'],
		'chatType_crm' => ['entity' => 'CRM'],
		'chatType_mail' => ['entity' => 'MAIL'],
		'chatType_sonetGroup' => ['entity' => 'SONET_GROUP'],
		'chatType_tasks' => ['entity' => 'TASKS'],
		'chatType_calendar' => ['entity' => 'CALENDAR'],

		'chatType_generalChannel' => ['instanceof' => GeneralChannel::class],
		'chatType_openChannel' => ['instanceof' => OpenChannelChat::class],
		'chatType_channel' => ['instanceof' => ChannelChat::class],

		'chatType_comment' => ['instanceof' => CommentChat::class],
		'chatType_open' => ['instanceof' => OpenChat::class],
		'chatType_general' => ['instanceof' => GeneralChat::class],
		'chatType_videoconf' => ['instanceof' => VideoConfChat::class],
		'chatType_copilot' => ['instanceof' => CopilotChat::class],
		'chatType_chat' => ['instanceof' => GroupChat::class],

		'chatType_notes' => ['instanceof' => FavoriteChat::class],
		'chatType_aiAssistant' => ['instanceof' => AiAssistantPrivateChat::class],
		'chatType_user' => ['instanceof' => PrivateChat::class],
	];

	private ?CollabChat $parentCollabMemo = null;
	private ?Chat $parentCollabOwner = null;

	protected function setDefaultParams(): self
	{
		$this
			->setChatP1()
			->setChatP2()
			->setChatP4()
			->setChatP5()
		;

		return $this;
	}

	public function setChatType(): self
	{
		$this->type = self::resolveChatType($this->chat);

		return $this;
	}

	private static function resolveChatType(Chat $chat): string
	{
		if ($chat instanceof ExternalChat)
		{
			$extendedType = $chat->getExtendedType();

			return $extendedType === 'collab' ? 'project' : $extendedType;
		}

		$entityType = $chat->getEntityType();

		foreach (self::CHAT_TYPE_CONDITIONS as $typeName => $conditions)
		{
			if (array_key_exists('entity', $conditions) && $conditions['entity'] === $entityType)
			{
				return $typeName;
			}

			if (!array_key_exists('instanceof', $conditions))
			{
				continue;
			}

			foreach ((array)$conditions['instanceof'] as $condition)
			{
				if ($chat instanceof $condition)
				{
					return $typeName;
				}
			}
		}

		return '';
	}

	protected function setChatP1(): self
	{
		$this->p1 = self::resolveChatP1($this->chat);

		return $this;
	}

	/**
	 * The kind of the chat a discussion belongs to, spelled with the very dictionary every other label
	 * spells a chat kind with: an event counted by the kind of the parent has to land in the same rows
	 * as the rest of the module, and a second dictionary would make it unjoinable with them.
	 *
	 * Only a discussion has a parent to name, so the slot keeps whatever it holds for any other chat.
	 */
	public function setParentChatP1(): self
	{
		if ($this->chat instanceof CommentChat)
		{
			$this->p1 = self::resolveChatP1($this->chat->getParentChat());
		}

		return $this;
	}

	private static function resolveChatP1(Chat $chat): string
	{
		if ($chat instanceof ExternalChat)
		{
			$extendedType = $chat->getExtendedType();

			return $extendedType === 'collab' ? 'chatType_project' : 'chatType_' . $extendedType;
		}

		$entityType = $chat->getEntityType();

		foreach (self::CHAT_P1_CONDITIONS as $typeName => $conditions)
		{
			if (array_key_exists('entity', $conditions) && $conditions['entity'] === $entityType)
			{
				return $typeName;
			}

			if (array_key_exists('instanceof', $conditions) && $chat instanceof $conditions['instanceof'])
			{
				return $typeName;
			}
		}

		return 'custom';
	}

	protected function setChatP2(): self
	{
		if ($this->chat instanceof CopilotChat && !Features::isBitrixGptV2Available())
		{
			$engineCode = $this->chat->getEngineCode();
			$engineName = (new EngineManager())->getEngineNameByCode($engineCode) ?? '';

			$this->p2 = 'provider_' . Event::convertUnderscore($engineName);

			return $this;
		}

		// the user the event is attributed to, and not the context of the chat: a discussion is created
		// with the context user cleared, so a chat would answer "intranet" whoever opened the thread
		$this->p2 = self::resolveUserType(User::getInstance($this->userId));

		return $this;
	}

	/**
	 * A guest and a collaber are both external users, so either must be recognised before the kind
	 * they descend from. An extranet user reaches the module as one of two unrelated classes - a member
	 * of an extranet group and an externally authorized one - and both are counted as extranet.
	 */
	private static function resolveUserType(User $user): string
	{
		return match (true)
		{
			$user instanceof UserCollaber => 'user_collaber',
			$user instanceof UserGuest => 'user_guest',
			$user instanceof UserExtranet, $user instanceof UserExternal => 'user_extranet',
			default => 'user_intranet',
		};
	}

	protected function setChatP4(): self
	{
		$projectCollab = $this->getProjectCollab();
		if ($projectCollab !== null)
		{
			$entityId = $projectCollab->getEntityId();
			if ((int)$entityId > 0)
			{
				$this->p4 = self::PROJECT_P4_PREFIX . $entityId;
			}

			return $this;
		}

		if ($this->chat instanceof CopilotChat)
		{
			if (!Features::isBitrixGptV2Available())
			{
				$role = (new RoleManager())->getMainRole($this->chat->getChatId()) ?? '';
				$this->p4 = 'role_' . self::convertUnderscore($role);
			}

			return $this;
		}

		// a comment names its parent in p5, so the slot is left to the project alone
		if ($this->chat instanceof CommentChat)
		{
			return $this;
		}

		// a chat with no parent names none: parentChatId_0 is no parent but a bucket of every chat that
		// stands on its own, and it crowded out the rows the slot is really read for
		if ($this->chat->hasParent())
		{
			$this->p4 = self::PARENT_CHAT_ID_PREFIX . $this->chat->getParentChatId();
		}

		return $this;
	}

	/**
	 * The chat id an event is cut by: a comment names the chat the discussion belongs to, every other
	 * chat names itself. A discussion is one chat per root message, so its own id would split the
	 * metrics of a chat into as many rows as it has discussions.
	 */
	protected function setChatP5(): self
	{
		if ($this->chat instanceof CommentChat)
		{
			$this->p5 = self::PARENT_CHAT_ID_PREFIX . ($this->chat->getParentChatId() ?? 0);

			return $this;
		}

		if ($this->chat->getChatId() !== null)
		{
			$this->p5 = self::CHAT_ID_PREFIX . $this->chat->getChatId();
		}

		return $this;
	}

	/**
	 * Whether the chat-id slot of a label names the given chat: a discussion names its parent there and
	 * every other chat names itself, so both forms mean the event belongs to that chat.
	 */
	public static function isChatIdSlotOf(string $slot, int $chatId): bool
	{
		return $slot === self::CHAT_ID_PREFIX . $chatId || $slot === self::PARENT_CHAT_ID_PREFIX . $chatId;
	}

	protected function getTool(): string
	{
		return 'im';
	}

	/**
	 * A comment chat is counted in the category of the chat it belongs to: the comments of a channel
	 * stay `channel`, and a discussion of any other chat lands in the category of its parent, project
	 * subtree included. Discussions are told apart from the comments of a channel by the chat-type
	 * parameter (`p1=chatType_comment`) inside that category, not by a category of their own.
	 */
	protected function getCategory(string $eventName): string
	{
		$additionalCategories = $eventName === ChatAnalytics::SUBMIT_CREATE_NEW;
		$chat = $this->getCategoryChat();

		return match (true)
		{
			$chat instanceof ChannelChat => 'channel',
			$this->isProjectChild($chat) => 'project',
			$chat instanceof CopilotChat => 'copilot',
			$chat instanceof CollabChat => 'project',
			$chat instanceof VideoConfChat && $additionalCategories => 'videoconf',
			default => 'chat',
		};
	}

	/**
	 * The chat whose kind decides the category: the parent for a comment chat, the chat itself for
	 * every other one.
	 */
	private function getCategoryChat(): Chat
	{
		return $this->chat instanceof CommentChat ? $this->chat->getParentChat() : $this->chat;
	}

	private function isProjectChild(Chat $chat): bool
	{
		return $this->getParentCollab($chat) !== null;
	}

	/**
	 * The project an event is counted in, read off the same chat the category is read off: the collab
	 * of a discussion sits one level further up than the collab of any other chat, and asking the
	 * parent covers both distances at once. A comment of a channel is asked too - its category stays
	 * with the channel while the project of the subtree reaches p4.
	 *
	 * A channel itself is the one kind left out: it spends p4 on the id of its parent, as it always
	 * has, so the rows the dashboards of channels read stay where they are.
	 */
	private function getProjectCollab(): ?CollabChat
	{
		if ($this->chat instanceof ChannelChat)
		{
			return null;
		}

		$chat = $this->getCategoryChat();

		return $chat instanceof CollabChat ? $chat : $this->getParentCollab($chat);
	}

	public function setChat(Chat $chat): self
	{
		$this->parentCollabMemo = null;
		$this->parentCollabOwner = null;

		return parent::setChat($chat);
	}

	/**
	 * Memoized per chat: p4 asks it of the chat of the event, while the category asks it of the chat
	 * the category is read off - the parent for a comment chat.
	 */
	private function getParentCollab(Chat $chat): ?CollabChat
	{
		if ($this->parentCollabOwner === $chat)
		{
			return $this->parentCollabMemo;
		}

		$parentChat = $chat->hasParent() ? $chat->getParentChat() : null;

		$this->parentCollabOwner = $chat;
		$this->parentCollabMemo = $parentChat instanceof CollabChat ? $parentChat : null;

		return $this->parentCollabMemo;
	}
}
