<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\VendorPack;

use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\RecentItem;
use Bitrix\Im\V2\Message\Sticker\StickerItem;
use Bitrix\Im\V2\Message\Sticker\StickerType;
use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\Localization\Loc;

class VendorPacks
{
	private static self $instance;

	/**
	 * @var PackItem[] $vendorPacks
	 */
	private static array $vendorPacks = [];

	private function __construct()
	{
		if (empty(self::$vendorPacks))
		{
			$this->fill();
		}
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function getList(int $limit, ?int $lastId = null, ?string $lastType = null): array
	{
		$packs = [];

		if ($lastId !== null && $lastType !== PackType::Vendor->value)
		{
			return [];
		}

		if ($lastId === null)
		{
			foreach (array_slice(self::$vendorPacks, 0, $limit) as $pack)
			{
				$packs[] = $pack->toRestFormat();
			}
		}
		else
		{
			foreach (self::$vendorPacks as $pack)
			{
				if ($pack->id > $lastId && count($packs) < $limit)
				{
					$packs[] = $pack->toRestFormat();
				}
			}
		}

		return $packs;
	}

	public function getPackByName(VendorPackName $packName): PackItem
	{
		$stickers = $this->getStickersByPackName($packName);

		return new PackItem(
			$packName->getId(),
			$this->getPackName($packName),
			PackType::Vendor,
			$stickers
		);
	}

	public function getStickerById(int $stickerId, int $packId): ?array
	{
		$packName = VendorPackName::getById($packId);
		if ($this->packExists($packName))
		{
			$stickers = self::$vendorPacks[$packName->value]->stickers;

			if (isset($stickers[$stickerId]))
			{
				return array_merge(
					$stickers[$stickerId],
					['packId' => $packId, 'packType' => PackType::Vendor->value]
				);
			}
		}

		return null;
	}

	public function getPackById(int $packId): ?PackItem
	{
		$packName = VendorPackName::getById($packId);
		if ($this->packExists($packName))
		{
			return self::$vendorPacks[$packName->value];
		}

		return null;
	}

	public function getStickersByRecent(array $recentItems): array
	{
		/** @var array<RecentItem> $recentItems */
		$stickers = [];

		foreach ($recentItems as $recentItem)
		{
			if ($recentItem->packType !== PackType::Vendor->value)
			{
				continue;
			}

			$sticker = $this->getStickerById($recentItem->id, $recentItem->packId);
			if ($sticker !== null)
			{
				$stickers[] = $sticker;
			}
		}

		return $stickers;
	}

	private function getStickersByPackName(VendorPackName $packName): array
	{
		$dir = $this->getImageDir($packName);
		$publicDir = $this->getImagePublicDir($packName);

		$stickers = [];

		$files = match ($packName)
		{
			VendorPackName::BitrixVibe => VendorConfig::getBitrixVibe(),
			VendorPackName::Zefir => VendorConfig::getZefir(),
			VendorPackName::ArkashaAndCat => VendorConfig::getArkashaAndCat(),
			VendorPackName::BitrixReactions => VendorConfig::getBitrixReactions(),
			VendorPackName::Airy => VendorConfig::getAiry(),
			VendorPackName::BittyBob => VendorConfig::getBittyBob(),
		};

		foreach ($files as $id => $file)
		{
			if (File::isFileExists($dir . $file['name']))
			{
				$uri = $publicDir . $file['name'];
				$stickerItem = new StickerItem($id, $uri, StickerType::Image, $file['width'], $file['height']);
				$stickers[$id] = $stickerItem->toRestFormat();
			}
		}

		return $stickers;
	}

	private function fill(): void
	{
		$license = Application::getInstance()->getLicense();


		if ($license->isCis())
		{
			self::$vendorPacks[VendorPackName::BitrixVibe->value] = $this->getPackByName(VendorPackName::BitrixVibe);
			self::$vendorPacks[VendorPackName::Zefir->value] = $this->getPackByName(VendorPackName::Zefir);
			self::$vendorPacks[VendorPackName::ArkashaAndCat->value] = $this->getPackByName(VendorPackName::ArkashaAndCat);
		}
		else
		{
			self::$vendorPacks[VendorPackName::BitrixReactions->value] = $this->getPackByName(VendorPackName::BitrixReactions);
			self::$vendorPacks[VendorPackName::Airy->value] = $this->getPackByName(VendorPackName::Airy);
			self::$vendorPacks[VendorPackName::BittyBob->value] = $this->getPackByName(VendorPackName::BittyBob);
		}
	}

	private function packExists(?VendorPackName $packName): bool
	{
		return $packName !== null && isset(self::$vendorPacks[$packName->value]);
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
		};
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
