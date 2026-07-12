<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field;

use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons\ButtonLine;

class Buttons extends AbstractField
{
	protected array $buttons = [];

	public function addButtonLine(ButtonLine $buttonLine): self
	{
		$this->buttons[] = $buttonLine->jsonSerialize();

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
