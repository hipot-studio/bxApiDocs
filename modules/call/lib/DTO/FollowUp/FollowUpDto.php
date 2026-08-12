<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp;

use Bitrix\Call\DTO\FollowUp\Outcome\EvaluationDto;
use Bitrix\Call\DTO\FollowUp\Outcome\InsightsDto;
use Bitrix\Call\DTO\FollowUp\Outcome\OverviewDto;
use Bitrix\Call\DTO\FollowUp\Outcome\SummaryDto;
use Bitrix\Call\DTO\FollowUp\Outcome\TranscriptionDto;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Follow-up payload shared by call.followup.list (metadata + optional AI blocks via `select`)
 * and call.followup.get (metadata + all AI blocks always populated).
 */
class FollowUpDto extends Dto
{
	use NullCompactArrayTrait;

	#[Description('Bitrix24 call identifier (b_call.ID). Always present.')]
	public ?int $callId = null;

	#[Description('Call type: 1 = instant ad-hoc, 2 = permanent conference room, 3 = large room.')]
	public ?int $callType = null;

	#[Description('User id of who initiated the call.')]
	public ?int $initiatorId = null;

	#[Description('ISO 8601 UTC timestamp when the call started.')]
	public ?string $startDate = null;

	#[Description('ISO 8601 UTC timestamp when the call ended. Null while the call is still in progress.')]
	public ?string $endDate = null;

	#[Description('Total call duration in seconds (endDate − startDate).')]
	public ?int $durationSeconds = null;

	#[Description('Opaque UUID of the call session. Opt-in: list this in `select` to receive it.')]
	public ?string $uuid = null;

	#[Description('Detected transcription language (BCP-47 / ISO 639-1, e.g. "ru", "en"). Null when there is no transcription.')]
	public ?string $language = null;

	#[Description('Maximum schema version across all stored outcome blocks (transcription, overview, summary, insights, evaluation).')]
	public ?int $version = null;

	/** @var ParticipantDto[]|null */
	#[Description('Call participants, enriched with display data from Util::getUsers (name, avatar, work position). Opt-in via `select`.')]
	#[ElementType(ParticipantDto::class)]
	public ?array $participants = null;

	/** @var string[]|null */
	#[Description('Names of outcome blocks present for this call: any subset of [transcription, overview, summary, insights, evaluation].')]
	public ?array $outcomes = null;

	#[Description('ISO 8601 UTC timestamp of the most recently stored outcome record for this call.')]
	public ?string $createdAt = null;

	/** @var TrackDto[]|null */
	#[Description('Call recordings / tracks with download URLs. Opt-in via `select`.')]
	#[ElementType(TrackDto::class)]
	public ?array $tracks = null;

	#[Description('Time-ordered transcription of the call with per-segment speaker attribution.')]
	public ?TranscriptionDto $transcription = null;

	#[Description('AI overview of the meeting: topic, agenda, agreements, action items, takeaways.')]
	public ?OverviewDto $overview = null;

	#[Description('Segmented summary of the call by topic chunks.')]
	public ?SummaryDto $summary = null;

	#[Description('AI insights: per-speaker analysis (CIS only), meeting strengths/weaknesses, recommendations.')]
	public ?InsightsDto $insights = null;

	#[Description('Meeting efficiency evaluation: overall score and individual evaluation criteria.')]
	public ?EvaluationDto $evaluation = null;
}
