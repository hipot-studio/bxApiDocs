<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

class Constant extends Item
{
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $constantType,
		public readonly string $description = '',
		public readonly bool $multiple = false,
		public readonly bool $required = false,
		public readonly array $options = [],
		public readonly array $settings = [],
		public readonly string|array $default = '',
		public readonly array $meta = [],
	) {}

	public function toArray(): array
	{
		$result = parent::toArray()
			+ [
				'id' => $this->id,
				'name' => $this->name,
				'constantType' => $this->constantType,
				'description' => $this->description,
				'multiple' => $this->multiple,
				'required' => $this->required,
				'options' => $this->options,
				'settings' => $this->settings,
				'default' => $this->default,
			]
		;

		if ($this->meta !== [])
		{
			$result['meta'] = $this->meta;
		}

		return $result;
	}

	public function getType(): ItemType
	{
		return ItemType::Constant;
	}

	public function toFieldTypeArray(): array
	{
		return [
			'Id' => $this->id,
			'Name' => $this->name,
			'Type' => $this->constantType,
			'Description' => $this->description,
			'Multiple' => $this->multiple,
			'Required' => $this->required,
			'Options' => $this->options,
			'Settings' => $this->settings,
			'Default' => $this->default,
		];
	}
}
