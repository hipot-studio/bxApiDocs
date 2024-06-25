<?php

namespace Bitrix\Main\Security\Notifications;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

class VendorNotificationSignTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_sec_vendor_notification_sign';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('USER_ID')),
			(new StringField('NOTIFICATION_VENDOR_ID')),
			(new DatetimeField('DATE'))
		];
	}

	public static function signOrIgnore($notificationId, $userId)
	{
		$tableName = static::getTableName();
		$connection = static::getEntity()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		[$fields, $values] = $sqlHelper->prepareInsert($tableName, [
			'USER_ID' => $userId,
			'NOTIFICATION_VENDOR_ID' => $notificationId,
			'DATE' => new DateTime
		]);

		$query = $sqlHelper->getInsertIgnore($tableName, "($fields)", "VALUES($values)");

		$connection->query($query);
	}
}