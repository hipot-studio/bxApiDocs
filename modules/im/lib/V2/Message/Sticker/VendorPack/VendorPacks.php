<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\VendorPack;

use Bitrix\Im\V2\Message\Sticker\PackCollection;
use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\Recent\RecentCollection;
use Bitrix\Im\V2\Message\Sticker\StickerCollection;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\Message\Sticker\StickerItem;
use Bitrix\Im\V2\Message\Sticker\StickerPacks;
use Bitrix\Im\V2\Message\Sticker\StickerType;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\Localization\Loc;

class VendorPacks implements StickerPacks
{
	private static self $instance;

	private static ?PackCollection $vendorPacks = null;

	private function __construct()
	{
		if (!isset(self::$vendorPacks))
		{
			$this->fill();
		}
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
			$lastId = 0;
		}

		$lastId = $lastId ?? 0;
		$lastPack = null;

		foreach (self::$vendorPacks as $pack)
		{
			if ($pack->id > $lastId && count($packCollection) < $limit)
			{
				$packCollection->append($pack);
				$lastPack = $pack;
			}
		}

		if (isset($lastPack) && $lastPack->id === $this->getLastPackId())
		{
			$packCollection->setHasNextPage(false);
		}

		return $packCollection;
	}

	protected function getLastPackId(): int
	{
		$packId = 0;

		foreach (self::$vendorPacks as $pack)
		{
			$packId = $pack->id;
		}

		return $packId;
	}

	public function getStickerById(int $stickerId, int $packId): ?StickerItem
	{
		$packName = VendorPackName::getById($packId);
		if ($this->packExists($packName))
		{
			$stickers = self::$vendorPacks->offsetGet($packName->value)->stickers;

			if (isset($stickers[$stickerId]))
			{
				return $stickers[$stickerId];
			}
		}

		return null;
	}

	public function getPackById(int $packId): ?PackItem
	{
		$packName = VendorPackName::getById($packId);
		if ($this->packExists($packName))
		{
			return self::$vendorPacks->offsetGet($packName->value);
		}

		return null;
	}

	public function isPackAdded(int $packId): bool
	{
		return true;
	}

	public function getStickersByRecent(RecentCollection $recentCollection): StickerCollection
	{
		$stickerCollection = new StickerCollection();

		foreach ($recentCollection as $recentItem)
		{
			if ($recentItem->packType !== PackType::Vendor)
			{
				continue;
			}

			$sticker = $this->getStickerById($recentItem->id, $recentItem->packId);
			if ($sticker !== null)
			{
				$stickerCollection->append($sticker);
			}
		}

		return $stickerCollection;
	}

	public function addPack(array $fileUuidMap, ?string $packName): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function linkPack(int $packId): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function addStickers(array $fileUuidMap, int $packId, bool $sendPush = true): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function deletePack(int $packId): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function unlinkPack(int $packId): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function renamePack(int $packId, string $name): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function deleteStickers(array $stickerIds, int $packId): Result
	{
		return (new Result())->addError(new StickerError(StickerError::ACCESS_DENIED));
	}

	public function getType(): PackType
	{
		return PackType::Vendor;
	}

	private function getStickersByPackName(VendorPackName $packName, int $packId): StickerCollection
	{
		$dir = $this->getImageDir($packName);
		$publicDir = $this->getImagePublicDir($packName);

		$stickerCollection = (new StickerCollection());

		$files = match ($packName)
		{
			VendorPackName::BitrixVibe => VendorConfig::getBitrixVibe(),
			VendorPackName::Zefir => VendorConfig::getZefir(),
			VendorPackName::ArkashaAndCat => VendorConfig::getArkashaAndCat(),
			VendorPackName::BitrixReactions => VendorConfig::getBitrixReactions(),
			VendorPackName::Airy => VendorConfig::getAiry(),
			VendorPackName::BittyBob => VendorConfig::getBittyBob(),
			VendorPackName::OfficeRoutine => VendorConfig::getOfficeRoutine(),
			VendorPackName::Smileys => VendorConfig::getSmileys(),
			VendorPackName::Hands => VendorConfig::getHands(),
			VendorPackName::WorkDay => VendorConfig::getWorkDay(),
			VendorPackName::Animals => VendorConfig::getAnimals(),
			VendorPackName::Celebration => VendorConfig::getCelebration(),
		};

		foreach ($files as $id => $file)
		{
			$uri = $publicDir . $file['name'];
			$sticker = (new StickerItem($id, $uri, StickerType::Image, $file['width'], $file['height'], $packId, PackType::Vendor));
			$stickerCollection->offsetSet($id, $sticker);
		}

		return $stickerCollection;
	}

	private function fill(): void
	{
		self::$vendorPacks = (new PackCollection());
		$license = Application::getInstance()->getLicense();


		if ($license->isCis())
		{
			self::$vendorPacks->offsetSet(
				VendorPackName::BitrixVibe->value,
				$this->getPackByName(VendorPackName::BitrixVibe)
			);
			self::$vendorPacks->offsetSet(
				VendorPackName::Zefir->value,
				$this->getPackByName(VendorPackName::Zefir)
			);
			self::$vendorPacks->offsetSet(
				VendorPackName::ArkashaAndCat->value,
				$this->getPackByName(VendorPackName::ArkashaAndCat)
			);
			self::$vendorPacks->offsetSet(
				VendorPackName::OfficeRoutine->value,
				$this->getPackByName(VendorPackName::OfficeRoutine)
			);
		}
		else
		{
			self::$vendorPacks->offsetSet(
				VendorPackName::BitrixReactions->value,
				$this->getPackByName(VendorPackName::BitrixReactions)
			);
			self::$vendorPacks->offsetSet(
				VendorPackName::Airy->value,
				$this->getPackByName(VendorPackName::Airy)
			);
			self::$vendorPacks->offsetSet(
				VendorPackName::BittyBob->value,
				$this->getPackByName(VendorPackName::BittyBob)
			);
		}

		self::$vendorPacks->offsetSet(
			VendorPackName::Smileys->value,
			$this->getPackByName(VendorPackName::Smileys)
		);
		self::$vendorPacks->offsetSet(
			VendorPackName::Hands->value,
			$this->getPackByName(VendorPackName::Hands)
		);
		self::$vendorPacks->offsetSet(
			VendorPackName::WorkDay->value,
			$this->getPackByName(VendorPackName::WorkDay)
		);
		self::$vendorPacks->offsetSet(
			VendorPackName::Animals->value,
			$this->getPackByName(VendorPackName::Animals)
		);
		self::$vendorPacks->offsetSet(
			VendorPackName::Celebration->value,
			$this->getPackByName(VendorPackName::Celebration)
		);
	}

	private function packExists(?VendorPackName $packName): bool
	{
		return $packName !== null && self::$vendorPacks->offsetExists($packName->value);
	}

	private function getPackName(VendorPackName $packName): string
	{
		return match ($packName)
		{
			VendorPackName::BitrixVibe => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_BITRIX_VIBE') ?? '',
			VendorPackName::Zefir => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_ZEFIR') ?? '',
			VendorPackName::ArkashaAndCat => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_ARKASHA_AND_CAT') ?? '',
			VendorPackName::BitrixReactions => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_BITRIX_REACTIONS') ?? '',
			VendorPackName::Airy => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_AIRY') ?? '',
			VendorPackName::BittyBob => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_BITTY_BOB') ?? '',
			VendorPackName::OfficeRoutine => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_OFFICE_ROUTINE') ?? '',
			VendorPackName::Smileys => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_SMILEYS') ?? '',
			VendorPackName::Hands => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_HANDS') ?? '',
			VendorPackName::WorkDay => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_WORK_DAY') ?? '',
			VendorPackName::Animals => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_ANIMALS') ?? '',
			VendorPackName::Celebration => Loc::getMessage('IM_MESSAGE_STICKER_VENDOR_CELEBRATION') ?? '',
		};
	}

	private function getPackByName(VendorPackName $packName): PackItem
	{
		$stickerCollection = $this::getStickersByPackName($packName, $packName->getId());

		return new PackItem(
			$packName->getId(),
			$this->getPackName($packName),
			PackType::Vendor,
			$stickerCollection
		);
	}

	private function getImageDir(VendorPackName $packName): string
	{
		return Application::getDocumentRoot() . "/bitrix/modules/im/install/images/stickers/{$packName->value}/";
	}

	private function getImagePublicDir(VendorPackName $packName): string
	{
		return "/bitrix/images/im/stickers/{$packName->value}/";
	}
}
