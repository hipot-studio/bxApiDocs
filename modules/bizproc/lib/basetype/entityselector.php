<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\BaseType;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\UI\EntitySelector\Entity;
use Bitrix\UI\EntitySelector\Configuration;

Loader::requireModule('ui');

/**
 * Class Text
 * @package Bitrix\Bizproc\BaseType
 */
class EntitySelector extends Base
{
	/**
	 * @return string
	 */
	public static function getType()
	{
		return FieldType::ENTITYSELECTOR;
	}

	public static function extractValueMultiple(FieldType $fieldType, array $field, array $request)
	{
		$name = $field['Field'];
		$value = $request[$name] ?? [];

		if (!is_array($value))
		{
			$value = [$value];
		}

		$value = array_unique($value);
		$request[$name] = $value;

		return parent::extractValueMultiple($fieldType, $field, $request);
	}

	private static function getPreselectedItems(array $value, string $entityId): array
	{
		if (!class_exists(\Bitrix\UI\EntitySelector\Dialog::class) || !$entityId)
		{
			return [];
		}

		$preselectedItems = array_map(
			static fn($item) => [$entityId, $item],
			$value
		);

		$options = [];

		return \Bitrix\UI\EntitySelector\Dialog::getPreselectedItems($preselectedItems, $options)->toArray();
	}

	/**
	 * @param FieldType $fieldType
	 * @param array $field
	 * @param mixed $value
	 * @param bool $allowSelection
	 * @param int $renderMode
	 * @return string
	 */
	protected static function renderControl(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		\Bitrix\Main\UI\Extension::load('bizproc.bp-entity-selector');

		$name = static::generateControlName($field);
		$controlId = static::generateControlId($field);

		$settings = $fieldType->getSettings();
		$options = $fieldType->getOptions();

		$config = [
			'multiple' => $fieldType->isMultiple(),
			'tagMaxWidth' => 400,
			'dialogOptions' => [
				'showAvatars' => false,
				'dropdownMode' => true,
				'compactView' => true,
				'height' => 240,
				'enableSearch' => $settings['enableSearch'] ?? false,
			]
		];

		if (isset($settings['entity']) && is_array($settings['entity']))
		{
			$config['dialogOptions']['entities'][] = static::prepareEntity($settings['entity']);
		}

		if (isset($options) && is_array($options))
		{
			$config['dialogOptions']['items'] = $options;
		}

		if ($value)
		{
			$config['items'] = static::getPreselectedItems((array)$value, ($settings['entity']['id'] ?? ''));
		}

		if (isset($settings['dialogOptions']) && is_array($settings['dialogOptions']))
		{
			$config['dialogOptions'] = array_merge($config['dialogOptions'], $settings['dialogOptions']);
		}

		$property = $fieldType->getProperty();
		$property['Type'] = static::getType();

		$jsParams = [
			'containerId' => $controlId,
			'config' => $config,
			'inputName' => $name,
			'property' => $property,
			'initialValue' => $value,
		];

		$controlIdJs = \CUtil::JSEscape($controlId);
		$controlIdHtml = htmlspecialcharsbx($controlId);
		$propertyHtml = htmlspecialcharsbx(Main\Web\Json::encode($property));
		$jsParamsJson = Main\Web\Json::encode($jsParams);

		return <<<HTML
			<script>
				BX.ready(() => {
					const control = document.getElementById('{$controlIdJs}');
					if (control)
					{
						BX.Bizproc.FieldType.initControl(control.parentNode, JSON.parse(control.dataset.property));
					}
				});
			</script>
			<div id="{$controlIdHtml}" data-role="bp-entity-selector" data-config='{$jsParamsJson}' data-property="{$propertyHtml}"></div>
HTML;
	}

	public static function renderControlMultiple(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		return static::renderControl($fieldType, $field, $value, $allowSelection, $renderMode);
	}

	public static function renderControlSingle(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		return parent::renderControlSingle($fieldType, $field, $value, $allowSelection, $renderMode);
	}

	protected static function prepareEntity(array $entity): array
	{
		if (empty($entity))
		{
			return $entity;
		}

		$booleanFields = [
			'dynamicLoad',
			'dynamicSearch',
			'searchable',
			'showLink'
		];

		return self::convertFieldsToBoolean($entity, $booleanFields);
	}

	private static function convertFieldsToBoolean(array $entity, array $fields): array
	{
		foreach ($fields as $field)
		{
			if (isset($entity[$field]))
			{
				$entity[$field] = \CBPHelper::getBool($entity[$field]);
			}
		}

		return $entity;
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValuePrintable(FieldType $fieldType, $value): string
	{
		$selectedItem = static::getSelectedItem($fieldType, $value);
		if ($selectedItem)
		{
			return (string)($selectedItem['title'] ?? '');
		}

		return '';
	}

	private static function getSelectedItem(FieldType $fieldType, mixed $value): ?array
	{
		if (empty($value))
		{
			return null;
		}

		$settings = $fieldType->getSettings();
		if (!isset($settings['entity']) || !is_array($settings['entity']))
		{
			return null;
		}

		$preparedEntity = static::prepareEntity($settings['entity']);

		$entityOptions = [
			'id' => $preparedEntity['id'],
			'options' => [],
			'searchable' => true,
			'dynamicLoad' => true,
			'dynamicSearch' => true,
			'filters' => [],
		];

		$entity = new Entity($entityOptions);
		$provider = Configuration::getProvider($entity);
		if ($provider)
		{
			$items = $provider->getSelectedItems([$value]);
			if (empty($items))
			{
				return null;
			}

			foreach ($items as $item)
			{
				$item = $item->toArray();
				if ((string)$item['id'] === (string)$value)
				{
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param mixed $value Field value.
	 * @param string $toTypeClass Type class name.
	 * @return null|mixed
	 */
	public static function convertTo(FieldType $fieldType, $value, $toTypeClass)
	{
		/** @var Base $toTypeClass */
		$type = $toTypeClass::getType();
		$selectedItem = static::getSelectedItem($fieldType, $value);

		$originalValue = $value;
		if ($selectedItem && isset($selectedItem['title']))
		{
			$originalValue = $selectedItem['title'];
		}

		$value = match ($type) {
			FieldType::BOOL => in_array(mb_strtolower((string)$value), ['y', 'yes', 'true', '1']) ? 'Y' : 'N',
			FieldType::DOUBLE => (float)str_replace(' ', '', str_replace(',', '.', $value)),
			FieldType::INT => (int)str_replace(' ', '', $value),
			FieldType::STRING,
			FieldType::TEXT => (string)$originalValue,
			FieldType::SELECT,
			FieldType::ENTITYSELECTOR,
			FieldType::INTERNALSELECT => (string)$value,
			FieldType::USER => static::convertToUser((string)$value),
			default => null,
		};

		return $value;
	}

	private static function convertToUser(string $value): ?string
	{
		$trimmed = trim($value);
		if (
			mb_strpos($trimmed, 'user_') === false
			&& mb_strpos($trimmed, 'group_') === false
			&& !preg_match('#^[0-9]+$#', $trimmed)
		)
		{
			$value = null;
		}

		return $value;
	}
}
