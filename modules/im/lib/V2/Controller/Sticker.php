<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\ExternalUserTypeFilter;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Integration\UI\Sticker\PendingFileCollection;
use Bitrix\Im\V2\Message\Sticker\CustomPacks\StickerUuid;
use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\Message\Sticker\StickerService;
use Bitrix\Im\V2\Permission\GlobalAction;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

class Sticker extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				PendingFileCollection::class,
				'pendingFileCollection',
				function ($className, $uuids) {
					return (new PendingFileCollection($uuids));
				}
			),
		], parent::getAutoWiredParameters());
	}

	protected function getDefaultPreFilters()
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ExternalUserTypeFilter([UserGuest::AUTH_ID]),
			]
		);
	}

	public function configureActions()
	{
		return [
			'add' => [
				'+prefilters' => [
					new CheckActionAccess(GlobalAction::ChangeStickerPack),
				],
			],
		];
	}

	/**
	 * @restMethod im.v2.Sticker.add
	 */
	public function addAction(PendingFileCollection $pendingFileCollection, int $packId, PackType $packType): ?array
	{
		if (empty($pendingFileCollection->getFileMap()))
		{
			$this->addError(new StickerError(StickerError::EMPTY_STICKERS));

			return null;
		}

		$result = (new StickerService())->addStickers($pendingFileCollection, $packId, $packType);
		$pack = $result->getResult();

		if (!$result->isSuccess() || !$pack instanceof PackItem)
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$stickerUuid = new StickerUuid($pendingFileCollection->getFileMap(), $pack->stickers);

		return (new RestAdapter($pack, $stickerUuid))->toRestFormat();
	}

	/**
	 * @restMethod im.v2.Sticker.delete
	 */
	public function deleteAction(array $ids, int $packId, PackType $packType): ?array
	{
		$result = (new StickerService())->deleteStickers($ids, $packId, $packType);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
