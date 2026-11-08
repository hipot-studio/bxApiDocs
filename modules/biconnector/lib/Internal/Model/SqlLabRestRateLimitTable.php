<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

final class SqlLabRestRateLimitTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_rest_sqllab_rate';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new StringField('DIMENSION_KEY'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 128)),
			(new DatetimeField('WINDOW_START'))
				->configureRequired(),
			(new IntegerField('REQUEST_COUNT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new DatetimeField('UPDATED_AT'))
				->configureRequired(),
		];
	}

	public static function deleteExpired(DateTime $threshold): void
	{
		self::deleteByFilter(['<UPDATED_AT' => $threshold]);
	}
}
