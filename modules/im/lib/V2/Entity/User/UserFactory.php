<?php

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\Bot;
use Bitrix\Im\Color;
use Bitrix\Im\Model\StatusTable;
use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Cache\CacheLevel;
use Bitrix\Im\V2\Cache\NullEntity;
use Bitrix\Im\V2\Entity\User\Cache\UserCacheRegistry;
use Bitrix\Im\V2\Entity\User\Cache\UserMapper;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use CVoxImplantPhone;
use Generator;

class UserFactory
{
	private const COMMON_SELECT_FIELD = [
		'ID',
		'LAST_NAME',
		'NAME',
		'EMAIL',
		'LOGIN',
		'PERSONAL_PHOTO',
		'SECOND_NAME',
		'PERSONAL_BIRTHDAY',
		'WORK_POSITION',
		'PERSONAL_GENDER',
		'EXTERNAL_AUTH_ID',
		'PERSONAL_WWW',
		'ACTIVE',
		'LANGUAGE_ID',
		'WORK_PHONE',
		'TIME_ZONE',
		'PERSONAL_MOBILE',
		'PERSONAL_PHONE',
		'COLOR' => 'ST.COLOR',
		'STATUS' => 'ST.STATUS',
	];

	public const USERS_FETCH_CHUNK_SIZE = 300;

	protected static ?self $instance = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		if (isset(self::$instance))
		{
			return self::$instance;
		}

		self::$instance = new static();

