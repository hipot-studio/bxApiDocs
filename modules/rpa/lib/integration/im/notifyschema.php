<?php

namespace Bitrix\Rpa\Integration\Im;

use Bitrix\Main\Localization\Loc;

class NotifySchema
{
	public static function onGetNotifySchema()
	{
		return [
			'rpa' => [
				'NAME' => Loc::getMessage('RPA_NOTIFY_SCHEMA_BLOCK_TITLE'),
				'PRODUCT_SOURCE' => [
					'ID' => 'rpa',
					'NAME' => Loc::getMessage('RPA_NOTIFY_SCHEMA_PRODUCT_SOURCE_NAME'),
					'DESCRIPTION' => Loc::getMessage('RPA_NOTIFY_SCHEMA_PRODUCT_SOURCE_DESCRIPTION'),
					'ICON' => ['TYPE' => 'named', 'VALUE' => 'robot'],
					'SORT' => 1000,
				],
				'NOTIFY' => [
					'mention' => [
						'NAME' => Loc::getMessage('RPA_NOTIFY_SCHEMA_MENTION'),
						"MAIL" => "N",
						"PUSH" => "Y",
					],
				],
			],
		];
	}
}
