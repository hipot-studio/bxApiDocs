<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Sticker;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message\Sticker\RecentSticker;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\Message\Sticker\StickerService;

class Pack extends BaseController
{
	/**
	 * @restMethod im.v2.Sticker.Pack.load
	 */
	public function loadAction(int $limit): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		return [
			'recentStickers' => (new RecentSticker())->get(),
			'packs' => (new StickerService())->getList($limit),
		];
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.tail
	 */
	public function tailAction(int $limit, int $lastPackId, string $lastPackType): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		return ['packs' => (new StickerService())->getList($limit, $lastPackId, $lastPackType)];
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.get
	 */
	public function getAction(int $packId, string $packType): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		$pack = (new StickerService())->getPackById($packId, $packType);
		if (!isset($pack))
		{
			$this->addError(new StickerError(StickerError::PACK_NOT_AVAILABLE));

			return null;
		}

		return ['pack' => $pack];
	}
}
