<?php

namespace Bitrix\Main\Data\Infrastructure;

use Bitrix\Main\Data\Internal\Storage\PersistentStorageTable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;

final class StorageCleanupAgent
{
	public static function run(): ?string
	{
		PersistentStorageTable::deleteByFilter(
			(new ConditionTree())
				->where('EXPIRED_AT', '<', new DateTime())
		);

		return __METHOD__ . '();';
	}
}
