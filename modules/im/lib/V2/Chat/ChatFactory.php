<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Analytics\CopilotAnalytics;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Cache\CacheLevel;
use Bitrix\Im\V2\Cache\NullEntity;
use Bitrix\Im\V2\Chat\Cache\ChatCacheRegistry;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Bitrix\Im\V2\Chat\Add\AddResult;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Im\V2\Chat\Cache\ChatMapper;
use Generator;

class ChatFactory
{
	use ContextCustomer;

	private const LOCK_TIMEOUT = 3;

	public const CHATS_FETCH_CHUNK_SIZE = 50;

	protected static self $instance;

	private function __construct() {}

	/**
	 * Returns current instance of the Dispatcher.
	 * @return self
	 */
	public static function getInstance(): self
	{
		if (isset(self::$instance))
		{
			return self::$instance;
		}

		self::$instance = new static();

		return self::$instance;
	}



	//region Chat actions

	/**
	 * @param array|int|string $params
	 * @return Chat|null
	 */
	public function getChat($params): ?Chat
	{
		$type = $params['TYPE'] ?? $params['MESSAGE_TYPE'] ?? '';

		if (empty($params))
		{
			return null;
		}
		if (is_numeric($params))
		{
			$params = ['CHAT_ID' => (int)$params];
		}
		elseif (is_string($params))
		{
			$params = ['DIALOG_ID' => $params];
			if (\Bitrix\Im\Common::isChatId($params['DIALOG_ID']))
			{
				$params['CHAT_ID'] = \Bitrix\Im\Dialog::getChatId($params['DIALOG_ID']);
			}
		}

		if (isset($params['CHAT_ID'])) // we can get it from cache by primaryId
		{
			return $this->tryFromCacheOrFindById((int)$params['CHAT_ID']);
		}

		$findResult = $this->findChat($params);
		if ($findResult->hasResult())
		{
			$chatParams = $findResult->getResult();

			return $this->initChat($chatParams);
		}

		if (
			$type === Chat::IM_TYPE_SYSTEM
			|| $type ===  Chat::IM_TYPE_PRIVATE
		)
		{
			$addResult = $this->addChat($params);
			if ($addResult->hasResult())
			{
				return
					$addResult
						->getChat()
						?->setContext($this->context)
				;
			}
		}

		return null;
	}

	protected function tryFromCacheOrFindById(int $chatId): Chat
	{
		$result = ServiceLocator::getInstance()
			->get(ChatCacheRegistry::class)
			?->getChatDataManager()
			->getOrSet(entityId: $chatId, dataProvider: fn() => $this->getRawById($chatId))
		;

		return $result->getResult() ?? new NullChat();
	}

	protected function tryFromCache(int $chatId, CacheLevel $cacheLevel = CacheLevel::All): ?Chat
	{
		$result = ServiceLocator::getInstance()
			->get(ChatCacheRegistry::class)
			?->getChatDataManager()
			->get(entityId: $chatId, cacheLevel: $cacheLevel)
		;

		return $result->getResult();
	}

	/**
	 * @return Chat|NotifyChat|null
	 */
	public function getNotifyFeed($userId = null): ?NotifyChat
	{
		if (!$userId)
		{
			$userId = $this->getContext()->getUserId();
		}

		$params = [
			'TYPE' => Chat::IM_TYPE_SYSTEM,
			'TO_USER_ID' => $userId,
		];

		return $this->getChat($params);
	}

	public function getEntityChat(string $entityType, string $entityId): Chat
	{
		$chatId = $this->getEntityChatId($entityType, $entityId);

		return Chat::getInstance($chatId);
	}

	/**
	 * @param string $entityType
	 * @param int|string $entityId
	 * @return Chat|GeneralChat|null
	 */
	public function getGeneralChat(): ?GeneralChat
	{
		return GeneralChat::get();
	}

	public function getGeneralChannel(): ?ChannelChat
	{
		return GeneralChannel::get();
	}

