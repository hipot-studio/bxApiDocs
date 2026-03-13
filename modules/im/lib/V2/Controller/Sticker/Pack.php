<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Sticker;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\ExternalUserTypeFilter;
use Bitrix\Im\V2\Integration\UI\Sticker\PendingFileCollection;
use Bitrix\Im\V2\Message\Sticker\CustomPacks\StickerUuid;
use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\Recent\RecentSticker;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\Message\Sticker\StickerService;
use Bitrix\Im\V2\Permission\GlobalAction;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

class Pack extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				PackType::class,
				'packType',
				function($className, string $type) {
					return $this->getPackType($type);
				}
			),
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
				new ExternalUserTypeFilter(),
			]
		);
	}

	public function configureActions()
	{
		return [
			'add' => [
				'+prefilters' => [
					new CheckActionAccess(GlobalAction::CreateStickerPack),
				],
			],
		];
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.load
	 */
	public function loadAction(int $limit): ?array
	{
		$packCollection = (new StickerService())->getList($limit);
		$recent = (new RecentSticker())->get();
		$rest = (new RestAdapter($packCollection, $recent))->toRestFormat();
		$rest['hasNextPage'] = $packCollection->hasNextPage();


		return $rest;
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.tail
	 */
	public function tailAction(int $limit, int $id, PackType $packType): ?array
	{
		$packCollection = (new StickerService())->getList($limit, $id, $packType);
		$rest = (new RestAdapter($packCollection))->toRestFormat();
		$rest['hasNextPage'] = $packCollection->hasNextPage();

		return $rest;
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.get
	 */
	public function getAction(int $id, PackType $packType): ?array
	{
		$pack = (new StickerService())->getPackById($id, $packType);
		if (!isset($pack))
		{
			$this->addError(new StickerError(StickerError::PACK_NOT_AVAILABLE));

			return null;
		}

		return (new RestAdapter($pack))->toRestFormat();
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.add
	 */
	public function addAction(PendingFileCollection $pendingFileCollection, PackType $packType, ?string $name): ?array
	{
		$result = (new StickerService())->addPack($pendingFileCollection, $packType, $name);
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
	 * @restMethod im.v2.Sticker.Pack.link
	 */
	public function linkAction(int $id, PackType $packType): ?array
	{
		$result = (new StickerService())->linkPack($id, $packType);
		if (!$result->isSuccess() || !$result->hasResult())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return (new RestAdapter($result->getResult()))->toRestFormat();
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.delete
	 */
	public function deleteAction(int $id, PackType $packType): ?array
	{
		$result = (new StickerService())->deletePack($id, $packType);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.unlink
	 */
	public function unlinkAction(int $id, PackType $packType): ?array
	{
		$result = (new StickerService())->unlinkPack($id, $packType);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod im.v2.Sticker.Pack.rename
	 */
	public function renameAction(int $id, PackType $packType, string $name): ?array
	{
		$result = (new StickerService())->renamePack($id, $packType, trim($name));
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}
}
