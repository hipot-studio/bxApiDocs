<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\Recent;

use Bitrix\Im\Model\StickerRecentTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerRecentDelete;
use Bitrix\Im\V2\Pull\Event\Sticker\StickerRecentDeleteAll;
use Bitrix\Main\Type\DateTime;

class RecentSticker
{
	use ContextCustomer;

	private const DELETE_LIMIT = 30;

	public function get(int $limit = 12): RecentCollection
	{
		$recentItems = new RecentCollection();
		$result = StickerRecentTable::query()
			->setSelect(['*'])
			->where('USER_ID', $this->getContext()->getUserId())
			->setOrder(['DATE_CREATE' => 'DESC'])
			->setLimit($limit + self::DELETE_LIMIT)
			->fetchAll()
		;

		$this->clearOldRecentStickers($result, $limit);
		$result = array_slice($result, 0, $limit);


		foreach ($result as $sticker)
		{
			$packType = PackType::tryFrom((string)$sticker['PACK_TYPE']);
			if ($packType === null)
			{
				continue;
			}

			$recentItem = new RecentItem(
				(int)$sticker['STICKER_ID'],
				(int)$sticker['PACK_ID'],
				$packType,
				$sticker['DATE_CREATE']
			);

			$recentItems->offsetSet($recentItem->getUniqueKey(), $recentItem);
		}

		return $recentItems;
	}

	public function add(Message $message): void
	{
		$stickerParams = $message->getParams()->get(Message\Params::STICKER_PARAMS)->getValue();

		if (
			!$message->getParams()->isSet(Message\Params::STICKER_PARAMS)
			|| $message->getParams()->isSet(Message\Params::FORWARD_ID)
		)
		{
			return;
		}

		$fields = [
			'USER_ID' => $this->getContext()->getUserId(),
			'STICKER_ID' => $stickerParams['ID'],
			'PACK_ID' => $stickerParams['PACK_ID'],
			'PACK_TYPE' => $stickerParams['PACK_TYPE'],
			'DATE_CREATE' => $message->getDateCreate() ?? new DateTime(),
		];

		$update = ['DATE_CREATE' => $message->getDateCreate() ?? new DateTime()];
		$uniqueFields = ['USER_ID', 'STICKER_ID', 'PACK_ID', 'PACK_TYPE'];

		StickerRecentTable::merge($fields, $update, $uniqueFields);
	}

	public function delete(int $stickerId, int $packId, PackType $packType): void
	{
		StickerRecentTable::deleteBatch([
			'=USER_ID' => $this->getContext()->getUserId(),
			'=STICKER_ID' => $stickerId,
			'=PACK_ID' => $packId,
			'=PACK_TYPE' => $packType->value,
		]);

		(new StickerRecentDelete($stickerId, $packId, $packType->value))->send();
	}

	public function deleteAll(): void
	{
		StickerRecentTable::deleteBatch(['=USER_ID' => $this->getContext()->getUserId()]);
		(new StickerRecentDeleteAll())->send();
	}

	private function clearOldRecentStickers(array $result, int $limit): void
	{
		$result = array_slice($result, $limit);

		if (!empty($result))
		{
			$recentIds = [];
			foreach ($result as $sticker)
			{
				$recentIds[] = (int)$sticker['ID'];
			}

			StickerRecentTable::deleteBatch(['=ID'=> $recentIds]);
		}
	}
}