	/**
	 * @return Chat|Chat\PrivateChat|null
	 */
	public function getPrivateChat($fromUserId, $toUserId): ?Chat\PrivateChat
	{
		$params = [
			'TYPE' => Chat::IM_TYPE_PRIVATE,
			'FROM_USER_ID' => $fromUserId,
			'TO_USER_ID' => $toUserId,
		];

		return $this->getChat($params);
	}

	/**
	 * @return Chat|Chat\FavoriteChat|null
	 */
	public function getPersonalChat($userId = null): ?Chat\FavoriteChat
	{
		if (!$userId)
		{
			$userId = $this->getContext()->getUserId();
		}

		$params = [
			'TYPE' => Chat::IM_TYPE_PRIVATE,
			'FROM_USER_ID' => $userId,
			'TO_USER_ID' => $userId,
		];

		return $this->getChat($params);
	}
	//endregion

	//region Chat Create
	/**
	 * @param array|null $params
	 * @return Chat
	 */
	public function initChat(?array $params = null): Chat
	{
		$mapper = ServiceLocator::getInstance()->get(ChatMapper::class);

		$chat = $mapper($params);
		$chat->setContext($this->context);

		return $chat;
	}

	/**
	 * @param array|null $params
	 * @return Chat|NotifyChat
	 */
	public function createNotifyFeed(?array $params = null): Chat
	{
		$params = $params ?? [];
		$params['TYPE'] = Chat::IM_TYPE_SYSTEM;

		return $this->initChat($params);
	}

	/**
	 * @param array|null $params
	 * @return Chat|FavoriteChat
	 */
	public function createPersonalChat(?array $params = null): Chat
	{
		$params = $params ?? [];
		$params['ENTITY_TYPE'] = Chat::ENTITY_TYPE_FAVORITE;

		return $this->initChat($params);
	}

	/**
	 * @param array|null $params
	 * @return Chat|PrivateChat
	 */
	public function createPrivateChat(?array $params = null): Chat
	{
		$params = $params ?? [];
		$params['TYPE'] = Chat::IM_TYPE_PRIVATE;

		return $this->initChat($params);
	}

	/**
	 * @param array|null $params
	 * @return Chat|OpenChat
	 */
	public function createOpenChat(?array $params = null): Chat
	{
		$params = $params ?? [];
		$params['TYPE'] = Chat::IM_TYPE_OPEN;

		return $this->initChat($params);
	}

	/**
	 * @param array|null $params
	 * @return Chat|OpenLineChat
	 */
	public function createOpenLineChat(?array $params = null): Chat
	{
		$params = $params ?? [];
		$params['TYPE'] = Chat::IM_TYPE_OPEN_LINE;

		return $this->initChat($params);
	}

	//endregion

	//region Chat Find


	/**
	 * @param int $chatId
	 * @return Chat|null
	 */
	public function getChatById(int $chatId): Chat
	{
		return $this->tryFromCacheOrFindById($chatId);
	}

	public function getChatFromCache(int $chatId, CacheLevel $cacheLevel = CacheLevel::All): ?Chat
	{
		return $this->tryFromCache($chatId, $cacheLevel);
	}

	/**
	 * Loads chats by a list of ids and warms up the same cache keys as getChatById, so that subsequent
	 * per-chat lookups (Chat::getInstance / getChatById) read from the hot cache instead of issuing one
	 * query per chat. Already cached ids are promoted to the static level without touching the database;
	 * only true cache misses are selected, one query per chunk. Absence is cached the same way
	 * getChatById caches it, so a deleted source costs one query for the whole feed rather than one per
	 * message that was forwarded from it.
	 *
	 * Access is not checked and nothing is filtered out: the caller decides what to show.
	 *
	 * @param int[] $chatIds
	 * @return array<int, Chat> Chats by id in the order of $chatIds, unknown ids resolve to NullChat.
	 */
	public function getChatsByIds(array $chatIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $chatIds), static fn (int $id) => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$cacheManager = ServiceLocator::getInstance()->get(ChatCacheRegistry::class)?->getChatDataManager();

