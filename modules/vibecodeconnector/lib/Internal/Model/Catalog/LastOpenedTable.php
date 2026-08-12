<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Catalog;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\Catalog\EO_LastOpened_Query query()
 */
final class LastOpenedTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_catalog_last_opened';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new IntegerField('CATALOG_ITEM_ID'))
				->configureRequired(),

			(new DatetimeField('OPENED_AT'))
				->configureRequired(),
		];
	}
}
