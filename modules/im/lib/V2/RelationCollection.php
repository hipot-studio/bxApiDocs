<?php

namespace Bitrix\Im\V2;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\OpenLineLiveChat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

/**
 * @implements \IteratorAggregate<int,Relation>
 * @implements Registry<Relation>
 * @method Relation offsetGet($key)
 */
class RelationCollection extends Collection implements RestConvertible, PopupDataAggregatable
{
	public const COMMON_FIELDS = [
		'ID',
		'MESSAGE_TYPE',
		'CHAT_ID',
		'USER_ID',
		'START_ID',
		'LAST_FILE_ID',
		'LAST_ID',
		'LAST_SEND_MESSAGE_ID',
		'UNREAD_ID',
		'NOTIFY_BLOCK',
		'MANAGER',
		'REASON',
		'IS_HIDDEN',
	];

	private const START_ID_PREFETCH_CHUNK = 300;

	protected static array $startIdStaticCache = [];

	protected array $relationsByUserId = [];
	protected ?self $activeOnly = null;
	protected ?self $notifyOnly = null;
	protected ?self $activeMembers = null;

	public static function getCollectionElementClass(): string
	{
		return Relation::class;
	}

	public static function find(
		array $filter,
		array $order = [],
		?int $limit = null,
		?Context $context = null,
		array $select = self::COMMON_FIELDS
	): self
	{
		$query = RelationTable::query()->setSelect($select);

		if (isset($limit))
		{
			$query->setLimit($limit);
		}

		static::processFilters($query, $filter, $order);

		return new static($query->fetchAll());
	}

	public function toRestFormat(array $option = []): ?array
	{
		$rest = [];
		foreach ($this as $relation)
		{
			$rest[] = $relation->toRestFormat($option);
		}

		return $rest;
	}

	public static function getRestEntityName(): string
	{
		return 'relations';
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([new UserPopupItem($this->getUserIds())], $excludedList);
	}

	public static function createFake(array $userIds, Chat $chat): self
	{
		$relations = new static();

		foreach ($userIds as $userId)
		{
			$relation = new Relation();
			$relation
				->setId(0)
				->setUserId($userId)
				->setChatId($chat->getId())
				->setMessageType($chat->getType())
				->setNotifyBlock(false)
				->markAsFake()
			;
			$relations->add($relation);
		}

		return $relations;
	}

	public static function getStartId(int $userId, int $chatId): int
	{
		if (isset(self::$startIdStaticCache[$chatId][$userId]))
		{
			return self::$startIdStaticCache[$chatId][$userId];
		}

		$relation = static::find(['CHAT_ID' => $chatId, 'USER_ID' => $userId], [], 1)->getByUserId($userId, $chatId);

		if ($relation === null)
		{
			return 0;
		}

		return $relation->getStartId() ?? 0;
	}

	/**
	 * Batched form of getStartId(): the relations already loaded in this request are taken from the same
	 * static cache, and only the users left over cost a single query instead of one query each.
	 *
	 * The answer of every user asked about goes into that cache, the zero of a user with no relation
	 * included. Only offsetSet() used to write there, so the watchers of an open chat, of an open channel
	 * and of an open project - who hold no relation at all - were asked about again by every publication
	 * of the same post.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> START_ID per user asked about, zero for a user with no relation
	 */
	public static function getStartIds(array $userIds, int $chatId): array
	{
		$startIds = [];
		$notCachedUserIds = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;

			if (isset(self::$startIdStaticCache[$chatId][$userId]))
			{
				$startIds[$userId] = self::$startIdStaticCache[$chatId][$userId];
			}
			else
			{
				$notCachedUserIds[$userId] = $userId;
			}
		}

		if (!empty($notCachedUserIds))
		{
			$relations = static::find(['CHAT_ID' => $chatId, 'USER_ID' => $notCachedUserIds]);

			foreach ($notCachedUserIds as $userId)
			{
				$startId = $relations->getByUserId($userId, $chatId)?->getStartId() ?? 0;
				self::$startIdStaticCache[$chatId][$userId] = $startId;
				$startIds[$userId] = $startId;
			}
		}

