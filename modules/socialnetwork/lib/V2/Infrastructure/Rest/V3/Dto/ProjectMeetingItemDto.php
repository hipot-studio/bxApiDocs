<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Dto;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A finished project meeting with an optional call overview.
 *
 * @see \Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Controller\Project\Meeting
 * @see \Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectMeetingResponseMapper
 */
class ProjectMeetingItemDto extends Dto
{
	#[Description('Calendar event identifier of the meeting inside the project calendar.')]
	public ?int $meetingId = null;

	#[Description('Meeting name.')]
	public ?string $name = null;

	#[Description('ISO 8601 UTC timestamp when the meeting started.')]
	public ?string $date = null;

	#[Description('AI-detected meeting topic in one short phrase.')]
	public ?string $topic = null;

	/**
	 * @var array{explanation?: string, quote?: string}|null
	 */
	#[Description(
		'Agenda detection. Shape: { explanation: string (was an agenda announced and how it was set),'
		. ' quote: string (verbatim agenda quote from the transcription) }.'
	)]
	public ?array $agenda = null;

	/**
	 * @var array<int, array{agreement: string, quote?: string}>|null
	 */
	#[Description(
		'Explicit agreements reached during the meeting. Each item: { agreement: string,'
		. ' quote?: string (supporting transcription excerpt) }.'
	)]
	public ?array $agreements = null;

	/**
	 * @var array<int, array{actionItem: string, quote?: string}>|null
	 */
	#[Description('Action items detected by AI. Each item: { actionItem: string, quote?: string }.')]
	public ?array $actionItems = null;

	/**
	 * @var array<int, array{meeting: string, quote?: string}>|null
	 */
	#[Description('Follow-up meetings planned during the call. Each item: { meeting: string, quote?: string }.')]
	public ?array $meetings = null;

	#[Description('Long-form summary of the meeting outcomes (multiple sentences).')]
	public ?string $detailedTakeaways = null;

	/**
	 * Omits unavailable overview fields from the response.
	 */
	public function toArray(bool $rawData = false): array
	{
		return array_filter(
			parent::toArray($rawData),
			static fn (mixed $value): bool => $value !== null,
		);
	}
}
