<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

use Bitrix\Main\Type\Contract\Arrayable;

class Setting implements Arrayable
{
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly string|SettingType $type,
		public readonly bool $required = false,
		public readonly bool $multiple = false,
		public readonly ?SettingOptionCollection $options = null,
		public readonly ?SettingCollection $children = null,
		public readonly ?string $defaultValue = null,
		/** The activity lets this setting hold a bizproc expression instead of one of its options. */
		public readonly bool $allowSelection = false,
		/** Who owns the value. An activity that declares nothing keeps the editable typed default. */
		public readonly SettingCategory $category = SettingCategory::EditableTyped,
		/** Only meaningful for a map setting: how its keys are decided. */
		public readonly ?SettingKeyMode $keyMode = null,
		/**
		 * Only meaningful for a list of objects: the child key whose value tells one element from another, so
		 * two elements sharing it are refused instead of one silently winning over the other.
		 *
		 * Published with the setting ({@see self::toArray()}) because the server enforces it: an agent that
		 * cannot read the rule learns of it only from the refusal its duplicate earns.
		 */
		public readonly ?string $uniqueChildKey = null,
		public readonly int|float|null $minimum = null,
		public readonly int|float|null $maximum = null,
	) {}

	public function toArray(): array
	{
		$array = [
			'name' => $this->name,
			'description' => $this->description,
			'type' => (string)$this->type,
		];

		if ($this->required)
		{
			$array['required'] = $this->required;
		}

		if ($this->multiple)
		{
			$array['multiple'] = $this->multiple;
		}

		// Unconditional: the agent has to know an expression is allowed here even when it is not,
		// and an absent key reads as "unknown" rather than "no".
		$array['allowSelection'] = $this->allowSelection;

		if ($this->keyMode !== null)
		{
			$array['keyMode'] = $this->keyMode->value;
		}

		if ($this->uniqueChildKey !== null)
		{
			$array['uniqueChildKey'] = $this->uniqueChildKey;
		}

		if ($this->minimum !== null)
		{
			$array['minimum'] = $this->minimum;
		}

		if ($this->maximum !== null)
		{
			$array['maximum'] = $this->maximum;
		}

		if ($this->options)
		{
			$array['options'] = $this->options->toArray();
		}

		if ($this->children)
		{
			$array['children'] = $this->children->toArray();
		}

		if ($this->defaultValue !== null && $this->defaultValue !== '')
		{
			$array['defaultValue'] = $this->defaultValue;
		}

		return $array;
	}
}