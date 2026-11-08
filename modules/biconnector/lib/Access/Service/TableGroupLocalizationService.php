<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\Main\Localization\Loc;

final class TableGroupLocalizationService
{
	private const NAME_PHRASE_PREFIX = 'BICONNECTOR_TABLE_GROUP_NAME_';

	public static function update(?string $langCode): void
	{
		$groupCollection = TableGroupTable::getList([
			'filter' => [
				'=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM,
			],
		])
			->fetchCollection()
		;

		foreach ($groupCollection as $group)
		{
			$groupName = self::getGroupName($group->getCode(), $langCode);

			if (empty($groupName))
			{
				continue;
			}

			$group->setName($groupName);
		}

		$groupCollection->save();
	}

	/**
	 * @param string $groupCode System group code.
	 * @param string|null $language Language of the phrase, the current one when omitted.
	 *
	 * @return string Empty string when the phrase is missing.
	 */
	public static function getGroupName(string $groupCode, ?string $language = null): string
	{
		Loc::loadLanguageFile(__FILE__, $language);

		return Loc::getMessage(self::NAME_PHRASE_PREFIX . strtoupper($groupCode), null, $language) ?? '';
	}
}
