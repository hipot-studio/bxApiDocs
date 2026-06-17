<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList\Column\Provider;

use Bitrix\Bizproc\Internal\Model\StorageTypeTable;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;

class StorageListDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		$entity = StorageTypeTable::getEntity();

		return [
			$this->createColumn('ID')
				->setType(Type::INT)
				->setName($entity->getField('ID')->getTitle())
				->setSort('ID')
				->setDefault(true)
				->setNecessary(true)
			,

			$this->createColumn('TITLE')
				->setType(Type::TEXT)
				->setName($entity->getField('TITLE')->getTitle())
				->setSort('TITLE')
				->setDefault(true)
			,

			$this->createColumn('DESCRIPTION')
				->setType(Type::TEXT)
				->setName($entity->getField('DESCRIPTION')->getTitle())
				->setDefault(true)
			,

			$this->createColumn('CODE')
				->setType(Type::TEXT)
				->setName($entity->getField('CODE')->getTitle())
				->setSort('CODE')
				->setDefault(true)
			,
		];
	}
}
