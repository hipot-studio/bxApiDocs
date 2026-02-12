<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\SharingLink\Type;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

class SharingLinkTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_im_sharing_link';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('ENTITY_TYPE'))
				->configureRequired()
				->configureSize(50),

			(new StringField('ENTITY_ID'))
				->configureRequired()
				->configureSize(100),

			(new StringField('CODE'))
				->configureRequired()
				->configureSize(32),

			(new IntegerField('AUTHOR_ID'))
				->configureRequired()
				->configureDefaultValue(0),

			(new EnumField('TYPE'))
				->configureRequired()
				->configureDefaultValue(Type::Custom->value)
				->configureValues(Type::getValues()),

			(new DatetimeField('DATE_CREATE'))
				->configureRequired(),

			(new DatetimeField('DATE_EXPIRE'))
				->configureNullable()
				->configureDefaultValue(null),

			(new BooleanField('IS_REVOKED'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new IntegerField('MAX_USES'))
				->configureNullable()
				->configureDefaultValue(null),

			(new IntegerField('USES_COUNT'))
				->configureRequired()
				->configureDefaultValue(0),

			(new BooleanField('REQUIRE_APPROVAL'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			(new StringField('NAME'))
				->configureNullable()
				->configureDefaultValue(null)
				->configureSize(255),
		];
	}
}
