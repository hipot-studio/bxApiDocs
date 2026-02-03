<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Sticker;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\Recent\RecentSticker;
use Bitrix\Im\V2\Message\Sticker\StickerError;

class Recent extends BaseController
{
	/**
	 * @restMethod im.v2.Sticker.Recent.delete
	 */
	public function deleteAction(int $id, int $packId, PackType $packType): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		(new RecentSticker())->delete($id, $packId, $packType);

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Sticker.Recent.deleteAll
	 */
	public function deleteAllAction(): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		(new RecentSticker())->deleteAll();

		return ['result' => true];
	}
}
