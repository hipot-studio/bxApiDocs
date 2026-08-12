<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Call\DTO\FollowUp\NullCompactArrayTrait;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

class SummaryDto extends Dto
{
	use NullCompactArrayTrait;

	/** @var SummarySegmentDto[]|null */
	#[Description('Time-ordered segments of the meeting summary. Each segment covers a continuous topical chunk of the call.')]
	#[ElementType(SummarySegmentDto::class)]
	public ?array $segments = null;
}
