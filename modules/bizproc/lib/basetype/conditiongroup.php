<?php

namespace Bitrix\Bizproc\BaseType;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup as EngineConditionGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\AiAssistant\ConditionOperatorDictionary;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;
use Bitrix\Main;

/**
 * Class ConditionGroup
 *
 * Standard bizproc field type for the "filter by fields" control. The stored value is the
 * serialized {@see EngineConditionGroup} (`{items:[[{object,field,operator,value}, joiner], ...]}`);
 * this class only changes how the control is rendered (server render + self-bootstrap JS mount)
 * and how the value is extracted from the request.
 *
 * @package Bitrix\Bizproc\BaseType
 */
class ConditionGroup extends Base
{
	// Mirror of the multi-value limits in EntityFilter (bizproc/lib/Activity/Mixins/EntityFilter.php).
	private const MAX_MULTIPLE_CONDITION_VALUES = 500;
	private const MAX_MULTIPLE_CONDITION_VALUE_LENGTH = 255;
	private const MAX_MULTIPLE_CONDITION_JSON_LENGTH = 65535;

	public static function getType()
	{
		return FieldType::CONDITIONGROUP;
	}

	/**
	 * Server render + self-bootstrap script that mounts the JS control from data-config.
	 * Emitted independently of the render mode (by the EntitySelector example); selection
	 * ("выбор выражения вместо значения") is meaningless for a condition tree and is suppressed.
	 */
	protected static function renderControl(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		Main\UI\Extension::load('bizproc.condition');

		$name = static::generateControlName($field);
		$controlId = static::generateControlId($field);

		$settings = $fieldType->getSettings();

		$normalizedValue = static::normalizeStoredValue($value);

		$config = [
			'fields' => $settings['Fields'] ?? [],
			'documentType' => $settings['documentType'] ?? null,
			'value' => $normalizedValue,
			'prefix' => static::resolvePrefix($fieldType),
		];

		// Optional caption (header/collapsed) is a presentation hint from Settings, not part of the value.
		if (isset($settings['caption']) && is_array($settings['caption']))
		{
			$config['caption'] = $settings['caption'];
		}

		// Initial expanded state is a UI hint carried inside the value (ignored by the Engine).
		if (isset($normalizedValue['isExpanded']))
		{
			$config['isExpanded'] = (bool)$normalizedValue['isExpanded'];
		}

		$property = $fieldType->getProperty();

		// initControl needs only Type, the JS mount only Name — embed a slim property so
		// potentially heavy Settings (e.g. the pilot's per-storage field map) don't get
		// duplicated into the HTML (data-config + data-property).
		$slimProperty = [
			'Name' => (string)($property['Name'] ?? ''),
			'Type' => static::getType(),
		];

		$jsParams = [
			'containerId' => $controlId,
			'config' => $config,
			'inputName' => $name,
			'property' => $slimProperty,
		];

		$controlIdJs = \CUtil::JSEscape($controlId);
		$controlIdHtml = htmlspecialcharsbx($controlId);
		$propertyHtml = htmlspecialcharsbx(Main\Web\Json::encode($slimProperty));
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
			<div id="{$controlIdHtml}" data-role="bp-condition-group" data-config='{$jsParamsJson}' data-property="{$propertyHtml}"></div>
HTML;
	}

	public static function renderControlSingle(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		return static::renderControl($fieldType, $field, $value, false, $renderMode);
	}

	public static function renderControlMultiple(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		// The type is always single; the multiple branch would break the control.
		return static::renderControl($fieldType, $field, $value, false, $renderMode);
	}

	/**
	 * Fully overridden extraction (bypasses the scalar base extractValue): assembles a condition
	 * group from the prefixed request arrays ({prefix}field[]/operator[]/value[]/joiner[]/object[])
	 * into the exact positional Engine\ConditionGroup format. Prefix comes from Settings.Prefix.
	 *
	 * @return array {items:[[{object,field,operator,value}, joiner], ...], isExpanded?:bool}
	 */
	public static function extractValueSingle(FieldType $fieldType, array $field, array $request)
	{
		static::cleanErrors();

		$prefix = static::resolvePrefix($fieldType);

		$fields = array_values((array)($request[$prefix . 'field'] ?? []));
		$operators = array_values((array)($request[$prefix . 'operator'] ?? []));
		$values = array_values((array)($request[$prefix . 'value'] ?? []));
		$joiners = array_values((array)($request[$prefix . 'joiner'] ?? []));
		$objects = array_values((array)($request[$prefix . 'object'] ?? []));

		$items = [];
		$valueIndex = 0;

		foreach ($fields as $i => $fieldName)
		{
			if ((string)$fieldName === '')
			{
				// The empty row still occupies one value slot.
				$valueIndex++;
				continue;
			}

			$operator = (string)($operators[$i] ?? '');

			if ($operator === Operator::Between->value)
			{
				$value = [$values[$valueIndex] ?? null, $values[$valueIndex + 1] ?? null];
				$valueIndex++;
			}
			else
			{
				$value = static::unserializeMultiValue($operator, $values[$valueIndex] ?? null);
			}

			$joiner = (($joiners[$i] ?? '') === EngineConditionGroup::JOINER_OR)
				? EngineConditionGroup::JOINER_OR
				: EngineConditionGroup::JOINER_AND;

			$items[] = [
				[
					'object' => (string)($objects[$i] ?? 'Document'),
					'field' => (string)$fieldName,
					'operator' => $operator,
					'value' => $value,
				],
				$joiner,
			];

			$valueIndex++;
		}

		$result = ['items' => $items];
		if (array_key_exists($prefix . 'isExpanded', $request))
		{
			$result['isExpanded'] = ((string)$request[$prefix . 'isExpanded'] !== 'N');
		}

		return $result;
	}

