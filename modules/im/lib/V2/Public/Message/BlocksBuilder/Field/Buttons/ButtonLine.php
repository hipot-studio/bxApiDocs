<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;

use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\AbstractField;

class ButtonLine extends AbstractField
{
	protected array $buttons = [];

	public function addButton(AbstractButton $button): self
	{
		$this->buttons[] = $button->jsonSerialize();

		return $this;
	}

	public function jsonSerialize(): array
	{
		return $this->buttons;
	}

	public static function create(): self
	{
		return new self();
	}
}
