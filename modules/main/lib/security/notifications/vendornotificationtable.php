<?php

namespace Bitrix\Main\Security\Notifications;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

class VendorNotificationTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_sec_vendor_notification';
	}

	public static function getMap()
	{
		global $USER;

		return [
			(new StringField('VENDOR_ID'))
				->configurePrimary(),
			(new TextField('DATA')),
			(new ExpressionField('NOT_SIGNED', 'NOT EXISTS(
				SELECT 1 FROM b_sec_vendor_notification_sign s WHERE %s=s.NOTIFICATION_VENDOR_ID and s.USER_ID='.intval($USER->getId()).'
			)', ['VENDOR_ID']))
				->configureValueType(BooleanField::class)
		];
	}
}