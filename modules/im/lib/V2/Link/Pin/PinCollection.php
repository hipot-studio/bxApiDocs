<?php

namespace Bitrix\Im\V2\Link\Pin;

use Bitrix\Im\Model\EO_LinkPin_Collection;
use Bitrix\Im\Model\LinkPinTable;
use Bitrix\Im\V2\Common\SidebarFilterProcessorTrait;
use Bitrix\Im\V2\Entity\File\FilePopupItem;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Link\BaseLinkCollection;
use Bitrix\Im\V2\Link\Reminder\ReminderPopupItem;
use Bitrix\Im\V2\Message\AdditionalMessagePopupItem;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\ORM\Query\Query;

/**
 * @implements \IteratorAggregate<int,PinItem>
 * @method PinItem offsetGet($key)
 */
class PinCollection extends BaseLinkCollection
{
	use SidebarFilterProcessorTrait;

	/**
	 * The order key is the pinned message, not the pin row: the canonical sequence goes from the
	 * newest message to the oldest no matter when it was pinned. The `ID` key of the order argument
	 * keeps naming the direction only, so the paging cursor and the sort share the message key.
	 */
	public static function find(
		array $filter,
		array $order = ['ID' => 'DESC'],
		?int $limit = null,
		?Context $context = null
	): self
	{
		$context = $context ?? Locator::getContext();

		$pinOrder = ['MESSAGE_ID' => $order['ID'] ?? 'DESC'];

		$query = LinkPinTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
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
	 * The legacy REST method im.chat.pin.get keeps the order and the cursor of the pin row it was
	 * released with: its callers page by the `id` of the answer, and that field names the pin row, not
	 * the message. The canonical sequence of the feature lives in find(), where both the order and the
	 * cursor are the message - moving the old method onto it would make the cursor of its own answer
	 * point at another record.
	 */
	public static function findOrderedByPinId(array $filter, array $order = ['ID' => 'DESC'], ?int $limit = null): self
	{
		$pinOrder = ['ID' => $order['ID'] ?? 'DESC'];

		$query = LinkPinTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->setOrder($pinOrder)
		;

		if (isset($limit))
		{
			$query->setLimit($limit);
		}

		static::processSidebarFilters($query, $filter, $pinOrder);

		return static::initByEntityCollection($query->fetchCollection());
	}

	public static function initByEntityCollection(EO_LinkPin_Collection $entityCollection): self
	{
		$pinCollection = new static();

		foreach ($entityCollection as $entity)
		{
			$pinCollection[] = PinItem::initByEntity($entity);
		}

		return $pinCollection;
	}

	public static function getByMessages(MessageCollection $messages): self
	{
		$entities = LinkPinTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->whereIn('MESSAGE_ID', $messages->getIds())
			->fetchCollection()
		;

		if ($entities === null)
		{
			return new static();
		}

		$links = static::initByEntityCollection($entities);

		foreach ($links as $link)
		{
			$messageId = $link->getMessageId();

			if (!isset($messageId))
			{
				continue;
			}

			$link->setEntity($messages[$messageId]);
		}

		return $links;
	}

	public static function getCollectionElementClass(): string
	{
		return PinItem::class;
	}

	public static function getRestEntityName(): string
	{
		return 'pins';
	}

	protected static function processFilters(Query $query, array $filter, array $order): void
	{
		static::processSidebarFilters($query, $filter, $order, ['ID' => 'MESSAGE_ID']);

		if (isset($filter['SEARCH_MESSAGE']))
		{
			$query->whereLike('MESSAGE.MESSAGE', "%{$filter['SEARCH_MESSAGE']}%");
		}
	}

	public function getAuthorIds(): array
	{
		$authorIds = [];
		foreach ($this as $item)
		{
			$authorIds[$item->getAuthorId()] = $item->getAuthorId();
		}

		return $authorIds;
	}

	public function getMessageIds(): array
	{
		$messageIds = [];
		foreach ($this as $item)
		{
			$messageIds[$item->getMessageId()] = $item->getMessageId();
		}

		return $messageIds;
	}
}
