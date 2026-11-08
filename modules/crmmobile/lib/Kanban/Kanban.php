<?php


namespace Bitrix\CrmMobile\Kanban;

use Bitrix\CrmMobile\Filter\NumberFields;

class Kanban extends \Bitrix\Crm\Kanban
{
	private ?array $drawerFilterValues = null;

	/**
	 * Conditions the kanban is read under, plus the ones over a field the mobile draws as a number of
	 * its own accord (`NumberFields`).
	 *
	 * The parent builds a condition by the type the CRM filter declares, and over a field it calls a
	 * text one it reads a plain value only: the keys the numeric form of the drawer writes would be
	 * dropped without a word. The list of the same section reads them as well
	 * (`ListStrategy::getItems`) - a comparison narrowing one view and not the other is a filter that
	 * says one thing and does another.
	 */
	protected function getFilter(array $params = []): array
	{
		$filter = parent::getFilter($params);

		// under this flag the parent keeps the funnel and drops every condition, the drawer included
		if (($params['FORCE_FILTER'] ?? '') === 'Y')
		{
			return $filter;
		}

		$entity = $this->getEntity();

		return NumberFields::applyTo(
			$filter,
			$this->readFilterValues($params),
			static fn(string $fieldId): string => $entity->prepareFilterField($fieldId),
		);
	}

	/**
	 * Values of the filter the kanban is read under, as the parent reads them: of the preset the
	 * request names, and without one of the preset the pointer of the storage names. A filter the
	 * form has not applied narrows nothing there, so it narrows nothing here.
	 *
	 * Read once, the way the parent keeps its filter: the kanban is asked for the filter for the
	 * columns and for the items alike, and the entity builds the options of the grid anew on every
	 * call.
	 */
	protected function readFilterValues(array $params): array
	{
		return $this->drawerFilterValues ??= $this->fetchFilterValues($params);
	}

	private function fetchFilterValues(array $params): array
	{
		$presetId = (string)($params['FILTER_PRESET_ID'] ?? '');
		$presetId = ($presetId === '' ? null : $presetId);

		$entity = $this->getEntity();
		$options = $entity->getFilterOptions();
		if ($presetId !== null)
		{
			$options->setCurrentFilterPresetId($presetId);
		}

		$values = $options->getFilter($entity->getGridFilter($presetId));

		return isset($values['FILTER_APPLIED']) ? $values : [];
	}

	/**
	 * @return array
	 */
	protected function getAdditionalColumnParams(): array
	{
		return [
			'blockPage' => 1,
			'allItemsLoaded' => false,
			'isRefreshing' => true,
		];
	}

	/**
	 * If the field is displayed in the kanban card,
	 * then it is in array format (value and configuration for mobile),
	 * if not displayed, then only the value.
	 * Therefore, we bring the field values to the format for mobile.
	 *
	 * @param array $data
	 * @param $value
	 * @param array|null $displayedFieldsValues
	 */
	protected function prepareField(array &$data, $value, ?array $displayedFieldsValues = []): void
	{
		if (
			isset($data['code'], $displayedFieldsValues[$data['code']])
			&& is_array($displayedFieldsValues[$data['code']])
		)
		{
			$value = $displayedFieldsValues[$data['code']];
		}
		elseif (is_array($value))
		{
			$value['value'] = $value;
		}
		else
		{
			$value = [
				'value' => $value,
			];
		}

		$data['value'] = $value['value'];
		if (isset($value['config']))
		{
			$data['config'] = $value['config'];
		}
	}

	/**
	 * @param array $row
	 * @param array $displayedFields
	 * @return array
	 */
	protected function mergeItemFieldsValues(array $row, array $displayedFields = []): array
	{
		foreach ($displayedFields as $key => $displayedField)
		{
			$displayedFields[$key] = ($displayedField['value'] ?? '');
		}

		return array_merge($row, $displayedFields);
	}

	protected function prepareAdditionalFields(array $item): array
	{
		$result = [];

		if (!array_key_exists('CLIENT', $this->additionalSelect))
		{
			$result['client']['hidden'] = true;
		}

		if (isset($item['ADDITIONAL_CONTACT_INFO']))
		{
			$result['client']['contact'] = [$item['ADDITIONAL_CONTACT_INFO']];
		}
		if (isset($item['ADDITIONAL_COMPANY_INFO']))
		{
			$result['client']['company'] = [$item['ADDITIONAL_COMPANY_INFO']];
		}

		return $result;
	}
}
