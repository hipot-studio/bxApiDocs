<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingKeyMode;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOptionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class AgentSettingValueValidator
{
	/**
	 * How many rejected elements one answer names before it counts the rest. The agent corrects a list from the
	 * positions it is given, and ten of them are enough to see the pattern of what it got wrong.
	 */
	private const MAX_REPORTED_REJECTED_VALUES = 10;

	/**
	 * How many allowed values one answer lists before it counts the rest and points at the catalog instead. This
	 * is the part that used to be repeated per element, so it is the part the bound exists for.
	 */
	private const MAX_REPORTED_ALLOWED_VALUES = 20;

	private string|array|null $expectedTypeValue = null;
	private readonly ?ValidationContext $context;

	public function __construct(?ValidationContext $context = null)
	{
		$this->context = $context;
	}

	/**
	 * A block of the graph is described for the document type of the edited template, so this entry names no
	 * document type of its own and the key carries the mark of the request's own type
	 * ({@see ValidationContext::optionValueFingerprint()}). The nested entry below is where a level names one.
	 *
	 * @param string|null $blockType the type that owns the setting - the block's own type at the top level, the
	 *        sub-action's activity code inside a complex node's rules. Only the carried-value passthrough of
	 *        the option check needs it, and without it that passthrough simply does not apply.
	 * @param string|null $presetId the preset the block is pinned to, the other half of what the option list is
	 *        built from. Carried beside $blockType because the passthrough may only unlock a value inside the
	 *        variant that carried it ({@see ValidationContext::hasCarriedOptionValue()}).
	 */
	public function validate(
		mixed $value,
		string $path,
		?Setting $settingConfig,
		?string $blockType = null,
		?string $presetId = null,
		bool $allowPublishedExpressionKeys = false,
	): Result
	{
		$this->expectedTypeValue = null;
		$valuePath = "{$path}.value";
		if (!is_string($value) && !is_array($value))
		{
			return (new Result())->addError(
				GraphError::at($valuePath, "{$valuePath} is required", GraphErrorCode::SettingValueInvalid),
			);
		}

		if ($settingConfig === null)
		{
			// setting is not described by the schema (e.g. it came from an existing template),
			// keep the value as is so the draft round-trip does not lose it
			$this->expectedTypeValue = $value;

			return new Result();
		}

		if ($settingConfig->type === ActivityAiPropertyConverter::SETTING_TYPE_MAP)
		{
			if ($value === '')
			{
				// The properties dialog writes its whole map on every save and a draft is autosaved half-built, so
				// a map the editor left unfilled reads back as the empty string. That is an empty map, not a type
				// error: refusing it would cost the whole template its way back.
				$value = [];
			}
			elseif (is_string($value))
			{
				$value = $this->getValueAsArray($value);
			}

			if (!is_array($value))
			{
				return (new Result())->addError(GraphError::at(
					$valuePath,
					"{$valuePath} expected type is object",
					GraphErrorCode::SettingValueInvalid,
				));
			}
			$this->expectedTypeValue = $value;

			return $this->validateAgainstChildSchema(
				$value,
				$settingConfig,
				$valuePath,
				$blockType,
				$presetId,
				$allowPublishedExpressionKeys,
			);
		}

		if ((string)$settingConfig->type === FieldType::CONDITIONGROUP)
		{
			return $this->validateConditionGroup($value, $valuePath);
		}

		if (
			!$settingConfig->multiple
			&& is_array($value)
			&& (string)$settingConfig->type === FieldType::USER
		)
		{
			// Bizproc persists a single USER value as a one-element array even without Multiple; coerce it to the
			// scalar the schema expects. Gated on the USER type, so other single-value settings still reject arrays.
			$coerced = $this->coerceSingleUserValue($value);
			if ($coerced === null)
			{
				return (new Result())->addError(GraphError::at(
					"{$path}.value",
					"{$path}.value expected type is string",
					GraphErrorCode::SettingValueInvalid,
				));
			}

			$value = $coerced;
		}

		if (!$settingConfig->multiple && !is_string($value))
		{
			return (new Result())->addError(GraphError::at(
				$valuePath,
				"{$valuePath} expected type is string",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		// Requiredness is not judged here: nothing checks that a required setting was sent at all
		// ({@see AgentBlockSettingsValidator::validate()} refuses only a wholly empty set), so refusing the one
		// that arrives empty would refuse the block the editor autosaved half-built and pass the same block with
		// the setting left out.

		$optionsResult = self::validateAgainstOptions(
			$value,
			$settingConfig,
			$path,
			$this->context,
			$blockType,
			$presetId,
		);
		if (!$optionsResult->isSuccess())
		{
			return $optionsResult;
		}

		$boundsResult = self::validateBounds($value, $settingConfig, $valuePath);
		if (!$boundsResult->isSuccess())
		{
			return $boundsResult;
		}

		$this->expectedTypeValue = $value;

		return new Result();
	}

	/**
	 * Whether a value counts as absent for the checks that judge presence - the requiredness of a setting
	 * (ALG-01 checkRequired) and of a child key (checkMapKeys), and the members of a cross-setting constraint.
	 *
	 * The single account of emptiness, so those three cannot drift apart. Null, the empty string and the empty
	 * collection are empty; `0`, `"0"` and `false` are values a setting legitimately holds, and reading them as
	 * absent would refuse a bool setting written as "off" and a numeric one written as zero.
	 */
	public static function isEmptyValue(mixed $value): bool
	{
		if ($value === null)
		{
			return true;
		}

		if (is_string($value))
		{
			return $value === '';
		}

		return is_array($value) && $value === [];
	}

	/**
	 * The value of a map setting against the child schema the block was described with (ALG-01, checkMapKeys /
	 * checkMapValues / checkRequiredChildren). Until now the map branch above only established that the value is
	 * an array, so the declared children were published to the agent and never held it to anything.
	 *
	 * Which keys a map admits is decided by its key mode alone. A schema that declares no mode leaves the keys
	 * unjudged, exactly as before: the mode is what says how the keys were decided, and guessing it from the
	 * presence of children would refuse a map whose children are a snapshot of the portal rather than a contract.
	 *
	 * The check is not gated on the request source: it follows from the description of the activity, and BRIEF
	 * § 4.4 keeps such consequences on both entrypoints so that one activity is never described twice.
	 */
	private function validateAgainstChildSchema(
		array $value,
		Setting $setting,
		string $path,
		?string $blockType,
		?string $presetId,
		bool $allowPublishedExpressionKeys = false,
	): Result
	{
		$keyMode = $setting->keyMode;
		if ($keyMode === null)
		{
			return new Result();
		}

		if ($keyMode === SettingKeyMode::Dynamic && $setting->children === null)
		{
			// A dynamic map is judged by keys the server computes from a trusted context, and a context that
			// could not be assembled is never read as "any key": that would turn the failure of a provider into
			// a free map (BRIEF § 1.4).
			return (new Result())->addError(GraphError::at(
				$path,
				"{$path} cannot be checked: the server could not resolve the keys of this setting",
				GraphErrorCode::ContextResolutionFailed,
			));
		}

		return $setting->multiple
			? $this->validateObjectList(
				$value,
				$setting->children,
				$keyMode,
				$path,
				$setting->name,
				$blockType,
				$presetId,
				$setting->uniqueChildKey,
			)
			: $this->validateObject(
				$value,
				$setting->children,
				$keyMode,
				$path,
				$setting->name,
				$blockType,
				$presetId,
				$allowPublishedExpressionKeys
					&& strcasecmp($blockType ?? '', 'writedatastorageactivity') === 0
					&& $setting->name === 'FieldValue'
					&& $keyMode === SettingKeyMode::Dynamic,
			);
	}

	/**
	 * A `map` setting that is also `multiple` is a list of objects, and the two shapes it is confused with are
	 * refused by name: an object where a list is declared (a map keyed by anything), and an element of the list
	 * that is not an object at all.
	 */
	private function validateObjectList(
		array $value,
		?SettingCollection $children,
		SettingKeyMode $keyMode,
		string $path,
		string $settingName,
		?string $blockType,
		?string $presetId,
		?string $uniqueChildKey = null,
	): Result
	{
		$result = new Result();

		if (!array_is_list($value))
		{
			return $result->addError(GraphError::at(
				$path,
				"{$path} expected a list of objects, not an object",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		$seenUniqueValues = [];
		foreach ($value as $index => $element)
		{
			$elementPath = "{$path}.{$index}";
			if (!is_array($element))
			{
				$result->addError(GraphError::at(
					$elementPath,
					"{$elementPath} expected type is object",
					GraphErrorCode::SettingValueInvalid,
				));

				continue;
			}

			$result->addErrors($this->validateObject(
				$element,
				$children,
				$keyMode,
				$elementPath,
				$settingName,
				$blockType,
				$presetId,
			)->getErrors());
			$result->addErrors(
				self::validateUniqueChildKey($element, $uniqueChildKey, $elementPath, $seenUniqueValues)->getErrors(),
			);
		}

		return $result;
	}

	/**
	 * The key that tells one element of a list from another, when the schema declares one. Two elements sharing
	 * it are refused rather than collapsed: whichever of them the block ends up acting on, the other one is
	 * silently gone, and the graph was written expecting both.
	 *
	 * Only a scalar is compared - a shape that cannot identify anything is left to the checks that judge the
	 * value itself - and the element that first carried a value keeps it, so the refusal names the later one.
	 *
	 * @param array<string, true> $seenValues carried across the elements of one list
	 */
	private static function validateUniqueChildKey(
		array $element,
		?string $uniqueChildKey,
		string $elementPath,
		array &$seenValues,
	): Result
	{
		$result = new Result();
		if ($uniqueChildKey === null)
		{
			return $result;
		}

		$value = $element[$uniqueChildKey] ?? null;
		if (!is_scalar($value) || (string)$value === '')
		{
			return $result;
		}

		$value = (string)$value;
		$keyPath = "{$elementPath}.{$uniqueChildKey}";
		if (isset($seenValues[$value]))
		{
			return $result->addError(GraphError::at(
				$keyPath,
				"{$keyPath} is not unique: another element of this setting already carries \"{$value}\"",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		$seenValues[$value] = true;

		return $result;
	}

	/**
	 * The keys and the values of one object of a map setting - the map itself when the setting holds one, an
	 * element of the list when it holds several.
	 */
	private function validateObject(
		array $object,
		?SettingCollection $children,
		SettingKeyMode $keyMode,
		string $path,
		string $settingName,
		?string $blockType,
		?string $presetId,
		bool $allowPublishedExpressionKeys = false,
	): Result
	{
		if ($keyMode === SettingKeyMode::Open)
		{
			return self::validateFreeObject($object, $path);
		}

		// A closed map admits its declared children and nothing else, so children the description does not carry
		// mean no key is admitted rather than every key: an empty allowed set is a legal state of such a map
		// (BRIEF § 1.4), and reading it as "unknown" is what would turn a closed map into a free one.
		$allowedKeys = $children?->getNames() ?? [];
		$details = [
			'allowedKeys' => $allowedKeys,
			'requiredKeys' => self::requiredChildNames($children),
		];

		$result = new Result();
		$hasPublishedExpressionKey = false;
		foreach (array_keys($object) as $key)
		{
			$keyPath = "{$path}.{$key}";

			$keyShapeError = self::keyShapeError($key, $keyPath);
			if ($keyShapeError !== null)
			{
				$result->addError($keyShapeError);

				continue;
			}

			if ($allowPublishedExpressionKeys && \CBPActivity::isExpression($key))
			{
				$hasPublishedExpressionKey = true;

				continue;
			}

			// Matched exactly as a top-level name is matched against the schema
			// ({@see \Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection::findFirstByName()}), so one
			// spelling of a key gets one verdict wherever it is read.
			if (!in_array($key, $allowedKeys, true))
			{
				$result->addError(GraphError::at(
					$keyPath,
					"{$keyPath} is not a key of this setting",
					GraphErrorCode::MapKeyUnknown,
					$details,
				));

				continue;
			}

			$child = $children?->findFirstByName($key);
			$result->addErrors($this->validateChildValue(
				$object[$key],
				$child,
				$keyMode,
				$keyPath,
				$settingName,
				$blockType,
				$presetId,
			)->getErrors());
		}

		// The runtime resolves the key; the validator cannot infer which required column it supplies.
		return $hasPublishedExpressionKey
			? $result
			: $result->addErrors(self::validateRequiredChildren($object, $children, $path, $details)->getErrors());
	}

	/**
	 * The value of one child of a map against what the child's own schema declares - the set of variants it
	 * published and the bounds of a number. Until now a map was held to its keys alone, so a column of a storage
	 * could carry `type: "nosuchtype"`: the key was declared, the value was anything, and the refusal arrived
	 * while the process ran (BRIEF § 3.1). Like the key check, this follows from the description of the activity
	 * and holds on both entrypoints (BRIEF § 4.4).
	 *
	 * Only a CLOSED map is judged this way, for the same reason the keys are judged only where a mode says how
	 * they were decided: the children of a closed map are written by the activity itself and their variants are a
	 * contract, while the children of a dynamic map are a snapshot of the portal - there the "options" of a child
	 * are whatever the provider of the field put there, and holding a written value to them would refuse the very
	 * substitutions the block asks the agent for.
	 *
	 * The passthrough of a value the published version carries reaches this level too, keyed by the setting and the
	 * key inside it ({@see ValidationContext::childSettingName()}): a variant list is a snapshot of the portal at
	 * this level exactly as it is at the top one, so a column of a storage whose type the portal has stopped
	 * offering would otherwise stop the template from being sent back unchanged.
	 */
	private function validateChildValue(
		mixed $value,
		?Setting $child,
		SettingKeyMode $keyMode,
		string $keyPath,
		string $settingName,
		?string $blockType,
		?string $presetId,
	): Result
	{
		$result = new Result();
		if ($child === null || $keyMode !== SettingKeyMode::Closed)
		{
			return $result;
		}

		$result->addErrors(self::validateOptionsAt(
			$value,
			$child,
			$keyPath,
			$this->context,
			$blockType,
			$presetId,
			carriedSettingName: ValidationContext::childSettingName($settingName, $child->name),
		)->getErrors());

		return $result->addErrors(self::validateBounds($value, $child, $keyPath)->getErrors());
	}

	/**
	 * A number against the bounds its schema publishes (BRIEF § 1.2). The bounds were serialised into the catalog
	 * and read by nobody, so `MaxLevel: 99` on the block that climbs the department tree passed the check and
	 * then climbed as far as the tree goes.
	 *
	 * Only a value that actually holds a number is judged. An empty one is the business of requiredness, an
	 * expression is a number only once the process runs, and a value of a shape the setting cannot hold is the
	 * business of the type checks - answering all three here would turn the bounds into a second, and different,
	 * account of what the value may be.
	 */
	private static function validateBounds(mixed $value, Setting $setting, string $valuePath): Result
	{
		$result = new Result();
		if ($setting->minimum === null && $setting->maximum === null)
		{
			return $result;
		}

		foreach (is_array($value) ? $value : [$value] as $item)
		{
			$number = self::numberOf($item);
			if ($number === null)
			{
				continue;
			}

			if (
				($setting->minimum !== null && $number < $setting->minimum)
				|| ($setting->maximum !== null && $number > $setting->maximum)
			)
			{
				return $result->addError(GraphError::at(
					$valuePath,
					"{$valuePath} is {$item}, outside the bounds this setting declares: "
					. self::describeBounds($setting),
					GraphErrorCode::SettingValueOutOfBounds,
				));
			}
		}

		return $result;
	}

	private static function describeBounds(Setting $setting): string
	{
		$bounds = [];
		if ($setting->minimum !== null)
		{
			$bounds[] = "at least {$setting->minimum}";
		}
		if ($setting->maximum !== null)
		{
			$bounds[] = "at most {$setting->maximum}";
		}

		return implode(', ', $bounds);
	}

	/**
	 * The number a value holds, or null when it holds none. A number sent as a JSON number and the same number
	 * sent as a string are one value here - the core leaves a JSON number unconverted, so both shapes reach this
	 * contour for the very same setting.
	 */
	private static function numberOf(mixed $value): int|float|null
	{
		if (is_int($value) || is_float($value))
		{
			return $value;
		}

		return is_string($value) && is_numeric($value) ? $value + 0 : null;
	}

	/**
	 * The children a map declares required, against one object of its value (ALG-01 checkRequiredChildren). A
	 * child the description publishes a default for counts as present: the schema answers for it, so demanding
	 * it of the agent would refuse a value the block is described as able to fill in itself.
	 *
	 * @param array{allowedKeys: list<string>, requiredKeys: list<string>} $details
	 */
	private static function validateRequiredChildren(
		array $object,
		?SettingCollection $children,
		string $path,
		array $details,
	): Result
	{
		$result = new Result();
		if ($children === null)
		{
			return $result;
		}

		foreach ($children as $child)
		{
			if (!$child->required)
			{
				continue;
			}

			$value = array_key_exists($child->name, $object) ? $object[$child->name] : $child->defaultValue;
			if (self::isEmptyValue($value))
			{
				$keyPath = "{$path}.{$child->name}";
				$result->addError(GraphError::at(
					$keyPath,
					"{$keyPath} is required",
					GraphErrorCode::MapKeyRequired,
					$details,
				));
			}
		}

		return $result;
	}

	/**
	 * A map whose keys are free: the author names them, so nothing is unknown here, but a key still has to be a
	 * name and a value still has to be readable as one. A nested structure is refused rather than flattened -
	 * the consumers of such a map read a scalar per key, and a silently kept nesting reaches them as the literal
	 * 'Array'.
	 */
	private static function validateFreeObject(array $object, string $path): Result
	{
		$result = new Result();

		foreach ($object as $key => $value)
		{
			$keyPath = "{$path}.{$key}";

			$keyShapeError = self::keyShapeError($key, $keyPath);
			if ($keyShapeError !== null)
			{
				$result->addError($keyShapeError);

				continue;
			}

			if (is_array($value) || is_object($value))
			{
				$result->addError(GraphError::at(
					$keyPath,
					"{$keyPath} should be a scalar value: nested objects and lists are not allowed here",
					GraphErrorCode::MapValueNested,
				));
			}
		}

		return $result;
	}

	/**
	 * The shape every key of a map owes, whichever rule decides which keys it may carry. Kept in one place so
	 * the free map and the closed one cannot answer the same malformed key with two different texts.
	 */
	private static function keyShapeError(mixed $key, string $keyPath): ?Error
	{
		return is_string($key) && $key !== ''
			? null
			: GraphError::at(
				$keyPath,
				"{$keyPath} is not a valid key: a key should be a not empty string",
				GraphErrorCode::SettingValueInvalid,
			)
		;
	}

	/**
	 * @return list<string>
	 */
	private static function requiredChildNames(?SettingCollection $children): array
	{
		$names = [];
		foreach ($children ?? [] as $child)
		{
			if ($child->required)
			{
				$names[] = $child->name;
			}
		}

		return $names;
	}

	/**
	 * The value of a setting typed as a group of conditions - the filter that says which records of a storage a
	 * node reads or deletes. The shape reaches the agent with the type itself
	 * ({@see \Bitrix\Bizproc\BaseType\ConditionGroup::getAiSettingType()}) and is held to here, because nothing
	 * downstream holds it to anything: the engine takes the field and the object by string
	 * ({@see \Bitrix\Bizproc\Automation\Engine\Condition::setField()}) and drops whatever it cannot read, so a
	 * value of another shape either raises while the process runs or silently selects by fewer conditions than
	 * the graph was written with.
	 *
	 * The empty string and the empty array are an empty filter rather than a type error, for the reason the map
	 * branch spells out: a draft is autosaved half-built, and a filter the dialog left unfilled reads back as
	 * the empty string.
	 */
	private function validateConditionGroup(string|array $value, string $valuePath): Result
	{
		if ($value === '')
		{
			$value = [];
		}
		elseif (is_string($value))
		{
			$value = $this->getValueAsArray($value);
		}

		if (!is_array($value))
		{
			return (new Result())->addError(GraphError::at(
				$valuePath,
				"{$valuePath} expected type is object",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		$result = self::validateConditionGroupShape($value, $valuePath);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->expectedTypeValue = $value;

		return $result;
	}

	/**
	 * The two keys the group is read by: the type of comparison and the list of conditions. A key outside them
	 * is left alone - the engine reads no other one, the dialog writes `isExpanded` of its own, and refusing
	 * what the engine ignores would refuse a value the editor saves.
	 */
	private static function validateConditionGroupShape(array $value, string $path): Result
	{
		$result = new Result();

		$allowedTypes = [ConditionGroup::TYPE_FIELD, ConditionGroup::TYPE_MIXED];
		$type = $value['type'] ?? null;
		if ($type !== null && !in_array($type, $allowedTypes, true))
		{
			$result->addError(GraphError::at(
				"{$path}.type",
				"{$path}.type is not valid. Allowed values: " . implode(', ', $allowedTypes),
				GraphErrorCode::SettingValueInvalid,
			));
		}

		$items = $value['items'] ?? null;
		if ($items === null)
		{
			return $result;
		}

		if (!is_array($items) || !array_is_list($items))
		{
			return $result->addError(GraphError::at(
				"{$path}.items",
				"{$path}.items expected a list of [condition, joiner] pairs",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		foreach ($items as $index => $item)
		{
			$result->addErrors(self::validateConditionItem($item, "{$path}.items.{$index}")->getErrors());
		}

		return $result;
	}

	/**
	 * One element of the list: the condition and the joiner that glues it to the NEXT element, positionally and
	 * in this order ({@see ConditionGroup::__construct()} destructures the pair). A pair of another shape is
	 * dropped there rather than reported, and a filter short of a condition selects more records than the graph
	 * asked for - which at a delete node in the multiple mode is a deletion of another record.
	 */
	private static function validateConditionItem(mixed $item, string $itemPath): Result
	{
		$result = new Result();

		if (!is_array($item) || !array_is_list($item) || count($item) !== 2)
		{
			return $result->addError(GraphError::at(
				$itemPath,
				"{$itemPath} expected a pair of [condition, joiner]",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		[$condition, $joiner] = $item;

		if (is_array($condition))
		{
			$result->addErrors(self::validateCondition($condition, "{$itemPath}.0")->getErrors());
		}
		else
		{
			$result->addError(GraphError::at(
				"{$itemPath}.0",
				"{$itemPath}.0 expected type is object",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		if (!is_string($joiner))
		{
			$result->addError(GraphError::at(
				"{$itemPath}.1",
				"{$itemPath}.1 expected type is string: the joiner of this condition with the next one",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		return $result;
	}

	/**
	 * The condition itself: the field it addresses, the object that field belongs to, the operator and the
	 * value compared with. Which fields a storage admits is not judged here - the set is the columns of the
	 * storage plus the service fields every one of them carries, the runtime refuses a field outside it by
	 * selecting nothing, and a false refusal on a working graph costs more than a filter that selects nothing.
	 *
	 * The operator is judged, and only for a condition the engine would take at all (a non-empty `field`):
	 * a code outside the dictionary is refused by nothing else - neither saving nor running - and the filter
	 * simply drops the line and widens the selection
	 * ({@see \Bitrix\Bizproc\Activity\Mixins\EntityFilter::getOrmFilter()}).
	 */
	private static function validateCondition(array $condition, string $path): Result
	{
		$result = new Result();

		foreach (['object', 'field', 'operator'] as $key)
		{
			$member = $condition[$key] ?? null;
			if ($member !== null && !is_string($member))
			{
				$result->addError(GraphError::at(
					"{$path}.{$key}",
					"{$path}.{$key} expected type is string",
					GraphErrorCode::SettingValueInvalid,
				));
			}
		}

		$field = $condition['field'] ?? null;
		$operator = $condition['operator'] ?? null;
		if (is_string($field) && $field !== '' && !in_array($operator, self::conditionOperators(), true))
		{
			$result->addError(GraphError::at(
				"{$path}.operator",
				"{$path}.operator is not valid. Allowed values: " . implode(', ', self::conditionOperators()),
				GraphErrorCode::SettingValueInvalid,
			));
		}

		return $result->addErrors(
			self::validateConditionValue($condition['value'] ?? null, "{$path}.value")->getErrors(),
		);
	}

	/**
	 * What the field is compared with: one value, or a list of them for the operators that take a set or a
	 * range ({@see \Bitrix\Bizproc\Activity\Mixins\EntityFilter::sanitizeMultipleConditionValue()}). Anything
	 * nested is refused: the runtime walks the whole value substituting expressions
	 * ({@see \CBPActivity::getPropertyValueRecursive()}) and what it cannot substitute reaches the query as the
	 * literal 'Array'.
	 */
	private static function validateConditionValue(mixed $value, string $valuePath): Result
	{
		$result = new Result();

		if ($value === null || is_scalar($value))
		{
			return $result;
		}

		if (!is_array($value) || !array_is_list($value))
		{
			return $result->addError(GraphError::at(
				$valuePath,
				"{$valuePath} expected a value or a list of values",
				GraphErrorCode::SettingValueInvalid,
			));
		}

		foreach ($value as $index => $item)
		{
			if (!is_scalar($item))
			{
				$elementPath = "{$valuePath}.{$index}";
				$result->addError(GraphError::at(
					$elementPath,
					"{$elementPath} expected a value: a list of values holds no nested ones",
					GraphErrorCode::SettingValueInvalid,
				));
			}
		}

		return $result;
	}

	/**
	 * The operators a condition of a filter may carry - the dictionary published with the type
	 * ({@see \Bitrix\Bizproc\Integration\AiAssistant\ConditionOperatorDictionary}) minus `modified`, which this
	 * consumer cannot honour: the filter drops such a line and thereby widens the selection. Taken from the
	 * enum rather than written out, so an operator the portal adds is admitted by the same list that describes
	 * it.
	 *
	 * @return list<string>
	 */
	private static function conditionOperators(): array
	{
		$codes = [];
		foreach (Operator::cases() as $operator)
		{
			if ($operator !== Operator::Modified)
			{
				$codes[] = $operator->value;
			}
		}

		return $codes;
	}

	/**
	 * The declared-options branch of the value check on its own, so a caller that cannot run the whole check
	 * still holds the agent to the option set the schema published to it. The nested settings of a complex
	 * node's sub-action are such a caller ({@see AgentComplexRulesValidator::validateNestedSettingNames()}):
	 * there the shape checks above do not run, but the options are the same contract.
	 *
	 * $context and $blockType together enable the carried-value passthrough; omitting either holds every
	 * value to the current option list. $presetId and $documentType qualify the block type with the variant and the
	 * document type the list was built for, so a value carried under one of them does not unlock it under another.
	 * $carriedSettingName names the level the passthrough is keyed at when it is not the setting itself - a child of
	 * a map is keyed by its parent and its key ({@see ValidationContext::childSettingName()}).
	 *
	 * @param array|null $documentType the document type the option list was built for, for a level that names one of
	 *        its own - the sub-step of a complex node, described for the effective document type of its host node.
	 *        Null for a block of the graph, described for the document type of the request itself
	 *        ({@see ValidationContext::optionValueFingerprint()}).
	 */
	public static function validateAgainstOptions(
		mixed $value,
		Setting $setting,
		string $path,
		?ValidationContext $context = null,
		?string $blockType = null,
		?string $presetId = null,
		?array $documentType = null,
	): Result
	{
		return self::validateOptionsAt(
			$value,
			$setting,
			"{$path}.value",
			$context,
			$blockType,
			$presetId,
			$documentType,
		);
	}

	/**
	 * The same check addressed at the value itself rather than at the setting holding it, so a level that has no
	 * `.value` of its own - a child of a map - is answered at the key the agent wrote.
	 */
	private static function validateOptionsAt(
		mixed $value,
		Setting $setting,
		string $valuePath,
		?ValidationContext $context = null,
		?string $blockType = null,
		?string $presetId = null,
		?array $documentType = null,
		?string $carriedSettingName = null,
	): Result
	{
		$result = new Result();

		if ($setting->options === null)
		{
			return $result;
		}

		// Keyed by the id rather than listed: the check runs per element of a multiple setting, and a linear
		// search turns that into O(elements x options). Casting the key to int on a numeric id changes nothing -
		// the lookup happens only on a string value, and PHP casts it the same way.
		$validIds = [];
		foreach ($setting->options as $option)
		{
			$validIds[$option->id] = true;
		}

		// A multiple setting holds a list, and skipping the whole check for it left the published option set
		// meaningless exactly where it matters most: a monitored-fields list took any code at all, the graph
		// was accepted, and the block then silently lost the outputs it computes from that setting. Each element
		// is held to the same contract as a single value.
		//
		// Every array is walked, keyed or not. Nothing above rejects a keyed array for a multiple setting, so
		// exempting one here handed the agent the bypass this check exists to close: `{"a": "MADE_UP_CODE"}`
		// passed with nothing examined.
		if (is_array($value))
		{
			return self::validateOptionValueList(
				$value,
				$setting,
				$validIds,
				$valuePath,
				$context,
				$blockType,
				$presetId,
				$documentType,
				$carriedSettingName,
			);
		}

		return self::validateOptionValue(
			$value,
			$setting,
			$validIds,
			$valuePath,
			$context,
			$blockType,
			$presetId,
			$documentType,
			$carriedSettingName,
		);
	}

	/**
	 * Every element of a list against the option set, answered as ONE error for the setting.
	 *
	 * The check stays per element - that is the hole it was written to close - but the answer does not. Naming
	 * the whole allowed set beside every rejected element turned a 1.8 MB request into 42 MB of message text,
	 * and this contour serves a model that regularly sends field codes that do not exist, so the size is reached
	 * by an ordinary mistake rather than by an attack. What is bounded is the retelling: the positions of the
	 * rejected elements up to {@see self::MAX_REPORTED_REJECTED_VALUES} and the allowed set once, up to
	 * {@see self::MAX_REPORTED_ALLOWED_VALUES}.
	 *
	 * @param array<string, true> $validIds
	 */
	private static function validateOptionValueList(
		array $value,
		Setting $setting,
		array $validIds,
		string $valuePath,
		?ValidationContext $context,
		?string $blockType,
		?string $presetId,
		?array $documentType,
		?string $carriedSettingName = null,
	): Result
	{
		$result = new Result();

		$rejectedCount = 0;
		$reported = [];
		foreach ($value as $index => $item)
		{
			if (self::isAllowedOptionValue(
				$item,
				$setting,
				$validIds,
				$context,
				$blockType,
				$presetId,
				$documentType,
				$carriedSettingName,
			))
			{
				continue;
			}

			++$rejectedCount;
			if (count($reported) < self::MAX_REPORTED_REJECTED_VALUES)
			{
				// An element that is not a scalar is named by its shape: `(string)` on it would yield the literal
				// 'Array' plus a PHP warning on every validate/push.
				$reported[] = "[{$index}] " . (is_scalar($item) ? "\"{$item}\"" : get_debug_type($item));
			}
		}

		if ($rejectedCount === 0)
		{
			return $result;
		}

		$hidden = $rejectedCount - count($reported);
		$listed = implode(', ', $reported) . ($hidden > 0 ? " and {$hidden} more" : '');

		$result->addError(GraphError::at(
			$valuePath,
			"{$valuePath} is not valid. Elements outside the allowed set ({$rejectedCount}): {$listed}."
			. ' ' . self::describeAllowedValues($validIds),
			GraphErrorCode::SettingValueInvalid,
		));

		return $result;
	}

	/**
	 * The allowed set for an answer that names it once. Beyond the bound the answer stops listing and says where
	 * the whole set lives instead, so the agent can still correct the graph from one round.
	 *
	 * @param array<string, true> $validIds
	 */
	private static function describeAllowedValues(array $validIds): string
	{
		$ids = array_keys($validIds);
		$listed = array_slice($ids, 0, self::MAX_REPORTED_ALLOWED_VALUES);
		$hidden = count($ids) - count($listed);

		$text = 'Allowed values (' . count($ids) . '): ' . implode(', ', $listed);

		return $hidden > 0
			? $text . " and {$hidden} more."
				. ' Read the whole set from the "options" of this setting in the block catalog.'
			: $text . '.'
		;
	}

	/**
	 * One value against the published option set, phrased for a setting that holds a single value.
	 *
	 * @param array<string, true> $validIds
	 */
	private static function validateOptionValue(
		mixed $value,
		Setting $setting,
		array $validIds,
		string $path,
		?ValidationContext $context = null,
		?string $blockType = null,
		?string $presetId = null,
		?array $documentType = null,
		?string $carriedSettingName = null,
	): Result
	{
		$result = new Result();

		if (self::isAllowedOptionValue(
			$value,
			$setting,
			$validIds,
			$context,
			$blockType,
			$presetId,
			$documentType,
			$carriedSettingName,
		))
		{
			return $result;
		}

		$result->addError(GraphError::at(
			$path,
			"{$path} is not valid. Allowed values: " . implode(', ', array_keys($validIds)),
			GraphErrorCode::SettingValueInvalid,
		));

		return $result;
	}

	/**
	 * The verdict on one value, kept apart from the wording so that a single value and an element of a list are
	 * judged by the very same code and answered by different ones. An empty value is not an option violation -
	 * requiredness is a separate check - and an entity-reference setting may carry an expression instead of an id.
	 *
	 * @param array<string, true> $validIds
	 */
	private static function isAllowedOptionValue(
		mixed $value,
		Setting $setting,
		array $validIds,
		?ValidationContext $context,
		?string $blockType,
		?string $presetId,
		?array $documentType,
		?string $carriedSettingName = null,
	): bool
	{
		if ($value === null)
		{
			return true;
		}

		// A number is the same id in another transport shape - the core leaves a JSON number unconverted - so it is
		// judged by its string form. Anything not scalar is a shape no option can hold, and letting it past unjudged
		// was the very bypass this check exists to close.
		if (!is_scalar($value))
		{
			return false;
		}

		$value = (string)$value;
		if ($value === '')
		{
			return true;
		}

		if (self::allowsEntityReferenceExpression($setting) && \CBPActivity::isExpression($value))
		{
			return true;
		}

		if (isset($validIds[$value]))
		{
			return true;
		}

		// A value the template the agent was handed already holds under this very preset and document type passes;
		// one the agent authored, and one carried under another preset or another document type of the same block
		// type, is still held to the current list. Why: {@see ValidationContext::hasCarriedOptionValue()}.
		return $blockType !== null
			&& $context?->hasCarriedOptionValue(
				$blockType,
				$presetId,
				$carriedSettingName ?? $setting->name,
				$value,
				$documentType,
			) === true
		;
	}

	/**
	 * The only array shapes a single USER value is persisted in: the empty list (no user) and a one-element list.
	 * Every other shape is a value the schema cannot hold, so it is refused rather than folded into a scalar:
	 * a multi-element list would be silently truncated to its first element, and a nested one would stringify to
	 * the literal 'Array'. `(string)` is applied instead of a truthiness test, so the id '0' stays '0' rather than
	 * collapsing into the empty string a required setting would then report as missing.
	 *
	 * @return string|null null means "not coercible" - the caller turns it into a type error
	 */
	private function coerceSingleUserValue(array $value): ?string
	{
		if (!array_is_list($value))
		{
			return null;
		}

		if ($value === [])
		{
			return '';
		}

		if (count($value) !== 1 || !is_scalar($value[0]))
		{
			return null;
		}

		return (string)$value[0];
	}

	/**
	 * An activity that declares `AllowSelection` on an option-bearing property makes the expression part of the
	 * setting's contract: the dialog offers the expression picker and stores what it picks alongside the ids, so
	 * refusing it here would refuse a value assembled by hand in the editor.
	 *
	 * The `botId` branch remains for descriptions that carry no such flag - a hand-written `.ai.php` declares no
	 * `AllowSelection` - and stays as narrow as it was: numeric option ids alone do not prove that an option is a
	 * portal entity, static enums often use numbers too. Every other option set stays closed.
	 */
	private static function allowsEntityReferenceExpression(Setting $setting): bool
	{
		if ($setting->options === null)
		{
			return false;
		}

		if ($setting->allowSelection)
		{
			return true;
		}

		return mb_strtolower($setting->name) === 'botid'
			&& self::optionsArePositiveIntegerIds($setting->options)
		;
	}

	/** The digit test is a PCRE one because the ctype extension is absent on portals: a call into it is a fatal. */
	private static function optionsArePositiveIntegerIds(SettingOptionCollection $options): bool
	{
		if (count($options) === 0)
		{
			return false;
		}

		foreach ($options as $option)
		{
			if (preg_match('/^[0-9]+$/D', $option->id) !== 1 || (int)$option->id <= 0)
			{
				return false;
			}
		}

		return true;
	}

	private function getValueAsArray(string|array $value): mixed
	{
		if (is_array($value))
		{
			return $value;
		}

		try
		{
			return Json::decode($value);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	public function getExpectedTypeValue(): array|string|null
	{
		return $this->expectedTypeValue;
	}
}
