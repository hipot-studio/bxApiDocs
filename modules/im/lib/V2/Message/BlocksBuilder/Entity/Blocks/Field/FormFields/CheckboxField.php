<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields;

class CheckboxField extends FormField
{
	/** @var Option[] */
	protected array $options;

	protected function __construct(array $fieldData)
	{
		parent::__construct($fieldData);
		$this->options = $this->createOptions($fieldData['options'] ?? []);
	}

	protected function createOptions(array $optionsData): array
	{
		$options = [];
		foreach ($optionsData as $optionData)
		{
			$options[] = new Option((string)($optionData['id'] ?? ''), (string)($optionData['label'] ?? ''));
		}

		return $options;
	}

	/** @return Option[] */
	public function getOptions(): array
	{
		return $this->options;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->getType(),
			'label' => $this->label ? \Bitrix\Im\Text::parse($this->label) : null,
			'options' => array_map(static fn (Option $option) => $option->jsonSerialize(), $this->options),
			'disabled' => $this->disabled,
		];
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->getType(),
			'label' => $this->label,
			'options' => array_map(static fn (Option $option) => $option->toArray(), $this->options),
			'disabled' => $this->disabled,
		];
	}

	public function getType(): string
	{
		return Type::Checkbox->value;
	}
}
