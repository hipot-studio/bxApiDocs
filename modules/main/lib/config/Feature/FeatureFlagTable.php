<?php

namespace Bitrix\Main\Config\Feature;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\MergeByDefaultTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

final class FeatureFlagTable extends DataManager
{
	use MergeByDefaultTrait;
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_feature_flag';
	}

	public static function getMap()
	{
		return [
			(new StringField('CODE'))
				->configurePrimary()
				->configureSize(255)
				->addValidator(new LengthValidator(min: 1, max: 255))
			,
			(new StringField('MODULE_ID'))
				->configureRequired()
				->configureSize(50)
				->addValidator(new LengthValidator(min: 1, max: 50))
			,
			(new BooleanField('ENABLED'))
				->configureDefaultValue(false)
				->configureValues('N', 'Y')
			,
			(new DatetimeField('MODIFIED_AT'))
				->configureNullable(false)
				->configureDefaultValueNow()
			,
			(new IntegerField('MODIFIED_BY'))
				->configureNullable(true)
			,
		];
	}
}
