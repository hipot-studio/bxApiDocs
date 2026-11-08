<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\FormFields;

class SingleLineTextField extends AbstractFormField
{
	private function __construct(
		private readonly ?string $id = null,
		private readonly ?string $label = null,
		private readonly ?string $placeholder = null,
		private readonly bool $disabled = false,
	)
	{}

	public static function create(
		?string $id = null,
		?string $label = null,
		?string $placeholder = null,
		bool $disabled = false
	): self
	{
		return new self($id, $label, $placeholder, $disabled);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => 'singleLineText',
			'label' => $this->label,
			'placeholder' => $this->placeholder,
			'disabled' => $this->disabled,
		];
	}
}
