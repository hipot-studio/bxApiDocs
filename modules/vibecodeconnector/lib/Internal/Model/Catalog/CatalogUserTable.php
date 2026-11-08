<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Model\Catalog;

use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\Merge;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * @method static \Bitrix\Vibecodeconnector\Internal\Model\Catalog\EO_CatalogUser_Query query()
 */
final class CatalogUserTable extends DataManager
{
	use DeleteByFilterTrait;
	use AddMergeTrait;

	public static function getTableName(): string
	{
		return 'b_vibecodeconnector_catalog_user';
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

			(new DatetimeField('LEFT_AT'))
				->configureNullable(),

			(new DatetimeField('ARRIVAL_PENDING_AT'))
				->configureNullable(),
		];
	}

	protected static function getMergeStrategy(): AddStrategy
	{
		return new Merge(static::getEntity(), ['USER_ID', 'CATALOG_ITEM_ID']);
	}
}
