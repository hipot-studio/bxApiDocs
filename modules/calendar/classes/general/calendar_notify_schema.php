<?php

use Bitrix\Main\Localization\Loc;

IncludeModuleLangFile(__FILE__);

class CCalendarNotifySchema
{
	public function __construct()
	{
	}

	public static function OnGetNotifySchema()
	{
		return [
			"calendar" => [
				"NAME" => Loc::getMessage('EC_NS_NOTIFY_TITLE'),
				"NOTIFY" => [
					"invite" => [
						"NAME" => Loc::getMessage('EC_NS_INVITE_MSGVER_1'),
						"SITE" => "Y",
						"MAIL" => "N",
						"PUSH" => "Y",
						"DISABLED" => [IM_NOTIFY_FEATURE_SITE],
					],
					"reminder" => [
						"NAME" => Loc::getMessage('EC_NS_REMINDER'),
						"SITE" => "Y",
						"MAIL" => "N",
						"PUSH" => "Y",
					],
					"change" => [
						"NAME" => Loc::getMessage('EC_NS_CHANGE'),
						"SITE" => "Y",
						"MAIL" => "N",
					],
					"info" => [
						"NAME" => Loc::getMessage('EC_NS_INFO_MSGVER_1'),
						"SITE" => "Y",
						"MAIL" => "N",
					],
					"event_comment" => [
						"NAME" => Loc::getMessage('EC_NS_EVENT_COMMENT'),
						"SITE" => "Y",
						"MAIL" => "N",
					],
					"delete_location" => [
						"NAME" => Loc::getMessage('EC_NS_DELETE_LOCATION_MSGVER_1'),
						"SITE" => "Y",
						"MAIL" => "N",
					],
					"rollback_sync" => [
						"NAME" => Loc::getMessage('EC_NS_ROLLBACK_SYNC'),
						"MAIL" => "N",
						"PUSH" => "N",
						"DISABLED" => [
							IM_NOTIFY_FEATURE_PUSH,
						],
					],
					"finished_sync" => [
						"NAME" => Loc::getMessage('EC_NS_FINISHED_SYNC'),
						"MAIL" => "N",
						"PUSH" => "N",
						"DISABLED" => [
							IM_NOTIFY_FEATURE_PUSH,
						],
					],
				],
			],
		];
	}
}

class CCalendarPullSchema
{
	public static function OnGetDependentModule()
	{
		return [
			'MODULE_ID' => "calendar",
			'USE' => ["PUBLIC_SECTION"],
		];
	}
}
