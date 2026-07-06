<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field;

class Fold extends AbstractField
{
	protected function __construct(
		readonly string $title,
		readonly bool $isOpened = false
	)
	{}

	public static function create(string $title, bool $isOpened = false): self
	{
		return new self($title, $isOpened);
	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title,
			'isOpened' => $this->isOpened,
		];
	}
}
