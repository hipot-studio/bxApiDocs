<?php

namespace Bitrix\Socialnetwork\Internals\Space\LiveWatch;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class LiveWatchTable
 *
 * Fields:
 * <ul>
 * <li> USER_ID int mandatory
 * <li> DATETIME datetime mandatory
 * </ul>
 *
 * @package Bitrix\Socialnetwork\Internals\Space\LiveWatch
 */

final class LiveWatchTable extends DataManager
{
	use MergeTrait;
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_sonet_space_live_watch';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('USER_ID'))
				->configurePrimary()
				->configureRequired()
			,
			(new DatetimeField('DATETIME'))
				->configureRequired()
			,
			(new IntegerField('SECONDARY_ENTITY_ID')),
		];
	}

	public static function getUniqueFields(): array
	{
		return ['USER_ID'];
	}
}