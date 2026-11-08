<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

final class DealCategoryDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		return [
			$this->createColumn('NAME')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_NAME'))
				->setTitle(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_NAME'))
				->setSort('NAME')
				->setDefault(true)
				->setEditable(true)
			,

			$this->createColumn('SORT')
				->setType(Type::INT)
				->setName(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_SORT'))
				->setTitle(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_SORT'))
				->setSort('SORT')
				->setDefault(true)
				->setEditable(true)
			,

			$this->createColumn('ID')
				->setType(Type::INT)
				->setName(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_ID'))
				->setTitle(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_ID'))
				->setSort('ID')
				->setNecessary(true)
			,

			$this->createColumn('CREATED_DATE')
				->setType(Type::DATE)
				->setName(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_CREATED_DATE'))
				->setTitle(Loc::getMessage('CRM_COLUMN_DEAL_CATEGORY_CREATED_DATE'))
				->setSort('CREATED_DATE')
			,
		];
	}
}
