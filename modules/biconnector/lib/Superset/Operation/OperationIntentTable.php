<?php

namespace Bitrix\BIConnector\Superset\Operation;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

final class OperationIntentTable extends DataManager
{
	use DeleteByFilterTrait;

	public const OPERATION_DELETE = 'delete';
	public const OPERATION_SUSPEND = 'suspend';
	public const OPERATION_FREEZE = 'freeze';

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_operation_intent';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,

			(new Fields\StringField('OPERATION_ID'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 64))
			,

			(new Fields\StringField('OPERATION'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 32))
			,

			new Fields\IntegerField('SERVER_ID'),

			(new Fields\StringField('GENERATION'))
				->addValidator(new LengthValidator(null, 32))
			,

			(new Fields\StringField('PARAMS_HASH'))
				->addValidator(new LengthValidator(null, 64))
			,

			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime())
			,

			(new Fields\DatetimeField('DATE_EXPIRE'))
				->configureRequired()
			,
		];
	}
}
