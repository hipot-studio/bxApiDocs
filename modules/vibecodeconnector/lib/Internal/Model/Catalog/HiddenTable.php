<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Catalog;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\Catalog\EO_Hidden_Query query()
 */
final class HiddenTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_catalog_hidden';
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

			(new DatetimeField('HIDDEN_AT'))
				->configureRequired(),
		];
	}
}
