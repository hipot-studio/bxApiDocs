<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\V2\Cache\CacheLevel;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Search\Access\OpenCodeReachResolver;
use Bitrix\Im\V2\Message;
use Bitrix\Main\DI\ServiceLocator;

/**
 * The portion of a walk as a unit of work, and the single owner of what a walk releases when the
 * portion ends: the instances of the chats it read and both maps of the permission builder.
 *
 * A walk runs in a process of its own, where a chat changed elsewhere is reported by no point of the
 * facade, so an answer about a chat may not outlive the portion it was read for. The openness a
 * record is built on comes from the instance and the membership from the builder, which is why both
 * sides are released together: split between two owners they drift apart, and a walk that releases
 * one of them still bakes rights the chat no longer describes.
 *
 * Only the static level of the instance is dropped - the persistent cache belongs to the whole
 * portal. The parent of a comment chat is released among the chats of the portion: the rights of
 * such a record are those of the parent channel.
 *
 * The answers of the hygiene rule are scoped here as well, and for the same reason: they may be read
 * once for a portion, never once for a process.
 */
final class WalkPortion
{
	/** @var array<int, true> */
	private array $chatIds = [];

	private bool $isOpen = false;

	public function __construct()
	{
		$this->hygieneRegistry()->openPortion();
		$this->isOpen = true;
	}

	/**
	 * Hands the messages of the portion over to the hygiene rule, so that it reads their marks in one
	 * query instead of one per message.
	 *
	 * @param iterable<Message> $messages
	 */
	public function addMessages(iterable $messages): void
	{
		$messageIds = [];
		foreach ($messages as $message)
		{
			$messageId = $message->getMessageId() ?? 0;
			if ($messageId > 0)
			{
				$messageIds[] = $messageId;
			}
		}

		if ($messageIds !== [])
		{
			$this->hygieneRegistry()->rememberMessages($messageIds);
		}
	}

	/**
	 * Reads the chats of the portion by one query and their parents by a second one, so that the walk
	 * below finds them in the cache the factory has just warmed. A portion of five hundred messages of
	 * as many cold chats costs five hundred selects otherwise - one per message, and the collection of
	 * the messages carries none of it.
	 *
	 * What is read here is released with the portion, exactly as a chat added row by row.
	 *
	 * @param iterable<Message> $messages
	 */
	public function addChatsOf(iterable $messages): void
	{
		$chatIds = [];
		foreach ($messages as $message)
		{
			$chatId = $message->getChatId() ?? 0;
			if ($chatId > 0)
			{
				$chatIds[$chatId] = true;
			}
		}

		if ($chatIds === [])
		{
			return;
		}

		$chatFactory = Chat\ChatFactory::getInstance();

		$chats = $chatFactory->getChatsByIds(array_keys($chatIds));
		$parentChatIds = [];
		$postIds = [];
		foreach ($chats as $chat)
		{
			$this->addChat($chat);

			$parentChatId = $chat->getParentChatId() ?? 0;
			if ($parentChatId > 0)
			{
				$parentChatIds[$parentChatId] = true;
			}

			if ($chat instanceof Chat\CommentChat && $chat->getParentMessageId() > 0)
			{
				$postIds[$chat->getParentMessageId()] = true;
			}
		}

		// the rights of a comment record are those of the parent channel, so the walk reaches for it as
		// surely as for the chat of the message itself
		if ($parentChatIds !== [])
		{
			$chatFactory->getChatsByIds(array_keys($parentChatIds));
		}

		// a thread stands only while its post does, and the rule asks that of the post of the comment it
		// holds: read one by one, a portion of comments of as many posts costs a query per comment
		if ($postIds !== [])
		{
			$this->hygieneRegistry()->rememberPosts(array_keys($postIds));
		}

		$this->rememberMembershipOf($chats);
	}

	public function addChat(Chat $chat): void
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId > 0)
		{
			$this->chatIds[$chatId] = true;
		}

		$parentChatId = $chat->getParentChatId() ?? 0;
		if ($parentChatId > 0)
		{
			$this->chatIds[$parentChatId] = true;
		}
	}

	public function release(): void
	{
		$chatFactory = Chat\ChatFactory::getInstance();

		foreach (array_keys($this->chatIds) as $chatId)
		{
			$chatFactory->cleanCache($chatId, CacheLevel::Static);
		}

		$this->chatIds = [];

		ServiceLocator::getInstance()->get(PermissionBuilder::class)->forgetAllChats();

		// a portion released twice closes the scope once: the walks release theirs in a finally block
		if ($this->isOpen)
		{
			$this->isOpen = false;
			$this->hygieneRegistry()->closePortion();
		}
	}

	/**
	 * Membership of the chats of the portion whose entries are baked by the names of their members, in
	 * one query. The objects of the chats carry none of it, so a portion of five hundred messages of as
	 * many closed chats costs a select apiece otherwise.
	 *
	 * A chat the shared code reaches is left out: the few members that code leaves out are picked by
	 * the resolver of the carriers, by a walk of its own, and reading the whole membership of a general
	 * channel here would cost more than it saves.
	 *
	 * A comment is baked by the codes of the channel its post stands in, so that channel is the chat
	 * asked about - it stands warmed among the parents read just above.
	 *
	 * Which chats those are is answered by the reach of the shared code, and that answer is the chain
	 * above the chat: the resolver reads it here by levels for the whole portion at once. Asked chat by
	 * chat, the question alone costs a select per ancestor, and the objects warmed by the factory carry
	 * nothing of it - the resolver reads the table it writes the entries against.
	 *
	 * @param iterable<Chat> $chats
	 */
	private function rememberMembershipOf(iterable $chats): void
	{
		$carriers = [];
		foreach ($chats as $chat)
		{
			$carrier = $chat instanceof Chat\CommentChat ? $chat->getParentChat() : $chat;
			$chatId = $carrier->getId() ?? 0;

			if ($chatId > 0)
			{
				$carriers[$chatId] = $carrier;
			}
		}

		if ($carriers === [])
		{
			return;
		}

		$reachResolver = ServiceLocator::getInstance()->get(OpenCodeReachResolver::class);
		$reachResolver->rememberChainOf($carriers);

		$chatIds = [];
		foreach ($carriers as $chatId => $carrier)
		{
			if (!$reachResolver->isReached($carrier))
			{
				$chatIds[$chatId] = true;
			}
		}

		if ($chatIds !== [])
		{
			ServiceLocator::getInstance()->get(PermissionBuilder::class)
				->rememberMembershipOf(array_keys($chatIds))
			;
		}
	}

	private function hygieneRegistry(): HygieneRegistry
	{
		return ServiceLocator::getInstance()->get(HygieneRegistry::class);
	}
}