		return $startIds;
	}

	/**
	 * The same border for a whole list of chats, in one range over IX_IM_REL_9 (USER_ID, CHAT_ID): read one
	 * by one, every chat a caller names costs a select of its own. Fills the very cache {@see self::getStartId()}
	 * reads, so the per-chat calls that follow stay in memory.
	 *
	 * A chat the selection brings no relation for is cached as 0 - the answer a single read gives a reader
	 * without one. Otherwise the prefetch would miss exactly the chats it was made for: an open chat is
	 * read by people who are not members of it, and every one of those would fall back to a select. The
	 * remembered 0 lives by the rules of any other cached border - a relation added through the collection
	 * overwrites it, deleting one drops it.
	 *
	 * @param int[] $chatIds
	 */
	public static function fillStartIds(array $chatIds, int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$chatIds = array_filter(
			array_unique(array_map('intval', $chatIds)),
			static fn (int $chatId) => $chatId > 0 && !isset(self::$startIdStaticCache[$chatId][$userId])
		);

		if (empty($chatIds))
		{
			return;
		}

		foreach (array_chunk($chatIds, self::START_ID_PREFETCH_CHUNK) as $chunk)
		{
			$startIds = array_fill_keys($chunk, 0);

			$rows = RelationTable::query()
				->setSelect(['CHAT_ID', 'START_ID'])
				->where('USER_ID', $userId)
				->whereIn('CHAT_ID', $chunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$startIds[(int)$row['CHAT_ID']] = (int)$row['START_ID'];
			}

			foreach ($startIds as $chatId => $startId)
			{
				self::$startIdStaticCache[$chatId][$userId] = $startId;
			}
		}
	}

	/**
	 * Drops the cached START_ID after it was changed outside of the collection.
	 * Without $userId the whole chat is invalidated.
	 */
	public static function clearStartIdCache(int $chatId, ?int $userId = null): void
	{
		if ($userId === null)
		{
			unset(self::$startIdStaticCache[$chatId]);

			return;
		}

		unset(self::$startIdStaticCache[$chatId][$userId]);
	}

	public function getByUserId(int $userId, int $chatId): ?Relation
	{
		return $this->relationsByUserId[$chatId][$userId] ?? null;
	}

	/**
	 * @param int[] $userIds
	 */
	public function filterByUserIds(array $userIds): self
	{
		$userIds = array_combine($userIds, $userIds);

		return $this->filter(static fn (Relation $relation) => isset($userIds[(int)$relation->getUserId()]));
	}

	/**
	 * @param int[] $userIds
	 */
	public function filterExcludingUserIds(array $userIds): self
	{
		$userIds = array_combine($userIds, $userIds);

		return $this->filter(static fn (Relation $relation) => !isset($userIds[(int)$relation->getUserId()]));
	}

	public function filterActive(): self
	{
		if (isset($this->activeOnly))
		{
			return $this->activeOnly;
		}

		$this->activeOnly = new static();

		foreach ($this as $relation)
		{
			$isLiveChat = $relation->getChat() instanceof OpenLineLiveChat;
			if ($relation->getUser()->isActive() && ($isLiveChat || !$relation->getUser()->isConnector()))
			{
				$this->activeOnly->add($relation);
			}
		}

		return $this->activeOnly;
	}

	public function filterActiveMembers(): self
	{
		if (isset($this->activeMembers))
		{
			return $this->activeMembers;
		}

		$this->activeMembers = new static();

		foreach ($this->filterActive() as $relation)
		{
			if (!$relation->isHidden())
			{
				$this->activeMembers->add($relation);
			}
		}

		return $this->activeMembers;
	}

	public function filterNotifySubscribed(): self
	{
		if (isset($this->notifyOnly))
		{
			return $this->notifyOnly;
		}

		$this->notifyOnly = new static();

		foreach ($this as $relation)
		{
			if (!($relation->getNotifyBlock() ?? false))
			{
				$this->notifyOnly->add($relation);
			}
		}

		return $this->notifyOnly;
	}

	public function hasUser(int $userId, int $chatId): bool
	{
		return isset($this->relationsByUserId[$chatId][$userId]);
	}

	public function getUserIds(): array
	{
		$userIds = [];
		foreach ($this as $relation)
		{
			$userIds[$relation->getUserId()] = $relation->getUserId();
		}

		return $userIds;
	}


	public function getUsers(): UserCollection
	{
		return new UserCollection($this->getUserIds());
	}

	protected static function processFilters(Query $query, array $filter, array $order): void
	{
		$orderField = null;
		$relationOrder = [];

		if (isset($filter['CHAT_ID']))
		{
			$query->where('CHAT_ID', (int)$filter['CHAT_ID']);
		}

		if (isset($filter['MANAGER']))
		{
			$query->where('MANAGER', (string)$filter['MANAGER']);
		}

		if (isset($filter['USER_ID']))
		{
			if (is_array($filter['USER_ID']) && !empty($filter['USER_ID']))
			{
				$query->whereIn('USER_ID', $filter['USER_ID']);
			}
			else
			{
				$query->where('USER_ID', (int)$filter['USER_ID']);
			}
		}

		if (isset($filter['!USER_ID']))
		{
			if (is_array($filter['!USER_ID']) && !empty($filter['!USER_ID']))
			{
				$query->whereNotIn('USER_ID', $filter['!USER_ID']);
			}
			else
			{
				$query->whereNot('USER_ID', (int)$filter['!USER_ID']);
			}
		}

		if (isset($filter['MESSAGE_TYPE']))
		{
			$query->where('MESSAGE_TYPE', (string)$filter['MESSAGE_TYPE']);
		}

		foreach (['ID', 'USER_ID', 'LAST_SEND_MESSAGE_ID'] as $allowedFieldToOrder)
		{
			if (isset($order[$allowedFieldToOrder]))
			{
				$orderField = $allowedFieldToOrder;
				$relationOrder[$allowedFieldToOrder] = $order[$allowedFieldToOrder];
				break;
			}
		}

		if (isset($orderField))
		{
			$query->setOrder($relationOrder);
		}

		if (isset($filter['LAST_ID']))
		{
			$operator = '<';
			if (isset($orderField) && $relationOrder[$orderField] === 'ASC')
			{
				$operator = '>';
			}
			$query->where($orderField, $operator, (int)$filter['LAST_ID']);
		}

		if (isset($filter['ACTIVE']))
		{
			$query->where('USER.ACTIVE', $filter['ACTIVE']);
		}

		if (isset($filter['ONLY_INTERNAL_TYPE']) && $filter['ONLY_INTERNAL_TYPE'])
		{
			$query->where(
				Query::filter()
					->logic('or')
					->whereNotIn('USER.EXTERNAL_AUTH_ID', UserTable::getExternalUserTypes())
					->whereNull('USER.EXTERNAL_AUTH_ID')
			);
		}

		if (isset($filter['ONLY_INTRANET']) && $filter['ONLY_INTRANET'])
		{
			if (\Bitrix\Main\Loader::includeModule('extranet'))
			{
				$query->where('USER.IS_INTRANET_USER', true);
			}
		}

		if (isset($filter['REASON']))
		{
			$query->where('REASON', (string)$filter['REASON']);
		}

		if (isset($filter['IS_HIDDEN']))
		{
			$query->where('IS_HIDDEN', $filter['IS_HIDDEN']);
		}

		if (isset($filter['NOTIFY_BLOCK']))
		{
			$query->where('NOTIFY_BLOCK', $filter['NOTIFY_BLOCK']);
		}
	}

	public function offsetSet($key, $value): void
	{
		/** @var Relation $value */
		parent::offsetSet($key, $value);

		if ($value->getUserId() !== null && $value->getChatId() !== null)
		{
			$this->relationsByUserId[$value->getChatId()][$value->getUserId()] = $value;
			if ($value->getStartId() !== null)
			{
				static::$startIdStaticCache[$value->getChatId()][$value->getUserId()] = $value->getStartId();
			}
		}

		$this->activeOnly = null;
		$this->notifyOnly = null;
		$this->activeMembers = null;
	}

	public function offsetUnset(mixed $key)
	{
		/** @var Relation|null $value */
		$value = $this[$key] ?? null;
		if ($value)
		{
			unset($this->relationsByUserId[$value->getChatId()][$value->getUserId()]);
			unset(static::$startIdStaticCache[$value->getChatId()][$value->getUserId()]);
		}
		parent::offsetUnset($key);

		$this->activeOnly = null;
		$this->notifyOnly = null;
		$this->activeMembers = null;
	}

	public function onAfterRelationDelete(int $chatId, int $userId): void
	{
		$relation = $this->getByUserId($userId, $chatId);
		if ($relation)
		{
			unset($this[$relation->getId()]);
		}
		unset($this->relationsByUserId[$chatId][$userId]);
		unset(self::$startIdStaticCache[$chatId][$userId]);
	}

	public function clearActiveMemberCache(): void
	{
		$this->activeMembers = null;
	}
}
