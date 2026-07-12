<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Feature;

IncludeModuleLangFile(__FILE__);

class CSocNetNotifySchema
{
	public function __construct()
	{
	}

	public static function OnGetNotifySchema()
	{
		$isNewProjectOn = Feature::isNewProjectsOn();
		$messageKeySuffix = ($isNewProjectOn ? '_V2' : '');

		$arResult = [
			"socialnetwork" => [
				"NAME" => Loc::getMessage('SONET_NS_SONET_GROUP_TITLE' . $messageKeySuffix),
				"NOTIFY" => [
					"invite_group" => [
						"NAME" => Loc::getMessage("SONET_NS_INVITE_GROUP_INFO_MSGVER_1" . $messageKeySuffix),
						"DISABLED" => [IM_NOTIFY_FEATURE_SITE],
					],
					"invite_group_btn" => [
						"NAME" => Loc::getMessage("SONET_NS_INVITE_GROUP_BTN" . $messageKeySuffix),
						"DISABLED" => [
							IM_NOTIFY_FEATURE_SITE,
							IM_NOTIFY_FEATURE_XMPP,
							IM_NOTIFY_FEATURE_MAIL,
							IM_NOTIFY_FEATURE_PUSH,
						],
					],
					"inout_group" => [
						"NAME" => Loc::getMessage("SONET_NS_INOUT_GROUP" . $messageKeySuffix),
					],
					"moderators_group" => [
						"NAME" => Loc::getMessage("SONET_NS_MODERATORS_GROUP" . $messageKeySuffix),
					],
					"owner_group" => [
						"NAME" => Loc::getMessage("SONET_NS_OWNER_GROUP_MSGVER_1" . $messageKeySuffix),
					],
					"sonet_group_event" => [
						"NAME" => Loc::getMessage("SONET_NS_SONET_GROUP_EVENT" . $messageKeySuffix),
						"PUSH" => 'Y',
					],
				],
			],
		];

		if (CSocNetUser::IsFriendsAllowed())
		{
			$arResult["socialnetwork"]["inout_user"] = [
				"NAME" => GetMessage("SONET_NS_FRIEND"),
			];
		}

		return $arResult;
	}
}

class CSocNetPullSchema
{
	public static function OnGetDependentModule()
	{
		return [
			'MODULE_ID' => "socialnetwork",
			'USE' => ["PUBLIC_SECTION"],
		];
	}
}
