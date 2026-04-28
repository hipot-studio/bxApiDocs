<?php

namespace Bitrix\Ldap\Internal\Models;

use Bitrix\Ldap\Sync\State;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\JsonField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

final class SyncSessionTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_ldap_sync_session';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('SERVER_ID'))
				->configureRequired()
			,
			(new StringField('STATE'))
				->configureRequired()
				->configureDefaultValue(State::Idle->value)
				->addValidator(new LengthValidator(min:1, max:20))
			,
			(new DatetimeField('STARTED_AT'))
				->configureNullable(false)
				->configureDefaultValueNow()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureNullable()
			,
			(new DatetimeField('FINISHED_AT'))
				->configureNullable()
			,
			(new JsonField('PROGRESS'))
				->configureNullable()
				->configureDefaultValue('')
			,
			(new StringField('MESSAGE'))
				->configureNullable()
				->addValidator(new LengthValidator(max:255))
			,
		];
	}
}