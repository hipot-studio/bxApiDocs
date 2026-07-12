<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class GetProjectForAnalysisDto
{
	use MapTypeTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $projectId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			projectId: static::mapInteger($props, 'projectId'),
		);
	}
}
