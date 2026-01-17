<?php

namespace Bitrix\Crm\Filter;

use Bitrix\Crm\Entity\EntityEditorOptionBuilder;
use Bitrix\Crm\UI\EntitySelector;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Entity;
use Bitrix\Ui\EntityForm\EntityFormConfigTable;

class EntityEditorConfigDataProvider extends \Bitrix\Main\Filter\EntityDataProvider
{
	private Settings $settings;
	private Entity $entity;
	private \Bitrix\Crm\Service\Factory $factory;

	public function __construct(string $id, \Bitrix\Crm\Service\Factory $factory)
	{
		$this->settings = new Settings(['ID' => $id]);
		$this->entity = EntityFormConfigTable::getEntity();
		$this->factory = $factory;
	}

	protected function getFieldName($fieldID): ?string
	{
		return Loc::getMessage("CRM_ENTITY_CONFIG_FILTER_{$fieldID}");
	}

	public function getSettings(): Settings
	{
		return $this->settings;
	}

	public function prepareFields(): array
	{
		return [
			$this->createField(
				'CATEGORY',
				[
					'type' => 'list',
					'name' =>  Loc::getMessage('CRM_COMMON_PIPELINE'),
					'default' => true,
					'partial' => true,
				],
			),
		];
	}

	private function getFieldCaption(string $fieldName): ?string
	{
		if ($this->entity->hasField($fieldName))
		{
			return $this->entity->getField($fieldName)->getTitle();
		}

		return null;
	}

	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === 'USERS')
		{
			return $this->getUserEntitySelectorParams(
				EntitySelector::CONTEXT,
				[
					'fieldName' => $fieldID,
				],
			);
		}
		if ($fieldID === 'CATEGORY')
		{
			return [
				'params' => ['multiple' => 'Y'],
				'items' => $this->getCategories(),
			];
		}

		return null;
	}

	private function getCategories(): array
	{
		$data = [];
		$categories = $this->factory->getCategories();
		foreach ($categories as $category)
		{
			$data[$category->getId()] = $category->getName();
		}

		return $data;
	}

	public function prepareListFilter(array &$filter, $requestFilter): void
	{
		if (isset($requestFilter['CATEGORY']) && $requestFilter['CATEGORY'] !== '')
		{
			if (is_array($requestFilter['CATEGORY']))
			{
				$entityTypeIds = [];
				foreach ($requestFilter['CATEGORY'] as $categoryId)
				{
					$entityTypeIds[] = (new EntityEditorOptionBuilder($this->factory->getEntityTypeId()))
						->setCategoryId($categoryId)
						->build()
					;
				}

				$filter["@ENTITY_TYPE_ID"] = $entityTypeIds;
			}
			else
			{
				$filter["=ENTITY_TYPE_ID"] = $this->getEntityTypeIdForEditorConfig((int)$requestFilter['CATEGORY']);

				return;
			}
		}
		else
		{
			//filter by all entity categories
			$entityCategories = $this->factory->getCategories();
			$entityTypeIds = [];
			foreach ($entityCategories as $category)
			{
				$entityTypeIds[] = $this->getEntityTypeIdForEditorConfig($category->getId());
			}

			$filter["@ENTITY_TYPE_ID"] = $entityTypeIds;
		}

		if (isset($requestFilter['FIND']) && $requestFilter['FIND'] !== '')
		{
			$filter["NAME"] = $requestFilter['FIND'] . '%';
		}
	}

	private function getEntityTypeIdForEditorConfig(int $categoryId): string
	{
		return (new EntityEditorOptionBuilder($this->factory->getEntityTypeId()))
			->setCategoryId($categoryId)
			->build()
		;
	}
}
