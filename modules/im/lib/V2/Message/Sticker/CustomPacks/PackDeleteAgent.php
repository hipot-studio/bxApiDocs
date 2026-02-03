<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\CustomPacks;

use Bitrix\Im\Model\StickerPackTable;
use Bitrix\Im\V2\Message\Sticker\CustomPacks;
use Bitrix\Im\V2\Message\Sticker\PackFactory;
use Bitrix\Im\V2\Message\Sticker\PackType;

class PackDeleteAgent
{
	protected const DELAY = 86400;
	protected const INTERVAL = 600;

	protected const PACK_LIMIT = 5;

	public static function onAfterUserUpdate(array $fields): void
	{
		if (isset($fields['ACTIVE']) && $fields['ACTIVE'] === 'N')
		{
			self::addAgent($fields);
		}
		elseif (isset($fields['ACTIVE']) && $fields['ACTIVE'] === 'Y')
		{
			self::deleteAgent($fields);
		}
	}

	protected static function addAgent(array $fields): void
	{
		\CAgent::AddAgent(
			self::getAgentName((int)$fields['ID']),
			'im',
			'N',
			self::INTERVAL,
			'',
			'Y',
			ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::DELAY, "FULL"),
			existError: false
		);
	}

	protected static function deleteAgent(array $fields): void
	{
		$res = \CAgent::getList([], [
			'NAME' => self::getAgentName((int)$fields['ID']),
			'MODULE_ID' => 'im'
		]);

		while($item = $res->fetch())
		{
			\CAgent::Delete($item['ID']);
		}
	}

	public static function deleteStickerPacksAfterUserFiredAgent(int $userId): string
	{
		$packIds = StickerPackTable::query()
			->setSelect(['ID'])
			->where('AUTHOR_ID', $userId)
			->setLimit(self::PACK_LIMIT)
			->fetchAll()
		;

		foreach ($packIds as $packId)
		{
			$packId = (int)$packId['ID'];
			$pack = PackFactory::getInstance()->getByType(PackType::Custom);
			if ($pack instanceof CustomPacks)
			{
				$pack->withContextUser($userId)->deletePack($packId);
			}
		}

		if (count($packIds) >= self::PACK_LIMIT)
		{
			return self::getAgentName($userId);
		}

		return '';
	}

	private static function getAgentName(int $userId): string
	{
		return "\Bitrix\Im\V2\Message\Sticker\CustomPacks\PackDeleteAgent::deleteStickerPacksAfterUserFiredAgent({$userId});";
	}
}
