<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List;

use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Color;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\AbstractField;

class OrderedListElements extends AbstractField
{
	protected array $elements = [];

	public function addElement(string $text, ?Color $color = null): self
	{
		$this->elements[] = [
			'text' => $text,
			'color' => $color?->value,
		];

		return $this;
	}

	public function jsonSerialize(): array
	{
		return $this->elements;
	}

	public static function create(): self
	{
		return new self();
	}
}
