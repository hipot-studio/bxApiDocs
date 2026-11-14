<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\EventHandler;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\StaffTrack\Internal\Integration\Im\OfficeAccessNotifier;

/**
 * The group of the module in the notification settings of a user.
 *
 * The confirmation of a request cannot be switched off on the site, as an invitation of the calendar
 * cannot: a decider who switched it off would leave the requester waiting for a decision nobody sees.
 */
class NotifySchemaHandler
{
	/**
	 * Handles im:OnGetNotifySchema.
	 *
	 * @return array
	 */
	public static function onGetNotifySchema(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		return [
			OfficeAccessNotifier::MODULE_ID => [
				'NAME' => Loc::getMessage('STAFFTRACK_INTEGRATION_IM_NOTIFY_SCHEMA_NAME'),
				'NOTIFY' => [
					OfficeAccessNotifier::EVENT_REQUEST => [
						'NAME' => Loc::getMessage('STAFFTRACK_INTEGRATION_IM_NOTIFY_SCHEMA_OFFICE_ACCESS_REQUEST'),
						'SITE' => 'Y',
						'MAIL' => 'N',
						'PUSH' => 'Y',
						'DISABLED' => [IM_NOTIFY_FEATURE_SITE],
					],
					OfficeAccessNotifier::EVENT_RESULT => [
						'NAME' => Loc::getMessage('STAFFTRACK_INTEGRATION_IM_NOTIFY_SCHEMA_OFFICE_ACCESS_RESULT'),
						'SITE' => 'Y',
						'MAIL' => 'N',
						'PUSH' => 'Y',
					],
				],
			],
		];
	}
}
