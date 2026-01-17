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