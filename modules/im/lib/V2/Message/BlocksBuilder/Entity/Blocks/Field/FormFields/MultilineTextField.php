<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields;

class MultilineTextField extends FormField
{
	protected ?string $placeholder;

	protected function __construct(array $fieldData)
	{
		parent::__construct($fieldData);
		$this->placeholder = $fieldData['placeholder'] ?? null;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->getType(),
			'label' => $this->label ? \Bitrix\Im\Text::parse($this->label) : null,
			'placeholder' => $this->placeholder ? \Bitrix\Im\Text::parse($this->placeholder) : null,
			'disabled' => $this->disabled,
		];
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->getType(),
			'label' => $this->label,
			'placeholder' => $this->placeholder,
			'disabled' => $this->disabled,
		];
	}

	public function getType(): string
	{
		return Type::MultilineText->value;
	}
}
