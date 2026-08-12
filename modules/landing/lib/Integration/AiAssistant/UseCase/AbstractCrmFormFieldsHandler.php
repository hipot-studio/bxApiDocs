<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Crm\Controller\Form as CrmFormController;
use Bitrix\Crm\WebForm\EntityFieldProvider;
use Bitrix\Crm\WebForm\Options;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetCrmFormFieldsDto;
use Bitrix\Landing\Subtype\Form;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractCrmFormFieldsHandler extends AbstractCrmFormHandler
{
	private const WARNING_GLOBAL_FORM_CHANGE = 'This changes the CRM form itself, not only the landing block where it is embedded.';
	private const LAYOUT_TYPES = ['section', 'page', 'hr', 'br'];
	private const PATCHABLE_FIELDS = [
		'label',
		'required',
		'multiple',
		'placeholder',
		'hint',
		'hintOnFocus',
		'autocomplete',
		'value',
		'valueType',
		'items',
	];
	private const LIST_LIKE_TYPES = ['list', 'radio', 'checkbox'];

	protected function resolveFormId(GetCrmFormFieldsDto $dto): int
	{
		if ($dto->pageId === null || $dto->blockId === null)
		{
			if ($dto->formId !== null)
			{
				return $dto->formId;
			}

			throw new InvalidArgumentException('Either formId or pageId + blockId must be provided.');
		}

		$formId = $this->getFormIdByBlock($dto->blockId);
		if ($formId === null)
		{
			throw new InvalidArgumentException('The selected block does not contain a CRM form binding.');
		}

		if ($dto->formId !== null && $dto->formId !== $formId)
		{
			throw new InvalidArgumentException('The provided formId does not match the CRM form bound to the selected block.');
		}

		return $formId;
	}

	protected function getFormIdByBlock(int $blockId): ?int
	{
		return Form::getFormByBlock($blockId);
	}

	protected function loadOptions(int $formId): array
	{
		if (!Loader::includeModule('crm'))
		{
			throw new RuntimeException('CRM module is not available.');
		}

		$options = Options::create($formId)->getArray();
		if ((int)($options['id'] ?? 0) !== $formId)
		{
			throw new InvalidArgumentException('CRM form not found.');
		}

		return $options;
	}

	protected function buildFieldsResponse(int $formId, array $options, ?array $source = null): array
	{
		$fields = $this->getOptionsFields($options);

		$response = [
			'formId' => $formId,
			'formName' => (string)($options['name'] ?? ''),
			'templateId' => $options['templateId'] ?? null,
			'document' => [
				'scheme' => $options['document']['scheme'] ?? null,
			],
			'fieldsHash' => $this->buildFieldsHash($fields),
			'fields' => $this->serializeFields($fields),
			'warnings' => [$this->buildGlobalFormWarning($formId, (string)($options['name'] ?? ''))],
		];

		if ($source !== null)
		{
			$response['source'] = $source;
		}

		return $response;
	}

	protected function buildFieldsHash(array $fields): string
	{
		$normalized = array_map(
			fn(array $field): array => $this->normalizeFieldForHash($field),
			array_values($fields),
		);

		return 'sha256:' . hash('sha256', Json::encode($normalized));
	}

	protected function getOptionsFields(array $options): array
	{
		return array_values(array_filter(
			$options['data']['fields'] ?? [],
			static fn(mixed $field): bool => is_array($field),
		));
	}

	protected function serializeFields(array $fields): array
	{
		return array_map(
			fn(array $field, int $index): array => $this->serializeField($field, $index + 1),
			array_values($fields),
			array_keys(array_values($fields)),
		);
	}

	protected function serializeAvailableFieldCategories(array $fieldsTree, ?string $query): array
	{
		$needle = $query !== null ? mb_strtolower($query) : null;
		$categories = [];

		foreach ($fieldsTree as $categoryId => $category)
		{
			if (!is_array($category))
			{
				continue;
			}

			$fields = [];
			foreach (($category['FIELDS'] ?? []) as $field)
			{
				if (!is_array($field))
				{
					continue;
				}
				if (!$this->isSupportedAvailableField($field))
				{
					continue;
				}

				$item = [
					'name' => (string)($field['name'] ?? ''),
					'caption' => (string)($field['caption'] ?? ''),
					'type' => (string)($field['type'] ?? ''),
					'entityName' => (string)($field['entity_name'] ?? $categoryId),
					'entityFieldName' => (string)($field['entity_field_name'] ?? ''),
					'multiple' => (bool)($field['multiple'] ?? false),
					'required' => (bool)($field['required'] ?? false),
				];

				if (isset($field['items']) && is_array($field['items']))
				{
					$item['items'] = array_values($field['items']);
				}

				if ($needle !== null && !$this->availableFieldMatchesQuery($item, $needle))
				{
					continue;
				}

				$fields[] = $item;
			}

			if ($fields === [])
			{
				continue;
			}

			$categories[] = [
				'id' => (string)$categoryId,
				'name' => (string)($category['CAPTION'] ?? $categoryId),
				'fields' => $fields,
			];
		}

		return $categories;
	}

	protected function getAvailableFieldsTree(
		bool $hideVirtual,
		bool $hideRequisites,
		bool $hideSmartDocument,
		?int $presetId = null,
	): array
	{
		$hiddenTypes = [];
		if ($hideVirtual)
		{
			$hiddenTypes[] = EntityFieldProvider::TYPE_VIRTUAL;
		}
		if ($hideRequisites)
		{
			$hiddenTypes[] = \CCrmOwnerType::Requisite;
		}
		if ($hideSmartDocument)
		{
			$hiddenTypes[] = \CCrmOwnerType::SmartDocument;
			if (defined('CCrmOwnerType::SmartB2eDocument'))
			{
				$hiddenTypes[] = \CCrmOwnerType::SmartB2eDocument;
			}
		}

		return EntityFieldProvider::getFieldsTree($hiddenTypes, $presetId);
	}

	protected function getAddableAvailableFieldsTree(?int $presetId = null): array
	{
		return $this->getAvailableFieldsTree(
			hideVirtual: true,
			hideRequisites: true,
			hideSmartDocument: true,
			presetId: $presetId,
		);
	}

	protected function getAvailableFieldsPresetId(array $options): ?int
	{
		$value = $options['FORM_SETTINGS']['REQUISITE_PRESET_ID'] ?? null;
		if (is_bool($value) || !is_scalar($value))
		{
			return null;
		}

		$presetId = filter_var($value, FILTER_VALIDATE_INT);

		return $presetId !== false && $presetId > 0 ? $presetId : null;
	}

	protected function applyOperations(array $options, array $operations): array
	{
		$fields = $this->getOptionsFields($options);
		if ($operations === [])
		{
			throw new InvalidArgumentException('At least one field operation must be provided.');
		}

		$removedFieldNames = [];
		foreach ($operations as $operation)
		{
			if (!is_array($operation))
			{
				throw new InvalidArgumentException('Each operation must be an object.');
			}

			$type = (string)($operation['type'] ?? '');
			$fields = match ($type)
			{
				'add_existing_field' => $this->applyAddExistingField($options, $fields, $operation),
				'add_layout_field' => $this->applyAddLayoutField($options, $fields, $operation),
				'remove_field' => $this->applyRemoveField($fields, $operation, $removedFieldNames),
				'update_field' => $this->applyUpdateField($fields, $operation),
				'move_field' => $this->applyMoveField($fields, $operation),
				default => throw new InvalidArgumentException("Unsupported CRM form field operation: {$type}."),
			};

			$options['data']['fields'] = array_values($fields);
		}

		$options['data']['fields'] = array_values($fields);
		if ($removedFieldNames !== [])
		{
			$options = $this->cleanupRemovedFieldDependencies($options, $removedFieldNames);
		}

		return $options;
	}

	protected function checkOptions(array $options, bool $confirmSynchronization): ?array
	{
		$options = $this->normalizeOptionsForCrmSave($options);
		$controller = new CrmFormController();
		$check = $controller->checkAction($options);
		$this->assertControllerHasNoErrors($controller, 'CRM form fields check failed.');

		$sync = is_array($check) ? ($check['sync'] ?? []) : [];
		$errors = array_values(array_filter($sync['errors'] ?? []));
		if ($errors !== [])
		{
			return [
				'success' => false,
				'changed' => false,
				'code' => 'SYNC_ERROR',
				'message' => 'CRM form fields synchronization failed.',
				'sync' => [
					'errors' => $errors,
					'fields' => $this->serializeFields(array_values(array_filter($sync['fields'] ?? [], 'is_array'))),
				],
			];
		}

		$syncFields = array_values(array_filter($sync['fields'] ?? [], 'is_array'));
		if ($syncFields !== [] && !$confirmSynchronization)
		{
			return [
				'success' => false,
				'changed' => false,
				'code' => 'SYNC_CONFIRMATION_REQUIRED',
				'message' => 'CRM form fields synchronization requires explicit confirmation.',
				'sync' => [
					'errors' => [],
					'fields' => $this->serializeFields($syncFields),
				],
			];
		}

		return null;
	}

	protected function saveOptions(array $options): array
	{
		$options = $this->normalizeOptionsForCrmSave($options);
		$controller = new CrmFormController();
		$savedOptions = $controller->saveAction($options);
		$this->assertControllerHasNoErrors($controller, 'CRM form fields save failed.');

		if (!is_array($savedOptions) || (int)($savedOptions['id'] ?? 0) <= 0)
		{
			throw new RuntimeException('CRM form fields save returned an invalid response.');
		}

		return $savedOptions;
	}

	protected function normalizeOptionsForCrmSave(array $options): array
	{
		if (!isset($options['data']['fields']) || !is_array($options['data']['fields']))
		{
			return $options;
		}

		foreach ($options['data']['fields'] as $index => $field)
		{
			if (!is_array($field))
			{
				continue;
			}

			$type = (string)($field['type'] ?? '');
			if (in_array($type, ['section', 'page'], true))
			{
				continue;
			}

			$placeholder = $field['placeholder'] ?? $field['PLACEHOLDER'] ?? '';
			$placeholder = is_scalar($placeholder) ? (string)$placeholder : '';
			$field['placeholder'] = $placeholder;
			$field['PLACEHOLDER'] = $placeholder;

			$options['data']['fields'][$index] = $field;
		}

		return $options;
	}

	protected function buildConflictResponse(int $formId, array $options, string $currentHash): array
	{
		return [
			'success' => false,
			'changed' => false,
			'code' => 'FIELDS_CONFLICT',
			'message' => 'CRM form fields were changed after the snapshot was loaded. Reload fields and retry with a fresh fieldsHash.',
			'formId' => $formId,
			'formName' => (string)($options['name'] ?? ''),
			'fieldsHash' => $currentHash,
			'fields' => $this->serializeFields($this->getOptionsFields($options)),
			'warnings' => [$this->buildGlobalFormWarning($formId, (string)($options['name'] ?? ''))],
		];
	}

	protected function getLayoutTypes(): array
	{
		return self::LAYOUT_TYPES;
	}

	private function applyAddExistingField(array $options, array $fields, array $operation): array
	{
		$fieldName = trim((string)($operation['fieldName'] ?? ''));
		if ($fieldName === '')
		{
			throw new InvalidArgumentException('fieldName is required for add_existing_field.');
		}

		if ($this->findFieldIndexByName($fields, $fieldName) !== null)
		{
			throw new InvalidArgumentException("CRM form field {$fieldName} already exists in this form.");
		}

		if (!$this->isFieldNameAvailable($options, $fieldName))
		{
			throw new InvalidArgumentException("CRM field {$fieldName} is not available for CRM forms.");
		}

		$field = $this->prepareNewField($options, ['name' => $fieldName]);
		$field = $this->applyPatchToField($field, $operation['patch'] ?? null);

		return $this->insertFieldAtPosition($fields, $field, $operation['position'] ?? null);
	}

	private function applyAddLayoutField(array $options, array $fields, array $operation): array
	{
		$layoutType = trim((string)($operation['layoutType'] ?? $operation['fieldName'] ?? ''));
		if (!in_array($layoutType, self::LAYOUT_TYPES, true))
		{
			throw new InvalidArgumentException('layoutType must be one of: ' . implode(', ', self::LAYOUT_TYPES) . '.');
		}

		$field = $this->prepareNewField($options, ['type' => $layoutType]);
		$field = $this->applyPatchToField($field, $operation['patch'] ?? null);

		return $this->insertFieldAtPosition($fields, $field, $operation['position'] ?? null);
	}

	private function applyRemoveField(array $fields, array $operation, array &$removedFieldNames): array
	{
		$index = $this->findFieldIndex($fields, $operation);
		$removedFieldName = (string)($fields[$index]['name'] ?? '');
		if ($removedFieldName !== '')
		{
			$removedFieldNames[] = $removedFieldName;
		}

		unset($fields[$index]);

		return array_values($fields);
	}

	private function applyUpdateField(array $fields, array $operation): array
	{
		if (!isset($operation['patch']) || !is_array($operation['patch']) || $operation['patch'] === [])
		{
			throw new InvalidArgumentException('patch is required for update_field.');
		}

		$index = $this->findFieldIndex($fields, $operation);
		$fields[$index] = $this->applyPatchToField($fields[$index], $operation['patch']);

		return array_values($fields);
	}

	private function applyMoveField(array $fields, array $operation): array
	{
		$index = $this->findFieldIndex($fields, $operation);
		if (!isset($operation['position']) || filter_var($operation['position'], FILTER_VALIDATE_INT) === false)
		{
			throw new InvalidArgumentException('position is required for move_field.');
		}

		$field = $fields[$index];
		unset($fields[$index]);

		return $this->insertFieldAtPosition(array_values($fields), $field, (int)$operation['position']);
	}

	private function prepareNewField(array $options, array $fieldDefinition): array
	{
		$options['data']['fields'] = [];
		$controller = new CrmFormController();
		$preparedOptions = $controller->prepareAction($options, ['fields' => [$fieldDefinition]]);
		$this->assertControllerHasNoErrors($controller, 'CRM form field prepare failed.');

		if (!is_array($preparedOptions))
		{
			throw new RuntimeException('CRM form field prepare returned an invalid response.');
		}

		$preparedFields = $this->getOptionsFields($preparedOptions);
		if ($preparedFields === [])
		{
			throw new RuntimeException('CRM form field was not prepared.');
		}

		$expectedName = (string)($fieldDefinition['name'] ?? '');
		if ($expectedName !== '')
		{
			foreach ($preparedFields as $field)
			{
				if ((string)($field['name'] ?? '') === $expectedName)
				{
					return $field;
				}
			}
		}

		$expectedType = (string)($fieldDefinition['type'] ?? '');
		if ($expectedType !== '')
		{
			foreach ($preparedFields as $field)
			{
				if ((string)($field['type'] ?? '') === $expectedType)
				{
					return $field;
				}
			}
		}

		return $preparedFields[array_key_last($preparedFields)];
	}

	private function insertFieldAtPosition(array $fields, array $field, mixed $position): array
	{
		$position = filter_var($position, FILTER_VALIDATE_INT);
		if ($position === false || $position < 1)
		{
			$position = count($fields) + 1;
		}

		$index = min((int)$position - 1, count($fields));
		array_splice($fields, $index, 0, [$field]);

		return array_values($fields);
	}

	private function applyPatchToField(array $field, mixed $patch): array
	{
		if ($patch === null)
		{
			return $field;
		}

		if (!is_array($patch))
		{
			throw new InvalidArgumentException('patch must be an object.');
		}

		$unsupported = array_diff(array_keys($patch), self::PATCHABLE_FIELDS);
		if ($unsupported !== [])
		{
			throw new InvalidArgumentException('Unsupported field patch properties: ' . implode(', ', $unsupported) . '.');
		}

		foreach ($patch as $key => $value)
		{
			switch ($key)
			{
				case 'label':
				case 'placeholder':
				case 'hint':
					$field[$key] = is_scalar($value) ? (string)$value : '';
					break;
				case 'required':
				case 'multiple':
				case 'hintOnFocus':
				case 'autocomplete':
					$field[$key] = (bool)$value;
					break;
				case 'value':
					$field[$key] = is_scalar($value) || $value === null ? $value : '';
					break;
				case 'valueType':
					$field = $this->applyValueTypePatch($field, $value);
					break;
				case 'items':
					if (!in_array((string)($field['type'] ?? ''), self::LIST_LIKE_TYPES, true))
					{
						throw new InvalidArgumentException('items can be changed only for list, radio, or checkbox fields.');
					}
					$field[$key] = $this->normalizeItemsPatch($value);
					break;
			}
		}

		return $field;
	}

	private function normalizeItemsPatch(mixed $items): array
	{
		if (!is_array($items))
		{
			throw new InvalidArgumentException('items must be an array.');
		}
		if (!$this->isList($items))
		{
			throw new InvalidArgumentException('items must be a list of objects.');
		}

		$result = [];
		foreach ($items as $index => $item)
		{
			if (!is_array($item) || !$this->isAssociativeArray($item))
			{
				throw new InvalidArgumentException("items[{$index}] must be an object.");
			}

			$unsupported = array_diff(array_keys($item), ['value', 'label', 'selected', 'disabled']);
			if ($unsupported !== [])
			{
				throw new InvalidArgumentException(
					"Unsupported items[{$index}] properties: " . implode(', ', $unsupported) . '.',
				);
			}

			if (!array_key_exists('value', $item) || !is_scalar($item['value']))
			{
				throw new InvalidArgumentException("items[{$index}].value is required and must be a string, number, or boolean.");
			}
			if (!array_key_exists('label', $item) || !is_string($item['label']))
			{
				throw new InvalidArgumentException("items[{$index}].label is required and must be a string.");
			}

			$normalizedItem = [
				'value' => $item['value'],
				'label' => $item['label'],
			];

			foreach (['selected', 'disabled'] as $booleanKey)
			{
				if (!array_key_exists($booleanKey, $item))
				{
					continue;
				}
				if (!is_bool($item[$booleanKey]))
				{
					throw new InvalidArgumentException("items[{$index}].{$booleanKey} must be a boolean.");
				}

				$normalizedItem[$booleanKey] = $item[$booleanKey];
			}

			$result[] = $normalizedItem;
		}

		return $result;
	}

	private function applyValueTypePatch(array $field, mixed $value): array
	{
		$valueType = is_scalar($value) ? trim((string)$value) : '';
		if ($valueType === '')
		{
			throw new InvalidArgumentException('valueType must be a non-empty string.');
		}

		$allowedValueTypes = $this->getAllowedValueTypes($field);
		if ($allowedValueTypes === [])
		{
			throw new InvalidArgumentException('valueType can be changed only for fields with valueTypes.');
		}
		if (!in_array($valueType, $allowedValueTypes, true))
		{
			throw new InvalidArgumentException('valueType must be one of: ' . implode(', ', $allowedValueTypes) . '.');
		}

		if (!isset($field['editing']) || !is_array($field['editing']))
		{
			$field['editing'] = [];
		}
		if (!isset($field['editing']['editable']) || !is_array($field['editing']['editable']))
		{
			$field['editing']['editable'] = [];
		}

		$field['editing']['editable']['valueType'] = $valueType;
		unset($field['valueType']);

		return $field;
	}

	private function getAllowedValueTypes(array $field): array
	{
		$valueTypes = $field['editing']['valueTypes'] ?? [];
		if (!is_array($valueTypes))
		{
			return [];
		}

		$result = [];
		foreach ($valueTypes as $valueType)
		{
			if (!is_array($valueType))
			{
				continue;
			}

			$id = $valueType['id'] ?? $valueType['ID'] ?? null;
			if (is_scalar($id) && (string)$id !== '')
			{
				$result[] = (string)$id;
			}
		}

		return array_values(array_unique($result));
	}

	private function findFieldIndex(array $fields, array $operation): int
	{
		$fieldId = filter_var($operation['fieldId'] ?? null, FILTER_VALIDATE_INT);
		if ($fieldId !== false && $fieldId > 0)
		{
			foreach ($fields as $index => $field)
			{
				if ((int)($field['editing']['id'] ?? $field['id'] ?? 0) === (int)$fieldId)
				{
					return (int)$index;
				}
			}
		}

		$fieldName = trim((string)($operation['fieldName'] ?? ''));
		if ($fieldName !== '')
		{
			$index = $this->findFieldIndexByName($fields, $fieldName);
			if ($index !== null)
			{
				return $index;
			}
		}

		throw new InvalidArgumentException('CRM form field not found for the requested operation.');
	}

	private function findFieldIndexByName(array $fields, string $fieldName): ?int
	{
		foreach ($fields as $index => $field)
		{
			if ((string)($field['name'] ?? '') === $fieldName)
			{
				return (int)$index;
			}
		}

		return null;
	}

	private function isFieldNameAvailable(array $options, string $fieldName): bool
	{
		$tree = $this->getAddableAvailableFieldsTree($this->getAvailableFieldsPresetId($options));

		foreach ($tree as $category)
		{
			foreach (($category['FIELDS'] ?? []) as $field)
			{
				if (
					is_array($field)
					&& $this->isSupportedAvailableField($field)
					&& (string)($field['name'] ?? '') === $fieldName
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	private function isSupportedAvailableField(array $field): bool
	{
		$name = (string)($field['name'] ?? '');
		$type = (string)($field['type'] ?? '');

		return !in_array($name, ['PRODUCT', 'BOOKING_BOOKING'], true)
			&& !in_array($type, ['product', 'booking', 'resourcebooking'], true)
		;
	}

	private function cleanupRemovedFieldDependencies(array $options, array $removedFieldNames): array
	{
		if (!isset($options['data']['dependencies']) || !is_array($options['data']['dependencies']))
		{
			return $options;
		}

		$removed = array_flip($removedFieldNames);
		$dependencies = [];
		foreach ($options['data']['dependencies'] as $dependency)
		{
			if (!is_array($dependency))
			{
				if (!$this->dependencyReferencesRemovedField($dependency, $removed))
				{
					$dependencies[] = $dependency;
				}

				continue;
			}

			if (isset($dependency['list']) && is_array($dependency['list']))
			{
				$dependency['list'] = array_values(array_filter(
					$dependency['list'],
					fn(mixed $rule): bool => !$this->dependencyReferencesRemovedField($rule, $removed),
				));

				if ($dependency['list'] !== [])
				{
					$dependencies[] = $dependency;
				}

				continue;
			}

			if (!$this->dependencyReferencesRemovedField($dependency, $removed))
			{
				$dependencies[] = $dependency;
			}
		}

		$options['data']['dependencies'] = $dependencies;

		return $options;
	}

	private function dependencyReferencesRemovedField(mixed $value, array $removedFieldNames): bool
	{
		if (is_string($value))
		{
			return isset($removedFieldNames[$value]);
		}

		if (!is_array($value))
		{
			return false;
		}

		foreach (['field', 'fieldName', 'fieldCode', 'name', 'target'] as $key)
		{
			if (isset($value[$key]) && is_string($value[$key]) && isset($removedFieldNames[$value[$key]]))
			{
				return true;
			}
		}

		foreach ($value as $child)
		{
			if ($this->dependencyReferencesRemovedField($child, $removedFieldNames))
			{
				return true;
			}
		}

		return false;
	}

	private function serializeField(array $field, int $position): array
	{
		$item = array_merge([
			'fieldId' => (int)($field['editing']['id'] ?? $field['id'] ?? 0),
			'position' => $position,
			'name' => (string)($field['name'] ?? ''),
			'type' => (string)($field['type'] ?? ''),
		], $this->getPatchableFieldSnapshot($field));

		if (isset($field['content']) && is_array($field['content']))
		{
			$item['content'] = $field['content'];
		}

		return $item;
	}

	private function getPatchableFieldSnapshot(array $field): array
	{
		return [
			'label' => (string)($field['label'] ?? ''),
			'required' => (bool)($field['required'] ?? false),
			'multiple' => (bool)($field['multiple'] ?? false),
			'placeholder' => (string)($field['placeholder'] ?? ''),
			'hint' => (string)($field['hint'] ?? ''),
			'hintOnFocus' => (bool)($field['hintOnFocus'] ?? false),
			'autocomplete' => (bool)($field['autocomplete'] ?? false),
			'value' => $this->normalizeSnapshotValue($field['value'] ?? null),
			'valueType' => $this->getFieldValueType($field),
			'items' => isset($field['items']) && is_array($field['items']) ? array_values($field['items']) : [],
		];
	}

	private function normalizeFieldForHash(array $field): array
	{
		$patchableSnapshot = $this->getPatchableFieldSnapshot($field);
		$patchableSnapshot['value'] = $this->normalizeHashValue($patchableSnapshot['value']);
		$patchableSnapshot['items'] = $this->normalizeHashValue($patchableSnapshot['items']);

		return array_merge([
			'id' => (int)($field['editing']['id'] ?? $field['id'] ?? 0),
			'name' => (string)($field['name'] ?? ''),
			'type' => (string)($field['type'] ?? ''),
		], $patchableSnapshot);
	}

	private function getFieldValueType(array $field): string
	{
		$valueType = $field['editing']['editable']['valueType'] ?? $field['valueType'] ?? '';

		return is_scalar($valueType) ? (string)$valueType : '';
	}

	private function normalizeSnapshotValue(mixed $value): mixed
	{
		if ($value === null || is_scalar($value))
		{
			return $value;
		}

		if (!is_array($value))
		{
			return null;
		}

		$result = [];
		foreach ($value as $key => $child)
		{
			$result[$key] = $this->normalizeSnapshotValue($child);
		}

		if (!$this->isList($result))
		{
			ksort($result);
		}

		return $result;
	}

	private function normalizeHashValue(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		$result = [];
		foreach ($value as $key => $child)
		{
			$result[$key] = $this->normalizeHashValue($child);
		}

		if (!$this->isList($result))
		{
			ksort($result);
		}

		return $result;
	}

	private function isList(array $value): bool
	{
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}

	private function isAssociativeArray(array $value): bool
	{
		return $value !== [] && !$this->isList($value);
	}

	private function availableFieldMatchesQuery(array $field, string $needle): bool
	{
		foreach (['name', 'caption', 'type', 'entityName', 'entityFieldName'] as $key)
		{
			if (str_contains(mb_strtolower((string)($field[$key] ?? '')), $needle))
			{
				return true;
			}
		}

		return false;
	}

	private function assertControllerHasNoErrors(CrmFormController $controller, string $fallbackMessage): void
	{
		$errors = $controller->getErrors();
		if ($errors === [])
		{
			return;
		}

		$messages = array_map(
			static fn(Error $error): string => trim($error->getMessage()),
			$errors,
		);
		$messages = array_values(array_filter($messages));

		throw new RuntimeException($messages !== [] ? implode(' ', $messages) : $fallbackMessage);
	}

	private function buildGlobalFormWarning(int $formId, string $formName): string
	{
		$name = trim($formName);
		if ($name !== '')
		{
			return "This changes CRM form {$name} (ID {$formId}) itself everywhere it is embedded.";
		}

		return self::WARNING_GLOBAL_FORM_CHANGE;
	}
}