		$loadedChats = [];
		$missedIds = [];
		foreach ($ids as $id)
		{
			$chat = $cacheManager?->get($id, CacheLevel::All)->getResult();
			if ($chat instanceof Chat)
			{
				$cacheManager->set($chat, CacheLevel::Static);
				$loadedChats[$id] = $chat;

				continue;
			}

			// Asked of the static level by name, because a negative entry is only ever returned by a
			// read whose level matches the engine exactly: CacheLevel::All falls through the negative
			// hit down the chain and comes back as a plain miss (Cache\Engine\BaseCacheEngine::get()).
			if ($cacheManager?->get($id, CacheLevel::Static)->isNegativeHit() !== true)
			{
				$missedIds[] = $id;
			}
		}

		// the chat is built by the mapper rather than by initChat(): the object is written into a shared
		// cache, and initChat() would stamp the context of this factory onto it. Written without tags,
		// as the per-chat path does: the chat cache is invalidated by id, see cleanCache()
		$mapper = ServiceLocator::getInstance()->get(ChatMapper::class);
		foreach ($this->getChatsFromDb($missedIds) as $id => $chatData)
		{
			$chat = $mapper($chatData);
			// writing the chat to the cache serializes it through the counter getters, each of which would
			// otherwise fill its value with a query of its own. The batch row carries the counters actual,
			// so the mark spares a query per chat and leaves the cache with the snapshot taken here, the
			// same trade V2\Sync\ChatsSync makes on its own batch
			$chat->markFilledNonCachedData(true);
			$cacheManager?->set($chat, CacheLevel::All);
			$loadedChats[$id] = $chat;
		}

		// An id the query did not answer belongs to no chat, and the request must not ask again: the
		// source of a forwarded message is lifted per message, so every message forwarded from a
		// deleted chat would otherwise cost a query. The entry is the same NullEntity getChatById
		// leaves through getOrSet(), but kept to the static level: absence lives for this request,
		// and a chat created later must not meet it in a shared cache.
		foreach ($missedIds as $id)
		{
			if (!isset($loadedChats[$id]))
			{
				$cacheManager?->set(new NullEntity($id), CacheLevel::Static);
			}
		}

		$chats = [];
		foreach ($ids as $id)
		{
			$chats[$id] = $loadedChats[$id] ?? new NullChat();
		}

