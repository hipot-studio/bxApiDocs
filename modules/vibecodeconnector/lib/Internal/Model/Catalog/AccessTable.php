<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Catalog;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\Catalog\EO_Access_Query query()
 */
final class AccessTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_catalog_access';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('CATALOG_ITEM_ID'))
				->configureRequired(),

			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->configureSize(100),

			(new DatetimeField('GRANTED_AT'))
				->configureRequired(),
		];
	}
}
