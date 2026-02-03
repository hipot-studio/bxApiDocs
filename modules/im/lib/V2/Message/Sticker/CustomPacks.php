<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\Model\StickerPackTable;
use Bitrix\Im\Model\StickerTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message\Sticker\CustomPacks\UserPack;
use Bitrix\Im\V2\Message\Sticker\Recent\RecentCollection;
use Bitrix\Im\V2\Message\Sticker\CustomPacks\PackNameManager;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerAdd;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerDelete;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerPackAdd;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerPackDelete;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerPackLink;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerPackRename;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerPackUnlink;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\DateTime;

class CustomPacks implements StickerPacks
{
	use ContextCustomer;

	protected const MAX_STICKERS = 50;
	protected const MAX_PACKS = 50;

	protected const CACHE_ID = 'sticker_pack_';
	protected const CACHE_DIR = '/bx/imc/sticker/pack/v1/';
	protected const CACHE_TTL = 18144000;

	protected static self $instance;
	protected static array $packs = [];
	protected PackNameManager $packNameManager;

	private function __construct()
	{
		$this->packNameManager = new PackNameManager();
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function getList(int $limit, ?int $lastId = null, ?PackType $lastType = null): PackCollection
	{
		$packCollection = new PackCollection();

		if ($lastId !== null && $lastType !== $this->getType())
		{
			return $packCollection;
		}

		$packIds = UserPack::getInstance()->getList($limit, $lastId);

		foreach ($packIds as $packId)
		{
			$pack = $this->getPackById($packId);
			$packCollection->append($pack);
		}

		return $packCollection->setHasNextPage(true);
	}

	public function getStickerById(int $stickerId, int $packId): ?StickerItem
	{
		$pack = $this->getPackById($packId);

		return $pack?->stickers?->offsetGet($stickerId);
	}

	public function getPackById(int $packId): ?PackItem
	{
		if (isset($this->packs[$packId]))
		{
			return $this->packs[$packId];
		}

		$cache = $this->getCache($packId);
		$packData = $cache->getVars();

		if ($packData !== false)
		{
			self::$packs[$packId] = $this->initByArray($packData);

			return self::$packs[$packId];
		}

		$packData = $this->getPackFromDB($packId);
		$cache->startDataCache();
		$cache->endDataCache($packData);

		self::$packs[$packId] = $this->initByArray($packData);

		return self::$packs[$packId];
	}

	public function isPackAdded(int $packId): bool
	{
		return UserPack::getInstance()->isPackAdded($packId);
	}

	public function addPack(array $fileUuidMap, ?string $packName): Result
	{
		$result = $this->checkUserPackLimit();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$dateCreate = new DateTime();

		$packData = [
			'NAME' => $this->packNameManager->getName($packName),
			'AUTHOR_ID' => $this->getContext()->getUserId(),
			'TYPE' => $this->getType()->value,
			'DATE_CREATE' => $dateCreate,
		];

		$addResult = StickerPackTable::add($packData);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		$packId = $addResult->getId();
		if (!is_int($packId) || $packId <= 0)
		{
			$result->addError(new StickerError(StickerError::PACK_CREATION_ERROR));
		}

		UserPack::getInstance()->add(
			(int)$addResult->getId(),
			$this->getContext()->getUserId(),
			$dateCreate
		);

		if (!empty($fileUuidMap))
		{
			$result = $this->addStickers($fileUuidMap, $packId, false);
		}

		$pack = $this->getPackById($packId);
		if ($pack !== null)
		{
			(new StickerPackAdd($pack, $fileUuidMap))->send();
		}

		return $result->setResult($pack);
	}

	public function linkPack(int $packId): Result
	{
		$result = $this->checkUserPackLimit();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$pack = $this->getPackById($packId);
		if ($pack === null)
		{
			return $result->addError(new StickerError(StickerError::PACK_NOT_FOUND));
		}

		if (UserPack::getInstance()->getUserPacks()->offsetGet($packId))
		{
			return $result->addError(new StickerError(StickerError::LINK_EXISTS));
		}

		$addResult = UserPack::getInstance()->add(
			$packId,
			$this->getContext()->getUserId(),
			new DateTime()
		);

		if ($addResult->isSuccess())
		{
			(new StickerPackLink($pack))->send();
		}

		return $result->setResult($pack);
	}

	public function addStickers(array $fileUuidMap, int $packId, bool $sendPush = true): Result
	{
		$result = new Result();

		$pack = $this->getPackById($packId);
		if ($pack === null)
		{
			return $result->addError(new StickerError(StickerError::PACK_NOT_FOUND));
		}
		if ($pack->authorId !== $this->getContext()->getUserId())
		{
			return $result->addError(new StickerError(StickerError::ACCESS_DENIED));
		}
		if ($pack->stickers->count() >= self::MAX_STICKERS)
		{
			return $result->addError(new StickerError(StickerError::MAX_STICKERS_ERROR));
		}

		$newStickers = [];

		foreach (array_keys($fileUuidMap) as $fileId)
		{
			$newStickers[] = [
				'PACK_ID' => $packId,
				'FILE_ID' => $fileId,
				'TYPE' => StickerType::Image->value,
			];
		}

		if ($pack->stickers->count() + count($newStickers) > self::MAX_STICKERS)
		{
			$overflowCount = $pack->stickers->count() + count($newStickers) - self::MAX_STICKERS;
			$newStickers = array_slice($newStickers, 0, count($newStickers) - $overflowCount);
		}

		StickerTable::multiplyInsertWithoutDuplicate($newStickers);
		$this->cleanCache($packId);

		if ($sendPush)
		{
			$pack = $this->getPackById($packId);
			if ($pack !== null)
			{
				(new StickerAdd($pack))->send();
			}

			$result->setResult($pack);
		}

		return $result;
	}

	public function deleteStickers(array $stickerIds, int $packId): Result
	{
		$result = new Result();
		$stickerIds = array_map('intval', $stickerIds);

		$pack = $this->getPackById($packId);
		if ($pack === null)
		{
			return $result->addError(new StickerError(StickerError::PACK_NOT_FOUND));
		}
		if ($pack->authorId !== $this->getContext()->getUserId())
		{
			return $result->addError(new StickerError(StickerError::ACCESS_DENIED));
		}

		if ($this->shouldDeletePack($pack, $stickerIds))
		{
			return $this->deletePack($packId);
		}

		$deletedFileIds = [];
		$deletedStickerIds = [];
		foreach ($stickerIds as $stickerId)
		{
			$stickerId = (int)$stickerId;
			$sticker = $pack->stickers->offsetGet($stickerId);

			if (isset($sticker))
			{
				$deletedFileIds[] = (int)$sticker->fileId;
				$deletedStickerIds[] = $stickerId;
			}
		}

		StickerTable::deleteBatch(['=ID' => $deletedStickerIds]);

		$this->cleanCache($packId);
		(new StickerDelete($packId, $pack->type, $this->getUsersWithPack($packId), $deletedStickerIds))->send();

		$this->deleteFiles($deletedFileIds);

		return $result;
	}

	protected function shouldDeletePack(PackItem $pack, array $deletedStickerIds): bool
	{
		$stickerIds = $pack->stickers->getIds();

		return empty(array_diff($stickerIds, $deletedStickerIds));
	}

	public function deletePack(int $packId): Result
	{
		$result = new Result();

		$pack = $this->getPackById($packId);
		if ($pack === null)
		{
			return $result->addError(new StickerError(StickerError::PACK_NOT_FOUND));
		}
		if ($pack->authorId !== $this->getContext()->getUserId())
		{
			return $result->addError(new StickerError(StickerError::ACCESS_DENIED));
		}

		$userIds = $this->getUsersWithPack($packId);

		UserPack::getInstance()->deleteByFilter(['=PACK_ID' => $packId], $userIds);
		StickerPackTable::delete($packId);
		StickerTable::deleteBatch(['=PACK_ID' => $packId]);

		$this->cleanCache($packId);
		(new StickerPackDelete($packId, $pack->type, $userIds))->send();

		$this->deleteFiles($pack->stickers->getFileIds());

		return $result;
	}

	public function unlinkPack(int $packId): Result
	{
		$result = new Result();

		$pack = $this->getPackById($packId);
		if ($pack === null)
		{
			return $result->addError(new StickerError(StickerError::PACK_NOT_FOUND));
		}
		if ($pack->authorId === $this->getContext()->getUserId())
		{
			return $result->addError(new StickerError(StickerError::ACCESS_DENIED));
		}

		$filter = ['=USER_ID' => $this->getContext()->getUserId(), '=PACK_ID' => $packId];
		UserPack::getInstance()->deleteByFilter($filter, [$this->getContext()->getUserId()]);
		(new StickerPackUnlink($packId, $pack->type))->send();

		return $result;
	}

	public function renamePack(int $packId, string $name): Result
	{
		$result = new Result();

		$pack = $this->getPackById($packId);
		if ($pack === null)
		{
			return $result->addError(new StickerError(StickerError::PACK_NOT_FOUND));
		}
		if ($pack->authorId !== $this->getContext()->getUserId())
		{
			return $result->addError(new StickerError(StickerError::ACCESS_DENIED));
		}
		if (empty($name))
		{
			return $result->addError(new StickerError(StickerError::EMPTY_PACK_NAME));
		}

		StickerPackTable::update($packId, ['NAME' => $this->packNameManager->getName($name)]);

		$this->cleanCache($packId);
		(new StickerPackRename($packId, $pack->type, $name))->send();

		return $result;
	}

	public function getStickersByRecent(RecentCollection $recentCollection): StickerCollection
	{
		$stickerCollection = new StickerCollection();

		foreach ($recentCollection as $recentItem)
		{
			if ($recentItem->packType === $this->getType())
			{
				$pack = $this->getPackById($recentItem->packId);
				$sticker = $pack?->stickers?->offsetGet($recentItem->id);
				if (isset($sticker))
				{
					$stickerCollection->append($sticker);
				}
			}
		}

		return $stickerCollection;
	}

	protected function getUsersWithPack($packId): array
	{
		return UserPack::getInstance()->getUsersWithPack($packId);
	}

	public function getType(): PackType
	{
		return PackType::Custom;
	}

	protected function initByArray(?array $packData): ?PackItem
	{
		if (empty($packData))
		{
			return null;
		}

		if (PackType::tryFrom((string)$packData['TYPE']) === null)
		{
			return null;
		}

		$stickerCollection = new StickerCollection();

		foreach ($packData['STICKERS'] as $stickerData)
		{
			$sticker = new StickerItem(
				(int)$stickerData['ID'],
				$stickerData['URI'] ?? null,
				StickerType::tryFrom($stickerData['TYPE']) ?? StickerType::Image,
				$stickerData['WIDTH'] ?? 0,
				$stickerData['HEIGHT'] ?? 0,
				(int)$packData['ID'],
				PackType::tryFrom((string)$packData['TYPE']),
				(int)$stickerData['FILE_ID'],
			);

			$stickerCollection->offsetSet($sticker->id, $sticker);
		}

		return new PackItem(
			(int)$packData['ID'],
			$this->packNameManager->decodeName((string)$packData['NAME']),
			PackType::tryFrom((string)$packData['TYPE']),
			$stickerCollection,
			(int)$packData['AUTHOR_ID']
		);
	}

	protected function getPackFromDB(int $packId): ?array
	{
		$pack = StickerPackTable::query()
			->setSelect(['*'])
			->where('ID', $packId)
			->fetch()
		;

		if (!$pack)
		{
			return null;
		}

		$stickers = StickerTable::query()
			->setSelect(['ID', 'FILE_ID', 'TYPE'])
			->where('PACK_ID', $packId)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		$stickers = $this->fillFileData($stickers);
		$pack['STICKERS'] = $stickers;

		return $pack;
	}

	protected function fillFileData(array $stickersData): array
	{
		$fileIds = [];
		foreach ($stickersData as $sticker)
		{
			$fileIds[] = (int)$sticker['FILE_ID'];
		}

		$resizedFiles = [];
		foreach ($fileIds as $fileId)
		{
			$file = \CFile::ResizeImageGet(
				$fileId,
				['width' => 512, 'height' => 512],
				BX_RESIZE_IMAGE_PROPORTIONAL,
				true,
				false,
				true
			);

			$resizedFiles[$fileId] = $file ?: [];
		}

		foreach ($stickersData as $key => $sticker)
		{
			$stickersData[$key]['URI'] = $resizedFiles[(int)$sticker['FILE_ID']]['src'] ?? null;
			$stickersData[$key]['WIDTH'] = $resizedFiles[(int)$sticker['FILE_ID']]['width'] ?? 0;
			$stickersData[$key]['HEIGHT'] = $resizedFiles[(int)$sticker['FILE_ID']]['height'] ?? 0;
		}

		return $stickersData;
	}

	protected function checkUserPackLimit(): Result
	{
		$result = new Result();
		$userPackCollection = UserPack::getInstance()->getUserPacks();

		if ($userPackCollection->count() >= self::MAX_PACKS)
		{
			$result->addError(new StickerError(StickerError::MAX_PACKS_ERROR));
		}

		return $result;
	}

	protected function getCache(int $packId): Cache
	{
		$cache = Application::getInstance()->getCache();

		$cacheTTL = self::CACHE_TTL;
		$cacheId = self::CACHE_ID . $packId;
		$cacheDir = $this->getCacheDir($packId);

		$cache->initCache($cacheTTL, $cacheId, $cacheDir);

		return $cache;
	}

	public function cleanCache(int $packId): void
	{
		Application::getInstance()->getCache()->cleanDir($this->getCacheDir($packId));
		unset(self::$packs[$packId]);
	}

	protected function getCacheDir(int $packId): string
	{
		$cacheSubDir = $packId % 100;

		return self::CACHE_DIR . "{$cacheSubDir}/{$packId}";
	}

	protected function deleteFiles(array $fileIds): void
	{
		foreach ($fileIds as $fileId)
		{
			\CFile::Delete($fileId);
		}
	}
}
