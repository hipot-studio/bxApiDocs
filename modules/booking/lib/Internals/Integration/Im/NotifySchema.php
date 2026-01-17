<?php

namespace Bitrix\Booking\Internals\Integration\Im;

use Bitrix\Main\Localization\Loc;

class NotifySchema
{
	public static function onGetNotifySchema()
	{
		return [
			'booking' => [
				'NOTIFY' => [
					'info' => [
						'NAME' => Loc::getMessage('BOOKING_IM_NOTIFY_SCHEMA_INFO'),
						"MAIL" => "N",
						"PUSH" => "Y",
					],
				],
			],
		];
	}
}
