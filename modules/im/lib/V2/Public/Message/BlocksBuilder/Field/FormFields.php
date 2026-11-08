<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field;

use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\FormFields\AbstractFormField;

class FormFields extends AbstractField
{
	protected array $fields = [];

	public function addField(AbstractFormField $field): self
	{
		$this->fields[] = $field->jsonSerialize();

		return $this;
	}

	public function jsonSerialize(): array
	{
		return $this->fields;
	}

	public static function create(): self
	{
		return new self();
	}
}
