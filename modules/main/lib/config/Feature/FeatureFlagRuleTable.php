<?php

namespace Bitrix\Main\Config\Feature;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\JsonField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

final class FeatureFlagRuleTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_feature_flag_rule';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('FEATURE_CODE'))
				->configureRequired()
				->configureSize(255)
				->addValidator(new LengthValidator(min: 1, max: 255))
			,
			(new EnumField('POLICY'))
				->configureRequired()
				->configureValues(array_column(RulePolicy::cases(), 'value'))
			,
			(new StringField('RULE_CODE'))
				->configureRequired()
				->configureSize(255)
				->addValidator(new LengthValidator(min: 1, max: 255))
			,
			(new JsonField('RULE_ARGS'))
				->configureNullable(true)
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