		return self::$instance;
	}

	public function getUserById(int $id): User
	{
		$result = ServiceLocator::getInstance()
			->get(UserCacheRegistry::class)
			?->getUserDataManager()
			->getOrSet(
				entityId: $id,
				dataProvider: fn() => $this->prepareUserData($this->getUserFromDb($id)),
				tags: [self::getCacheTag($id)],
			)
		;

		$user = $result->getResult();

		return $user ?? new NullUser();
	}

	/**
	 * Loads users in a single query and warms up the same per-user cache keys as getUserById.
	 * Unlike getUserById, absence of a user is not cached here: unknown ids are queried on every call.
	 *
	 * @param int[] $ids
	 * @return array<int, User> Users by id in the order of $ids, unknown ids resolve to NullUser.
	 */
	public function getUsersByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$cacheManager = ServiceLocator::getInstance()->get(UserCacheRegistry::class)?->getUserDataManager();

		$cachedUsers = [];
		$missedIds = [];
		foreach ($ids as $id)
		{
			$user = $cacheManager->get($id, CacheLevel::All)->getResult();
			if ($user instanceof User)
			{
				$cacheManager->set($user, CacheLevel::Static);
				$cachedUsers[$id] = $user;
			}
			else
			{
				$missedIds[] = $id;
			}
		}

		foreach ($this->getUsersFromDb($missedIds) as $id => $userData)
		{
			$user = $this->initUser($this->prepareUserData($userData));
			$cacheManager->set($user, CacheLevel::All, [self::getCacheTag($id)]);
			$cachedUsers[$id] = $user;
		}

		$users = [];
		foreach ($ids as $id)
		{
			$users[$id] = $cachedUsers[$id] ?? new NullUser();
		}

		return $users;
	}

	/**
	 * Warms the user cache for a batch of ids, so that subsequent per-user lookups
	 * (User::getInstance / getUserById) read from the hot cache instead of issuing one SQL per user.
	 * Already cached ids — negative entries included — are promoted to the static level without
	 * touching the database; only true cache misses are selected and written straight to both cache
	 * levels — without a per-id getOrSet() that would re-probe the caches just checked. The levels
	 * are probed one by one because only a level-exact get() reports a NegativeHit: the All-level
	 * chain collapses it to a miss, which would re-select ids already known to be absent. The rows
	 * are selected by the same query as getUserFromDb(), prepared by the same prepareUserData() and
	 * mapped by the same UserMapper as the cache read path, with the same tags, so the cached
	 * representation is identical to the per-user read. Ids missing from the database are cached
	 * negatively, as the per-user path does.
	 *
	 * @param int[] $userIds
	 */
	public function preloadUsers(array $userIds): void
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn ($id) => (int)$id, $userIds),
			static fn (int $id) => $id > 0,
		)));

		if (empty($ids))
		{
			return;
		}

		$cacheManager = ServiceLocator::getInstance()
			->get(UserCacheRegistry::class)
			?->getUserDataManager()
		;

		if ($cacheManager === null)
		{
			return;
		}

		$missingIds = [];
		foreach ($ids as $id)
		{
			if (!$cacheManager->get($id, CacheLevel::Static)->isMiss())
			{
				continue;
			}

			$persistentEntity = $cacheManager->get($id, CacheLevel::Persistent)->object;
			if ($persistentEntity !== null)
			{
				$cacheManager->set($persistentEntity, CacheLevel::Static);
				continue;
			}

			$missingIds[] = $id;
		}

		if (empty($missingIds))
		{
			return;
		}

		$mapper = new UserMapper();
		$loadedIds = [];
		foreach ($this->getUsersFromDb($missingIds) as $id => $row)
		{
			$userData = $this->prepareUserData($row);
			if ($userData === null)
			{
				continue;
			}

			$cacheManager->set($mapper($userData), CacheLevel::All, [self::getCacheTag($id)]);
			$loadedIds[$id] = true;
		}

		// Ids absent from the batch select (physically deleted users) get the same negative entry
		// the per-user getOrSet() path would cache, so later per-user lookups do not fall through
		// to one SQL per missing id.
		foreach ($missingIds as $id)
		{
			if (!isset($loadedIds[$id]))
			{
				$cacheManager->set(new NullEntity($id), CacheLevel::All, [self::getCacheTag($id)]);
			}
		}
	}

	public function initUser(array $userData): User
	{
		$userClass = ServiceLocator::getInstance()->get(UserClassResolver::class)->resolve($userData);

		return $userClass::initByArray($userData);
	}

	public function prepareUserData(?array $userData): ?array
	{
		if ($userData === null)
		{
			return null;
		}

		$avatar = \CIMChat::GetAvatarImage($userData['PERSONAL_PHOTO']) ?: '';

		$preparedUserData = $userData;
		$preparedUserData['COLOR'] = $this->getColor($userData);
		$preparedUserData['STATUS'] = $userData['STATUS'] ?? null;
		$preparedUserData['NAME'] = \Bitrix\Im\User::formatFullNameFromDatabase($userData);
		$preparedUserData['FIRST_NAME'] = \Bitrix\Im\User::formatNameFromDatabase($userData);
		$preparedUserData['BIRTHDAY'] =
			$userData['PERSONAL_BIRTHDAY'] instanceof \Bitrix\Main\Type\Date
				? $userData['PERSONAL_BIRTHDAY']->format('d-m')
				: false
		;
		$preparedUserData['AVATAR'] = $avatar !== '/bitrix/js/im/images/blank.gif' ? $avatar : '';
		$preparedUserData['AVATAR_HR'] = $avatar;
		$preparedUserData['AVATAR_ID'] = (int)$userData['PERSONAL_PHOTO'];
		$preparedUserData['IS_EXTRANET'] = $this->isExtranet($userData);
		$preparedUserData['IS_NETWORK'] = $this->isNetwork($userData);
		$preparedUserData['IS_BOT'] = $this->isBot($userData);
		$preparedUserData['IS_CONNECTOR'] = $this->isConnector($userData);
		$preparedUserData['LANGUAGE_ID'] = $userData['LANGUAGE_ID'] ?? null;

		if (Loader::includeModule('voximplant'))
		{
			$preparedUserData['WORK_PHONE'] = CVoxImplantPhone::Normalize($userData['WORK_PHONE']) ?: null;
			$preparedUserData['PERSONAL_MOBILE'] = CVoxImplantPhone::Normalize($userData['PERSONAL_MOBILE']) ?: null;
			$preparedUserData['PERSONAL_PHONE'] = CVoxImplantPhone::Normalize($userData['PERSONAL_PHONE']) ?: null;
		}

		if (Loader::includeModule('intranet'))
		{
			$innerPhone = preg_replace("/[^0-9\#\*]/i", "", $userData['UF_PHONE_INNER'] ?? '');
			if ($innerPhone)
			{
				$preparedUserData['INNER_PHONE'] = $innerPhone;
			}
		}

		return $preparedUserData;
	}

	protected function getUserFromDb(int $id): ?array
	{
		return $this->createUserQuery()
			->setLimit(1)
			->where('ID', $id)
			->fetch() ?: null
		;
	}

	/**
	 * Yields rows chunk by chunk, so raw data of the whole id list is never held at once.
	 *
	 * @param int[] $ids
	 * @return Generator<int, array>
	 */
	protected function getUsersFromDb(array $ids): Generator
	{
		foreach (array_chunk($ids, self::USERS_FETCH_CHUNK_SIZE) as $chunk)
		{
			$rows = $this->createUserQuery()->whereIn('ID', $chunk)->exec();
			while ($userData = $rows->fetch())
			{
				yield (int)$userData['ID'] => $userData;
			}
		}
	}

	private function createUserQuery(): Query
	{
		$query = UserTable::query()
			->setSelect(self::COMMON_SELECT_FIELD)
			->registerRuntimeField(
				'ST',
				new Reference(
					'ST',
					StatusTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			)
		;

		if (Loader::includeModule('intranet'))
		{
			$query
				->addSelect('UF_DEPARTMENT')
				->addSelect('UF_PHONE_INNER')
				->addSelect('UF_ZOOM')
				->addSelect('UF_SKYPE')
				->addSelect('UF_SKYPE_LINK')
			;
		}

		if (Loader::includeModule('voximplant'))
		{
			$query->addSelect('UF_VI_PHONE');
		}

		return $query;
	}

	private static function getCacheTag(int $id): string
	{
		return "USER_NAME_{$id}";
	}

	protected function isExtranet(array $params): bool
	{
		return \CIMContactList::IsExtranet($params);
	}

	protected function isNetwork(array $params): bool
	{
		$isNetworkUser = $params['EXTERNAL_AUTH_ID'] === \CIMContactList::NETWORK_AUTH_ID;
		$isNetworkBot = false;
		if ($params['EXTERNAL_AUTH_ID'] === Bot::EXTERNAL_AUTH_ID)
		{
			$isNetworkBot = BotData::getInstance((int)$params['ID'])->isNetworkBot();
		}

		return $isNetworkUser || $isNetworkBot;
	}

	protected function isBot(array $params): bool
	{
		return $params['EXTERNAL_AUTH_ID'] === Bot::EXTERNAL_AUTH_ID;
	}

	protected function isConnector(array $params): bool
	{
		return $params['EXTERNAL_AUTH_ID'] === 'imconnector';
	}

	protected function getColor(array $userData): string
	{
		return $userData['COLOR']
			? Color::getColor($userData['COLOR'])
			: $this->getColorByUserIdAndGender((int)$userData['ID'], $userData['PERSONAL_GENDER'] === 'M'? 'M': 'F');
	}

	protected function getColorByUserIdAndGender(int $id, string $gender): string
	{
		$code = Color::getCodeByNumber($id);
		if ($gender === 'M')
		{
			$replaceColor = Color::getReplaceColors();
			if (isset($replaceColor[$code]))
			{
				$code = $replaceColor[$code];
			}
		}

		return Color::getColor($code);
	}

	public function clearCache(int $id): void
	{
		ServiceLocator::getInstance()
			->get(UserCacheRegistry::class)
			?->getUserDataManager()
			->clear(entityId: $id)
		;
	}
}
