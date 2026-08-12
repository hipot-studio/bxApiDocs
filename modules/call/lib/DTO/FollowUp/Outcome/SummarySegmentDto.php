<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class SummarySegmentDto extends Dto
{
	#[Description('Segment start timestamp relative to call start, formatted as HH:MM:SS.')]
	public ?string $start = null;

	#[Description('Segment end timestamp relative to call start, formatted as HH:MM:SS.')]
	public ?string $end = null;

	#[Description('Short heading describing the segment topic.')]
	public ?string $title = null;

	#[Description('Detailed summary of what was discussed during the segment.')]
	public ?string $summary = null;
}
