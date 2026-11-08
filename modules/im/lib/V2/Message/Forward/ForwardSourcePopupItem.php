<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\CommentChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\PopupDataItem;

/**
 * Resolves the title of the parent chat of every discussion a message was forwarded from, and warms
 * the chat cache the block assembly reads afterwards. The title is personal: it is shown only to a
 * reader who has access to the parent, so it is written into the messages themselves and never into
 * shared state.
 *
 * The section of this item carries null on purpose: the payload goes into messages[].forward, and
 * PopupData puts every item into a top level section of its own, out of reach of that block.
 *
 * Deliberately not a PopupDataAggregatable: otherwise PopupData::fillNextLevel() would start
 * collecting popup data of the lifted chats and blow the answer up.
 */
final class ForwardSourcePopupItem implements PopupDataItem
{
	use ContextCustomer;

	/** @var MessageCollection[] */
	private array $collections;

	public function __construct(MessageCollection $messages)
	{
		$this->collections = [$messages];
	}

	/**
	 * Collections of both items are kept, not the first one: a feed carrying messages of several
	 * collections would otherwise resolve the titles of one of them alone.
	 */
	public function merge(PopupDataItem $item): self
	{
		if ($item instanceof self)
		{
			foreach ($item->collections as $collection)
			{
				$this->collections[] = $collection;
			}
		}

		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'forwardSource';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$this->fillParentTitles();

		return null;
	}

	private function fillParentTitles(): void
	{
		$messagesBySourceChatId = $this->getForwardedMessagesBySourceChatId();
		if (empty($messagesBySourceChatId))
		{
			return;
		}

		$sources = $this->liftExisting(array_keys($messagesBySourceChatId));
		$comments = array_filter($sources, static fn (Chat $chat): bool => $chat instanceof CommentChat);
		if (empty($comments))
		{
			return;
		}

		$ancestors = $this->liftAncestors($comments);

		$userId = $this->getContext()->getUserId();
		// one query for the relations of the whole chain: checkAccess() below would go to the database
		// per chat otherwise
		Chat::fillSelfRelations($sources + $ancestors, $userId);

		foreach ($comments as $chatId => $comment)
		{
			$parent = $ancestors[$comment->getParentChatId() ?? 0] ?? null;
			if (!$parent?->checkAccess($userId)->isSuccess())
			{
				continue;
			}

			foreach ($messagesBySourceChatId[$chatId] as $message)
			{
				$message->setForwardParentTitle($parent->getDisplayedTitle());
			}
		}
	}

	/**
	 * Access is a property of the source, not of the message, so the feed is grouped by the chat it
	 * came from: the title of one discussion is resolved once whatever the number of fragments of it.
	 *
	 * @return array<int, Message[]>
	 */
	private function getForwardedMessagesBySourceChatId(): array
	{
		$messages = [];

		foreach ($this->collections as $collection)
		{
			// the item loads the parameters itself: relying on a neighbour in getPopupData() to have
			// done it would turn into one query per message as soon as the list there is reordered
			$collection->fillParams();

			foreach ($collection as $message)
			{
				$chatId = $this->getSourceChatId($message);
				if ($chatId > 0)
				{
					$messages[$chatId][] = $message;
				}
			}
		}

		return $messages;
	}

	/**
	 * The whole chain of ancestors, level by level until it runs out: Chat::checkAccess() recurses to
	 * the parent without a depth limit, so a discussion in a task chat or in a nested project chat has
	 * a grandparent whose absence from the cache costs a query per message. The set of already lifted
	 * chats is what guards against a cycle.
	 *
	 * @param array<int, Chat> $comments
	 * @return array<int, Chat>
	 */
	private function liftAncestors(array $comments): array
	{
		$ancestors = [];
		$level = $this->getParentChatIds($comments);

		while (!empty($level))
		{
			$chats = $this->liftExisting($level);
			$ancestors += $chats;
			$level = array_diff_key($this->getParentChatIds($chats), $ancestors);
		}

		return $ancestors;
	}

	/**
	 * @param array<int, Chat> $chats
	 * @return array<int, int>
	 */
	private function getParentChatIds(array $chats): array
	{
		$parentChatIds = [];

		foreach ($chats as $chat)
		{
			$parentChatId = $chat->getParentChatId() ?? 0;
			if ($parentChatId > 0)
			{
				$parentChatIds[$parentChatId] = $parentChatId;
			}
		}

		return $parentChatIds;
	}

	/**
	 * @param array<int, int> $chatIds
	 * @return array<int, Chat>
	 */
	private function liftExisting(array $chatIds): array
	{
		$chats = ChatFactory::getInstance()->getChatsByIds(array_values($chatIds));

		return array_filter($chats, static fn (Chat $chat): bool => $chat->isExist());
	}

	/**
	 * Zero both for a message that was not forwarded and for one forwarded from a private chat: a
	 * private chat never hosts a discussion, so its context is answered without lifting anything.
	 */
	private function getSourceChatId(Message $message): int
	{
		$params = $message->getParams();
		if (!$params->isSet(Message\Params::FORWARD_CONTEXT_ID))
		{
			return 0;
		}

		$contextId = $params->get(Message\Params::FORWARD_CONTEXT_ID)->getValue();
		if (!is_string($contextId) || $contextId === '')
		{
			return 0;
		}

		$dialogId = Chat::getDialogIdByContextId($contextId);

		return str_starts_with($dialogId, 'chat') ? (int)mb_substr($dialogId, 4) : 0;
	}
}
