<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List;

use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Color;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\ListIconType;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\AbstractField;

class Icon extends AbstractField
{
	protected function __construct(
		readonly ListIconType $type,
		readonly ?Color $color = null
	)
	{}

	public static function create(ListIconType $type, ?Color $color = null): self
	{
		return new self($type, $color);
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type->value,
			'color' => $this->color?->value,
		];
	}
}
