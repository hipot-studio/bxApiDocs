<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Kanban\View;

use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Item;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Display;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\Crm\Service\Factory;

/**
 * Values of the compact field set: such fields are loaded and formatted apart from the item list itself.
 */
final class CompactDisplayValues
{
	public function __construct(private readonly Factory $factory)
	{
	}

	/**
	 * @param int[] $itemIds
	 * @param string[] $fieldsNames
	 */
	public function getItemParams(array $itemIds, array $fieldsNames): array
	{
		$params = ['isCompactView' => true];
		if (empty($fieldsNames) || empty($itemIds))
		{
			return $params;
		}

		$fieldsCollection = $this->factory->getFieldsCollection();

		$displayedFields = $this->getDisplayedFields($fieldsCollection, $fieldsNames);
		if (empty($displayedFields))
		{
			return $params;
		}

		$entityTypeId = $this->factory->getEntityTypeId();
		$fieldsValues = $this->getItemsFieldsValues($itemIds, array_keys($displayedFields));

		$params['displayValues'] =
			(new Display($entityTypeId, $displayedFields, $this->getDisplayOptions($itemIds)))
				->setItems($fieldsValues)
				->getAllValues()
		;
		$params['fieldsCollection'] = $fieldsCollection;

		return $params;
	}

	/**
	 * @param string[] $fieldsNames
	 * @return Field[]
	 */
	private function getDisplayedFields(Collection $fieldsCollection, array $fieldsNames): array
	{
		$displayedFields = [];
		foreach ($fieldsNames as $fieldName)
		{
			if (!$fieldsCollection->hasField($fieldName))
			{
				continue;
			}

			$displayedFields[$fieldName] =
				DisplayFieldFactory::create($fieldName, $fieldsCollection)
					->setContext(Field::MOBILE_CONTEXT)
			;
		}

		return $displayedFields;
	}

	/**
	 * @param int[] $itemIds
	 */
	private function getDisplayOptions(array $itemIds): Display\Options
	{
		$options = new Display\Options();

		$restriction = RestrictionManager::getWebFormResultsRestriction();
		if (!$restriction->hasPermission())
		{
			$restriction->prepareDisplayOptions($this->factory->getEntityTypeId(), $itemIds, $options);
		}

		return $options;
	}

	/**
	 * @param int[] $itemIds
	 * @param string[] $fieldsNames
	 */
	private function getItemsFieldsValues(array $itemIds, array $fieldsNames): array
	{
		$items = $this->factory->getItemsFilteredByPermissions([
			'select' => [Item::FIELD_NAME_ID, ...$fieldsNames],
			'filter' => ['@' . Item::FIELD_NAME_ID => $itemIds],
		]);

		$values = [];
		foreach ($items as $item)
		{
			$values[$item->getId()] = $item->getData();
		}

		return $values;
	}
}
