<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class UpdateCheckListDto
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $checkListId = null,
		public readonly ?string $title = null,
		public readonly ?int $sortIndex = null,
		public readonly ?bool $isComplete = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			checkListId: static::mapInteger($props, 'checkListId'),
			title: static::mapString($props, 'title'),
			sortIndex: static::mapInteger($props, 'sortIndex'),
			isComplete: static::mapBool($props, 'isComplete'),
		);
	}

	public function isEmpty(): bool
	{
		return
			$this->title === null
			&& $this->sortIndex === null
			&& $this->isComplete === null
		;
	}

	public function getId(): ?int
	{
		return $this->checkListId;
	}
}
