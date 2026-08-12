<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class TranscriptionSegmentDto extends Dto
{
	#[Description('Speaker user id. Null for unresolved or external speakers.')]
	public ?int $userId = null;

	#[Description('Speaker display name. Falls back to "User<id>" if user data is unavailable.')]
	public ?string $userName = null;

	#[Description('Segment start timestamp relative to call start, formatted as HH:MM:SS.')]
	public ?string $start = null;

	#[Description('Segment end timestamp relative to call start, formatted as HH:MM:SS.')]
	public ?string $end = null;

	#[Description('Spoken text. @-mentions are rendered in the format requested by `mentionFormat` (bb | html | none).')]
	public ?string $text = null;
}
