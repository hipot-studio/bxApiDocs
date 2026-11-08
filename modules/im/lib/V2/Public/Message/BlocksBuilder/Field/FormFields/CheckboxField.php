<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\FormFields;

class CheckboxField extends AbstractFormField
{
	private function __construct(
		private readonly ?string $id = null,
		private readonly ?string $label = null,
		private readonly array $options = [],
		private readonly bool $disabled = false,
	)
	{}

	public static function create(
		?string $id = null,
		?string $label = null,
		array $options = [],
		bool $disabled = false
	): self
	{
		return new self($id, $label, $options, $disabled);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => 'checkbox',
			'label' => $this->label,
			'options' => $this->options,
			'disabled' => $this->disabled,
		];
	}
}
