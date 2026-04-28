<?php

namespace Bitrix\Ldap\Internal\Models;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\MergeByDefaultTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

final class UserLastSyncTable extends DataManager
{
	use MergeByDefaultTrait;

	public static function getTableName()
	{
		return 'b_ldap_user_last_sync';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('USER_ID'))
				->configurePrimary()
			,
			(new IntegerField('SERVER_ID'))
				->configurePrimary()
			,
			(new IntegerField('SESSION_ID'))
				->configureRequired()
			,
			(new DatetimeField('LAST_SYNC_AT'))
				->configureRequired()
			,
		];
	}
}