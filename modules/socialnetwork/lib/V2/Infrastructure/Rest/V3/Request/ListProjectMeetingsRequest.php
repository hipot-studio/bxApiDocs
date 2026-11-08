<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Request;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\GetProjectMeetingsDto;

/**
 * Input for socialnetwork.project.meeting.list.
 *
 * @see \Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\GetProjectMeetingsDto
 */
class ListProjectMeetingsRequest extends Request
{
	#[Description('Identifier of the project whose finished meetings to return. Required.')]
	#[PositiveNumber]
	public int $projectId;

	#[Description(
		'Size of the returned sample, 1..50. Defaults to 10;'
		. ' pass 50 together with "from"/"to" when a period is requested.'
	)]
	#[Range(min: GetProjectMeetingsDto::LIMIT_MIN, max: GetProjectMeetingsDto::LIMIT_MAX)]
	public int $limit = GetProjectMeetingsDto::LIMIT_DEFAULT;

	#[Description(
		'Lower bound of the meeting date, ISO 8601 ("YYYY-MM-DD" or "YYYY-MM-DDTHH:MM:SS", optionally'
		. ' with a timezone offset; UTC is assumed when omitted). Defaults to 30 days ago; a range wider'
		. ' than 31 days is narrowed to the 31 days ending at "to".'
	)]
	public ?string $from = null;

	#[Description(
		'Upper bound of the meeting date, ISO 8601. A date-only value is inclusive: it covers the whole day.'
	)]
	public ?string $to = null;
}
