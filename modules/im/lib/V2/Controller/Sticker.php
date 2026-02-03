<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\Sticker\StickerUploader;
use Bitrix\Im\V2\Message\Sticker\CustomPacks\StickerUuid;
use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\Message\Sticker\StickerService;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\Uploader;

class Sticker extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				PendingFileCollection::class,
				'pendingFiles',
				function ($className, $uuids) {
					Loader::requireModule('ui');
					$pendingFileCollection = (new Uploader(new StickerUploader()))->getPendingFiles($uuids);
					$pendingFileCollection->makePersistent();

					return $pendingFileCollection;
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod im.v2.Sticker.add
	 */
	public function addAction(PendingFileCollection $pendingFiles, int $packId, PackType $packType): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		$fileUuidMap = StickerUuid::getFileMap($pendingFiles);
		if (empty($fileUuidMap))
		{
			$this->addError(new StickerError(StickerError::EMPTY_STICKERS));

			return null;
		}

		$result = (new StickerService())->addStickers($fileUuidMap, $packId, $packType);
		$pack = $result->getResult();

		if (!$result->isSuccess() || !$pack instanceof PackItem)
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$stickerUuid = new StickerUuid($fileUuidMap, $pack->stickers);

		return (new RestAdapter($pack, $stickerUuid))->toRestFormat();
	}

	/**
	 * @restMethod im.v2.Sticker.delete
	 */
	public function deleteAction(array $ids, int $packId, PackType $packType): ?array
	{
		if (!Features::isStickersAvailable())
		{
			$this->addError(new StickerError(StickerError::STICKERS_NOT_AVAILABLE));

			return null;
		}

		$result = (new StickerService())->deleteStickers($ids, $packId, $packType);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