	public static function extractValueMultiple(FieldType $fieldType, array $field, array $request)
	{
		// The type is always single.
		return static::extractValueSingle($fieldType, $field, $request);
	}

	protected static function formatValuePrintable(FieldType $fieldType, $value): string
	{
		$value = static::normalizeStoredValue($value);
		$items = is_array($value['items'] ?? null) ? $value['items'] : [];
		if (!$items)
		{
			return '';
		}

		$result = '';
		$pendingJoiner = null;
		foreach ($items as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$condition = $item[0] ?? null;
			$joiner = $item[1] ?? EngineConditionGroup::JOINER_AND;
			if (!is_array($condition))
			{
				continue;
			}

			$fieldName = (string)($condition['field'] ?? '');
			if ($fieldName === '')
			{
				continue;
			}

			$operator = (string)($condition['operator'] ?? '');
			$conditionValue = $condition['value'] ?? '';
			if (is_array($conditionValue))
			{
				$conditionValue = implode(', ', array_map(static fn($v) => (string)$v, $conditionValue));
			}

			$piece = trim($fieldName . ' ' . $operator . ' ' . (string)$conditionValue);

			if ($result !== '')
			{
				$result .= ' ' . $pendingJoiner . ' ';
			}
			$result .= $piece;

			$pendingJoiner = ($joiner === EngineConditionGroup::JOINER_OR)
				? EngineConditionGroup::JOINER_OR
				: EngineConditionGroup::JOINER_AND;
		}

		return $result;
	}

	/**
	 * Multi-value operators (in/!in) transport their array as a JSON string in a single value slot
	 * (JS serializeConditionValue). Decode it back to an array of scalar strings so the extracted
	 * value matches the Engine\ConditionGroup contract for direct evaluation, not only for the ORM
	 * filter (EntityFilter normalizes there). Mirror of JS unserializeConditionValue and
	 * EntityFilter::unserializeConditionValue: anything that is not a JSON list of scalars stays as is.
	 *
	 * Applies the same size limits as EntityFilter. An oversized payload is left raw (not decoded):
	 * EntityFilter::getOrmFilter re-applies the limits downstream and yields an impossible filter.
	 */
	private static function unserializeMultiValue(string $operator, $rawValue)
	{
		$isMultiValueOperator = (
			$operator === Operator::In->value
			|| $operator === Operator::NotIn->value
		);
		if (!$isMultiValueOperator || !is_string($rawValue) || !str_starts_with(ltrim($rawValue), '['))
		{
			return $rawValue;
		}

		if (mb_strlen($rawValue) > self::MAX_MULTIPLE_CONDITION_JSON_LENGTH)
		{
			return $rawValue;
		}

		try
		{
			$decoded = Main\Web\Json::decode($rawValue);
		}
		catch (\Throwable)
		{
			return $rawValue;
		}

		if (!is_array($decoded) || !array_is_list($decoded))
		{
			return $rawValue;
		}

		if (count($decoded) > self::MAX_MULTIPLE_CONDITION_VALUES)
		{
			return $rawValue;
		}

		$values = [];
		foreach ($decoded as $element)
		{
			if (!is_scalar($element))
			{
				return $rawValue;
			}

			$value = is_bool($element) ? ($element ? '1' : '') : (string)$element;
			if (mb_strlen($value) > self::MAX_MULTIPLE_CONDITION_VALUE_LENGTH)
			{
				return $rawValue;
			}

			$values[] = $value;
		}

		return $values;
	}

	private static function resolvePrefix(FieldType $fieldType): string
	{
		$prefix = (string)($fieldType->getSettings()['Prefix'] ?? '');

		return $prefix === '' ? 'condition_' : $prefix;
	}

	private static function normalizeStoredValue($value): array
	{
		if (is_array($value))
		{
			return $value;
		}

		if (is_string($value) && $value !== '')
		{
			try
			{
				$decoded = Main\Web\Json::decode($value);
			}
			catch (\Throwable)
			{
				$decoded = null;
			}

			if (is_array($decoded))
			{
				return $decoded;
			}
		}

		return ['items' => []];
	}

	public static function getAiSettingType(): SettingType
	{
		return new SettingType(
			name: static::getType(),
			description:
				'The value is a group of conditions, shaped as {"items": [[{"object": ..., "field": ...,'
				. ' "operator": ..., "value": ...}, joiner], ...]}, where joiner is the string "'
				. EngineConditionGroup::JOINER_OR . '" or "' . EngineConditionGroup::JOINER_AND . '" and glues'
				. ' the item to the NEXT one: only the literal "' . EngineConditionGroup::JOINER_OR . '" joins'
				. ' by OR, anything else - another string, a number, an absent joiner - joins by AND. The joiner'
				. ' of the last item is never used. The field is addressed by its stable codes, never by its'
				. ' label.'
				. ' ' . ConditionOperatorDictionary::contractText('the condition', [Operator::Modified]),
		);
	}
}
