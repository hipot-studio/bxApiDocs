<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class DeleteCheckListDto
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $checkListId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			checkListId: static::mapInteger($props, 'checkListId'),
		);
	}

	public function getId(): ?int
	{
		return $this->checkListId;
	}
}
