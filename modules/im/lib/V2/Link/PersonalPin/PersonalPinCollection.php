<?php

namespace Bitrix\Im\V2\Link\PersonalPin;

use Bitrix\Im\Model\EO_LinkPinPersonal_Collection;
use Bitrix\Im\Model\LinkPinPersonalTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Common\SidebarFilterProcessorTrait;
use Bitrix\Im\V2\Link\BaseLinkCollection;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\ORM\Query\Query;

/**
 * @implements \IteratorAggregate<int,PersonalPinItem>
 * @method PersonalPinItem offsetGet($key)
 */
class PersonalPinCollection extends BaseLinkCollection
{
	use SidebarFilterProcessorTrait;

	/**
	 * A personal pin is readable by its owner alone, so the owner is an argument of the read and not
	 * a key of the filter: a caller cannot forget it and get everyone's pins.
	 *
	 * The order key is the pinned message, not the pin row: the canonical sequence goes from the
	 * newest message to the oldest no matter when it was pinned. The `ID` key of the order argument
	 * keeps naming the direction only, so the paging cursor and the sort share the message key.
	 *
	 * Selecting the message joins its reference, and the reference is an inner join: a mark whose
	 * message is already gone drops out of the answer. A select without the message would bring
	 * such marks back.
	 */
	public static function findForOwner(
		int $authorId,
		array $filter = [],
		array $order = ['ID' => 'DESC'],
		?int $limit = null
	): self
	{
		$pinOrder = ['MESSAGE_ID' => $order['ID'] ?? 'DESC'];

		$query = LinkPinPersonalTable::query()
			->setSelect(['MESSAGE', 'ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->where('AUTHOR_ID', $authorId)
			->setOrder($pinOrder)
		;

		if (isset($limit))
		{
			$query->setLimit($limit);
		}

		static::processFilters($query, $filter, $pinOrder);

		return static::initByEntityCollection($query->fetchCollection());
	}

	/**
	 * Read by identifiers that came from outside - the delta journal keeps ids alone. The owner
	 * stays in the query, so identifiers of someone else's marks bring back nothing.
	 *
	 * A journal row proves whom it was written for, not that its owner may still read the message:
	 * the chat may have been left behind, or the window of reachable history may have moved above
	 * the pinned message. Hence the second narrowing, by the same check that guards pinning itself.
	 * It narrows the answer and not the table - the window may open again, and then the mark has to
	 * come back with it.
	 */
	public static function findByIdsForOwner(array $ids, int $authorId): self
	{
		if (empty($ids) || $authorId <= 0)
		{
			return new static();
		}

		$entityCollection = LinkPinPersonalTable::query()
			->setSelect(['MESSAGE', 'ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->whereIn('ID', array_values($ids))
			->where('AUTHOR_ID', $authorId)
			->fetchCollection()
		;

		$collection = static::initByEntityCollection($entityCollection);
		$collection->warmUpAccessData($authorId);

		return $collection->filter(
			static fn (PersonalPinItem $pin) => $pin->getEntity()->checkAccess($authorId)->isSuccess()
		);
	}

	/**
	 * The check below reads the chat of a mark and the relation of the owner in it, and both reads go
	 * per chat. A page of the delta carries marks of many chats at once, so the chats and the relations
	 * of the owner are taken by one query each before the filter runs: the checks then read the warm
	 * caches of the chat instead of going to the base once per mark.
	 */
	private function warmUpAccessData(int $authorId): void
	{
		$chatIds = [];

		/** @var PersonalPinItem $pin */
		foreach ($this as $pin)
		{
			$chatId = (int)$pin->getChatId();

			if ($chatId > 0)
			{
				$chatIds[$chatId] = $chatId;
			}
		}

		if (empty($chatIds))
		{
			return;
		}

		$chats = array_filter(
			ChatFactory::getInstance()->getChatsByIds(array_values($chatIds)),
			static fn (Chat $chat) => !($chat instanceof Chat\NullChat)
		);

		if (empty($chats))
		{
			return;
		}

		Chat::fillSelfRelations($chats, $authorId);
		$this->warmUpParentChats($chats, $authorId);
	}

	/**
	 * A chat of a thread answers for its parent too: the check of such a chat asks the parent for the
	 * membership of the same owner. The parent is read one at a time, and a page of the delta may carry
	 * threads of many different parents, so they are taken by the same pair of queries as the chats of
	 * the marks themselves.
	 *
	 * @param array<int,Chat> $chats
	 */
	private function warmUpParentChats(array $chats, int $authorId): void
	{
		$parentIds = [];

		foreach ($chats as $chat)
		{
			if (!$chat->hasParent() || !$chat->requiresParentMembership())
			{
				continue;
			}

			$parentId = (int)$chat->getParentChatId();

			if ($parentId > 0)
			{
				$parentIds[$parentId] = $parentId;
			}
		}

		if (empty($parentIds))
		{
			return;
		}

		$parents = array_filter(
			ChatFactory::getInstance()->getChatsByIds(array_values($parentIds)),
			static fn (Chat $chat) => !($chat instanceof Chat\NullChat)
		);

		if (empty($parents))
		{
			return;
		}

		Chat::fillSelfRelations($parents, $authorId);
	}

	/**
	 * Read of the link collection family, whose signature has no owner. The owner comes from the
	 * context the caller passes, and a caller that passes none gets nothing: the signature leaves it
	 * no way to name an owner, and the current user of the request is not the answer - an agent or a
	 * queue handler runs under a context set up for somebody else. Reads of the module name the owner
	 * and go through findForOwner().
	 */
	public static function find(
		array $filter,
		array $order = ['ID' => 'DESC'],
		?int $limit = null,
		?Context $context = null
	): self
	{
		if ($context === null)
		{
			return new static();
		}

		return static::findForOwner($context->getUserId(), $filter, $order, $limit);
	}

	public static function initByEntityCollection(EO_LinkPinPersonal_Collection $entityCollection): self
	{
		$pinCollection = new static();

		foreach ($entityCollection as $entity)
		{
			$pinCollection[] = PersonalPinItem::initByEntity($entity);
		}

		return $pinCollection;
	}

	public static function getCollectionElementClass(): string
	{
		return PersonalPinItem::class;
	}

	public static function getRestEntityName(): string
	{
		return 'personalPins';
	}

	/**
	 * The search runs inside the query that already carries the owner, so a text match can never
	 * reach across owners.
	 */
	protected static function processFilters(Query $query, array $filter, array $order): void
	{
		static::processSidebarFilters($query, $filter, $order, ['ID' => 'MESSAGE_ID']);

		if (isset($filter['SEARCH_MESSAGE']))
		{
			$query->whereLike('MESSAGE.MESSAGE', "%{$filter['SEARCH_MESSAGE']}%");
		}
	}
}