		return $chats;
	}

	/**
	 * @param array $params
	 * <pre>
	 * [
	 * 	(string) MESSAGE_TYPE - Message type:
	 * 		@see \IM_MESSAGE_SYSTEM = S - notification,
	 * 		@see \IM_MESSAGE_PRIVATE = P - private chat,
	 * 		@see \IM_MESSAGE_CHAT = C - group chat,
	 * 		@see \IM_MESSAGE_OPEN = O - open chat,
	 * 		@see \IM_MESSAGE_OPEN_LINE = L - open line chat.
	 *
	 * 	(string|int) DIALOG_ID - Dialog Id:
	 * 		chatNNN - chat,
	 * 		sgNNN - sonet group,
	 * 		crmNNN - crm chat,
	 * 		NNN - recipient user.
	 *
	 * 	(int) CHAT_ID - Chat Id.
	 * 	(int) TO_USER_ID - Recipient user Id.
	 * 	(int) FROM_USER_ID - Sender user Id.
	 * ]
	 * </pre>
	 * @return Result
	 */
	protected function findChat(array $params): Result
	{
		$result = new Result;

		if (isset($params['TYPE']))
		{
			$params['MESSAGE_TYPE'] = $params['TYPE'];
		}

		if (empty($params['CHAT_ID']) && !empty($params['DIALOG_ID']))
		{
			if (\Bitrix\Im\Common::isChatId($params['DIALOG_ID']))
			{
				$params['CHAT_ID'] = \Bitrix\Im\Dialog::getChatId($params['DIALOG_ID']);
				if (!isset($params['MESSAGE_TYPE']))
				{
					$params['MESSAGE_TYPE'] = Chat::IM_TYPE_CHAT;
				}
			}
			else
			{
				$params['TO_USER_ID'] = (int)$params['DIALOG_ID'];
				$params['MESSAGE_TYPE'] = Chat::IM_TYPE_PRIVATE;
			}
		}

		$chatClass = ServiceLocator::getInstance()->get(ChatClassResolver::class)->resolveForFind($params);
		if ($chatClass === NullChat::class)
		{
			return $result->addError(new ChatError(ChatError::WRONG_TYPE));
		}

		return $chatClass::find($params, $this->context);
	}

	private function getRawById(int $id): ?array
	{
		$chat = ChatTable::query()
			->setSelect(['*', '_ALIAS' => 'ALIAS.ALIAS'])
			->where('ID', $id)
			->fetch()
		;

		if (!$chat)
		{
			return null;
		}

		$chat['ALIAS'] = $chat['_ALIAS'];

		return $chat;
	}

	/**
	 * Yields rows chunk by chunk, so raw data of the whole id list is never held at once. The select
	 * repeats getRawById(): a narrower one would fill the cache with incomplete chats.
	 *
	 * @param int[] $ids
	 * @return Generator<int, array>
	 */
	private function getChatsFromDb(array $ids): Generator
	{
		foreach (array_chunk($ids, self::CHATS_FETCH_CHUNK_SIZE) as $chunk)
		{
			$rows = ChatTable::query()
				->setSelect(['*', '_ALIAS' => 'ALIAS.ALIAS'])
				->whereIn('ID', $chunk)
				->exec()
			;

			while ($chat = $rows->fetch())
			{
				$chat['ALIAS'] = $chat['_ALIAS'];

				yield (int)$chat['ID'] => $chat;
			}
		}
	}

	private function getEntityChatId(string $entityType, string $entityId, ?int $authorId = null): ?int
	{
		$query = ChatTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
			->setLimit(1)
		;

		if ($authorId !== null)
		{
			$query->where('AUTHOR_ID', $authorId);
		}

		$row = $query->fetch();

		if (!$row)
		{
			return null;
		}

		return (int)$row['ID'];
	}

	//endregion

	//region Add new chat

	/**
	 * @param array $params
	 * @return AddResult
	 */
	public function addChat(array $params): AddResult
	{
		$params['ENTITY_TYPE'] ??= '';
		$params['TYPE'] ??= Chat::IM_TYPE_CHAT;

		// Temporary workaround for Open chat type
		if (($params['SEARCHABLE'] ?? 'N') === 'Y')
		{
			if ($params['TYPE'] === Chat::IM_TYPE_CHAT)
			{
				$params['TYPE'] = Chat::IM_TYPE_OPEN;
			}
			elseif ($params['TYPE'] === Chat::IM_TYPE_CHANNEL)
			{
				$params['TYPE'] = Chat::IM_TYPE_OPEN_CHANNEL;
			}
			elseif ($params['TYPE'] === Chat::IM_TYPE_COLLAB)
			{
				$params['TYPE'] = Chat::IM_TYPE_OPEN_COLLAB;
			}
			else
			{
				$params['SEARCHABLE'] = 'N';
			}
		}

		ChatAnalytics::blockSingleUserEvents();

		$initParams = [
			'TYPE' => $params['TYPE'] ?? null,
			'ENTITY_TYPE' => $params['ENTITY_TYPE'] ?? null,
			'FROM_USER_ID' => $params['FROM_USER_ID'] ?? null,
			'TO_USER_ID' => $params['TO_USER_ID'] ?? null,
		];
		$chat = $this->initChat($initParams);
		$addResult = $chat->add($params, $this->context);

		if ($chat instanceof NullChat)
		{
			return $addResult->addError(new ChatError(ChatError::CREATION_ERROR));
		}

		$resultChat = $addResult->getChat();
		if ($resultChat instanceof Chat && ($params['SKIP_ANALYTICS'] ?? 'N') !== 'Y')
		{
			(new ChatAnalytics($resultChat))->addSubmitCreateNew();
		}

		// BitrixGPT v2 ai-label: create_new_chat for copilot chats under flag.
		// Respects SKIP_ANALYTICS like submit_create_new above; callers that skip generic
		// analytics but still need the funnel labels emit them explicitly (see DraftChatService).
		// The initiator id is pinned before the async label is scheduled: the global context
		// resolves the current user lazily, which may differ by the time the job runs.
		if (
			$resultChat instanceof CopilotChat
			&& Features::isBitrixGptV2Available()
			&& ($params['SKIP_ANALYTICS'] ?? 'N') !== 'Y'
		)
		{
			(new CopilotAnalytics($resultChat))
				->withContextUser($this->getContext()->getUserId())
				->addCreateNewChat()
			;
		}

		return $addResult;
	}

	/**
	 * Creates a chat ensuring uniqueness for the provided ENTITY_TYPE and ENTITY_ID pair.
	 * @param array $params ENTITY_TYPE and ENTITY_ID are required keys
	 * @return AddResult
	 * @see static::addChat()
	 *
	 */
	public function addUniqueChat(array $params): AddResult
	{
		if (!isset($params['ENTITY_TYPE']))
		{
			return (new AddResult())->addError(new ChatError(ChatError::ENTITY_TYPE_EMPTY));
		}
		if (!isset($params['ENTITY_ID']))
		{
			return (new AddResult())->addError(new ChatError(ChatError::ENTITY_ID_EMPTY));
		}

		return $this->addUniqueChatInternal(
			$params,
			(string)$params['ENTITY_TYPE'],
			(string)$params['ENTITY_ID'],
			null,
		);
	}

	/**
	 * Creates a chat ensuring uniqueness for the (ENTITY_TYPE, ENTITY_ID, AUTHOR_ID) triple.
	 * @param array $params ENTITY_TYPE, ENTITY_ID and AUTHOR_ID are required keys
	 * @return AddResult
	 * @see static::addUniqueChat()
	 */
	public function addUniqueChatPerAuthor(array $params): AddResult
	{
		if (!isset($params['ENTITY_TYPE']))
		{
			return (new AddResult())->addError(new ChatError(ChatError::ENTITY_TYPE_EMPTY));
		}
		if (!isset($params['ENTITY_ID']))
		{
			return (new AddResult())->addError(new ChatError(ChatError::ENTITY_ID_EMPTY));
		}
		if (!isset($params['AUTHOR_ID']) || (int)$params['AUTHOR_ID'] <= 0)
		{
			return (new AddResult())->addError(new ChatError(ChatError::AUTHOR_ID_EMPTY));
		}

		return $this->addUniqueChatInternal(
			$params,
			(string)$params['ENTITY_TYPE'],
			(string)$params['ENTITY_ID'],
			(int)$params['AUTHOR_ID'],
		);
	}

	private function addUniqueChatInternal(
		array $params,
		string $entityType,
		string $entityId,
		?int $authorId,
	): AddResult
	{
		$result = new AddResult();
		$lockName = self::getUniqueAdditionLockName($entityType, $entityId, $authorId);
		$connection = Application::getConnection();

		$isLocked = $connection->lock($lockName, self::LOCK_TIMEOUT);
		if (!$isLocked)
		{
			return $result->addError(new ChatError(ChatError::CREATION_ERROR));
		}

		try
		{
			$chatId = $this->getEntityChatId($entityType, $entityId, $authorId);
			if ($chatId)
			{
				return $result->setResult([
					'CHAT_ID' => $chatId,
					'CHAT' => Chat::getInstance($chatId),
					'ALREADY_EXISTS' => true,
				]);
			}

			return $this->addChat($params);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $result->addError(new ChatError(ChatError::CREATION_ERROR));
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private static function getUniqueAdditionLockName(string $entityType, string $entityId, ?int $authorId = null): string
	{
		if ($authorId !== null)
		{
			return "add_unique_chat_{$entityType}_{$entityId}_{$authorId}";
		}

		return "add_unique_chat_{$entityType}_{$entityId}";
	}

	//endregion

	//region Cache

	public function cleanCache(int $id, CacheLevel $cacheLevel = CacheLevel::All): void
	{
		ServiceLocator::getInstance()
			->get(ChatCacheRegistry::class)
			?->getChatDataManager()
			->clear(entityId: $id, cacheLevel: $cacheLevel)
		;
	}

	//endregion
}
