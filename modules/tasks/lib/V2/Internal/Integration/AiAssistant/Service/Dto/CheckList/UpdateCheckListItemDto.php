<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class UpdateCheckListItemDto
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $itemId  = null,
		public readonly ?string $title = null,
		public readonly ?int $sortIndex = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			itemId: static::mapInteger($props, 'itemId'),
			title: static::mapString($props, 'title'),
			sortIndex: static::mapInteger($props, 'sortIndex'),
		);
	}

	public function isEmpty(): bool
	{
		return
			$this->title === null
			&& $this->sortIndex === null
		;
	}

	public function getId(): ?int
	{
		return $this->itemId;
	}
}
