<?php

namespace Bitrix\Main\Data\Infrastructure;

use Bitrix\Main\Data\Internal\Storage\PersistentStorageTable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\Callback;
use Bitrix\Main\Type\DateTime;

final class StorageCleanupAgent
{
	public static function run(): Callback
	{
		PersistentStorageTable::deleteByFilter(
			(new ConditionTree())
				->where('EXPIRED_AT', '<', new DateTime())
		);

		return new Callback([self::class, __FUNCTION__]);
	}
}
