<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DecimalField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Class StorageRecordFieldTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> RECORD_ID int mandatory
 * <li> FIELD_ID int mandatory
 * <li> VALUE text mandatory
 * <li> VALUE_NUM double optional
 * </ul>
 *
 * @package Bitrix\Bizproc\Internal\Model
 **/

class StorageRecordFieldTable extends DataManager
{
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_bp_storage_record_field';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('RECORD_ID'))
				->configureRequired()
			,
			(new IntegerField('FIELD_ID'))
				->configureRequired()
			,
			(new TextField('VALUE'))
				->configureRequired()
			,
			(new DecimalField('VALUE_NUM'))
				->configureNullable()
				->configurePrecision(18)
				->configureScale(4)
			,
			new \Bitrix\Main\ORM\Fields\Relations\Reference(
				'FIELD',
				StorageFieldTable::class,
				\Bitrix\Main\ORM\Query\Join::on('this.FIELD_ID', 'ref.ID')
			),
		];
	}
}
