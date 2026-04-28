<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid\Column\Provider;

use Bitrix\Crm\RepeatSale\Sandbox\Entity\RepeatSaleSandboxTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\ORM\Entity;

final class SandboxDataProvider extends DataProvider
{
	/**
	 * Used only for field captions. If you are doing something else with it, you are wrong.
	 */
	private Entity $entity;

	public function __construct()
	{
		$this->entity = RepeatSaleSandboxTable::getEntity();

		parent::__construct();
	}

	public function prepareColumns(): array
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return [
			$this->createColumn('ID')
				->setType(Type::INT)
				->setName($this->getFieldCaptionFromOrm('ID'))
				->setTitle($this->getFieldCaptionFromOrm('ID'))
				->setSort('ID')
				->setDefault(true)
				->setNecessary(true)
			,

			$this->createColumn('JOB_ID')
				->setType(Type::INT)
				->setName($this->getFieldCaptionFromOrm('JOB_ID'))
				->setTitle($this->getFieldCaptionFromOrm('JOB_ID'))
				->setSort('JOB_ID')
				->setDefault(true)
			,

			$this->createColumn('ITEM_TYPE_ID')
				->setType(Type::INT)
				->setName($this->getFieldCaptionFromOrm('ITEM_TYPE_ID'))
				->setTitle($this->getFieldCaptionFromOrm('ITEM_TYPE_ID'))
				->setSort('ITEM_TYPE_ID')
				->setDefault(true)
			,

			$this->createColumn('ITEM_ID')
				->setType(Type::INT)
				->setName($this->getFieldCaptionFromOrm('ITEM_ID'))
				->setTitle($this->getFieldCaptionFromOrm('ITEM_ID'))
				->setSort('ITEM_ID')
				->setDefault(true)
			,

			$this->createColumn('CLIENT_TYPE_ID')
				->setType(Type::INT)
				->setName($this->getFieldCaptionFromOrm('CLIENT_TYPE_ID'))
				->setTitle($this->getFieldCaptionFromOrm('CLIENT_TYPE_ID'))
				->setSort('CLIENT_TYPE_ID')
				->setDefault(true)
			,

			$this->createColumn('CLIENT_ID')
				->setType(Type::INT)
				->setName($this->getFieldCaptionFromOrm('CLIENT_ID'))
				->setTitle($this->getFieldCaptionFromOrm('CLIENT_ID'))
				->setSort('CLIENT_ID')
				->setDefault(true)
			,

			$this->createColumn('CHECK_DATE')
				->setType(Type::DATE)
				->setName($this->getFieldCaptionFromOrm('CHECK_DATE'))
				->setTitle($this->getFieldCaptionFromOrm('CHECK_DATE'))
				->setSort('CHECK_DATE')
			,

			$this->createColumn('PROMPT')
				->setType(Type::TEXT)
				->setName($this->getFieldCaptionFromOrm('PROMPT'))
				->setTitle($this->getFieldCaptionFromOrm('PROMPT'))
				->setSort('PROMPT')
			,

			$this->createColumn('PAYLOAD')
				->setType(Type::HTML)
				->setName($this->getFieldCaptionFromOrm('PAYLOAD'))
				->setTitle($this->getFieldCaptionFromOrm('PAYLOAD'))
				->setSort('PAYLOAD')
			,

			$this->createColumn('CREATED_AT')
				->setType(Type::DATE)
				->setName($this->getFieldCaptionFromOrm('CREATED_AT'))
				->setTitle($this->getFieldCaptionFromOrm('CREATED_AT'))
				->setSort('CREATED_AT')
			,

			$this->createColumn('UPDATED_AT')
				->setType(Type::DATE)
				->setName($this->getFieldCaptionFromOrm('UPDATED_AT'))
				->setTitle($this->getFieldCaptionFromOrm('UPDATED_AT'))
				->setSort('UPDATED_AT')
				->setDefault(true)
			,

			/*$this->createColumn('custom')
				->setType(Type::HTML)
				->setName(Loc::getMessage('CRM_GRID_AUTOMATED_SOLUTION_COLUMN_PERMISSIONS'))
				->setTitle(Loc::getMessage('CRM_GRID_AUTOMATED_SOLUTION_COLUMN_PERMISSIONS'))
				->setDefault(true)
			,*/
		];
	}

	private function getFieldCaptionFromOrm(string $fieldName): string
	{
		return $this->entity->getField($fieldName)->getTitle();
	}
}