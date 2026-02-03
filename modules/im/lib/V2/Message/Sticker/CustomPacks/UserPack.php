<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\CustomPacks;

use Bitrix\Im\Model\StickerUserPackTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Type\DateTime;

class UserPack
{
	use ContextCustomer;

	protected const CACHE_ID = 'sticker_pack_user';
	protected const CACHE_DIR = '/bx/imc/sticker/userpack/v1/';
	protected const CACHE_TTL = 18144000;

	protected static self $instance;

	/** @var UserPackCollection[] $userPacks */
	protected static array $userPacks = [];

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function getUserPacks(): UserPackCollection
	{
		$userId = $this->getContext()->getUserId();
		self::$userPacks[$userId] ??= $this->getUserPacksInternal($userId);

		return self::$userPacks[$userId];
	}

	public function getList(int $limit, ?int $lastId): array
	{
		$packIds = [];

		$userPackCollection = $this->getUserPacks();
		foreach ($userPackCollection as $userPack)
		{
			if (count($packIds) >= $limit)
			{
				break;
			}

			if ($lastId !== null && $userPack->id > $lastId)
			{
				continue;
			}

			$packIds[$userPack->packId] = $userPack->packId;
		}

		return $packIds;
	}

	public function isPackAdded(int $packId): bool
	{
		$userPackCollection = $this->getUserPacks();

		return $userPackCollection->offsetGet($packId) instanceof UserPackItem;
	}

	public function add(int $packId, int $userId, DateTime $dateCreate): AddResult
	{
		$addResult = StickerUserPackTable::add([
			'PACK_ID' => $packId,
			'USER_ID' => $userId,
			'DATE_CREATE' => $dateCreate,
		]);

		$this->cleanCache($userId);

		return $addResult;
	}

	public function deleteByFilter(array $filter, array $userIds): void
	{
		StickerUserPackTable::deleteBatch($filter);

		foreach ($userIds as $userId)
		{
			$this->cleanCache($userId);
		}
	}

	public function getUsersWithPack($packId): array
	{
		$users = [];

		$query = StickerUserPackTable::query()
			->setSelect(['USER_ID'])
			->where('PACK_ID', $packId)
			->fetchAll();

		foreach ($query as $row)
		{
			$users[(int)$row['USER_ID']] = (int)$row['USER_ID'];
		}

		return $users;
	}

	protected function getUserPacksInternal(int $userId): UserPackCollection
	{
		if ($userId <= 0)
		{
			return new UserPackCollection();
		}

		$cache = $this->getCache($userId);
		$userPacksData = $cache->getVars();

		if ($userPacksData !== false)
		{
			return $this->initByArray($userPacksData);
		}

		$userPacksData = $this->getUserPacksFromDB($userId);
		$cache->startDataCache();
		$cache->endDataCache($userPacksData);

		return $this->initByArray($userPacksData);
	}

	protected function getUserPacksFromDB(int $userId): array
	{
		return StickerUserPackTable::query()
			->setSelect(['ID', 'PACK_ID', 'USER_ID'])
			->where('USER_ID', $userId)
			->setOrder(['ID' => 'DESC'])
			->fetchAll()
		;
	}

	protected function initByArray(array $userPackData): UserPackCollection
	{
		$userPackCollection = new UserPackCollection();

		foreach ($userPackData as $userPack)
		{
			$userPackItem = new UserPackItem(
				(int)$userPack['ID'],
				(int)$userPack['USER_ID'],
				(int)$userPack['PACK_ID']
			);

			$userPackCollection->offsetSet($userPack['PACK_ID'], $userPackItem);
		}

		return $userPackCollection;
	}

	protected function getCache(int $userId): Cache
	{
		$cache = Application::getInstance()->getCache();

		$cacheTTL = self::CACHE_TTL;
		$cacheId = self::CACHE_ID . $userId;
		$cacheDir = $this->getCacheDir($userId);

		$cache->initCache($cacheTTL, $cacheId, $cacheDir);

		return $cache;
	}

	public function cleanCache(int $userId): void
	{
		Application::getInstance()->getCache()->cleanDir($this->getCacheDir($userId));
		unset(self::$userPacks[$userId]);
	}

	protected function getCacheDir(int $userId): string
	{
		$cacheSubDir = $userId % 100;

		return self::CACHE_DIR . "{$cacheSubDir}/{$userId}";
	}
}
