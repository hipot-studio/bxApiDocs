<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Call\DTO\FollowUp\NullCompactArrayTrait;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class InsightsDto extends Dto
{
	use NullCompactArrayTrait;

	/**
	 * False on non-CIS portals — speaker-level evaluation is region-gated.
	 * When false, speakerAnalysis and other speaker-evaluation fields are empty.
	 *
	 * Nullable on purpose: when `select` does not include `insights` or
	 * `insights.speakerEvaluationAvailable`, the field stays null and is stripped
	 * from the response by {@see NullCompactArrayTrait}. Without nullability the
	 * default `false` would leak into responses regardless of select, violating
	 * the "return only listed fields" contract.
	 */
	#[Description('Whether per-speaker evaluation is available on this portal. False for non-CIS regions; speakerAnalysis is empty in that case.')]
	public ?bool $speakerEvaluationAvailable = null;

	/**
	 * Per-speaker analysis. Sorted by talkPercentage DESC, then efficiencyValue DESC.
	 * Enrichment fields (talkPercentage, duration, durationFormat) come from the Transcription.
	 *
	 * @var array<int, array{userId?: int, detailedInsight?: string, efficiencyValue?: int, evaluationCriteria?: array<string, array{value: bool, criteria: string, title: string}>, talkPercentage?: int, duration?: int, durationFormat?: string}>|null
	 */
	#[Description('Per-speaker analysis, sorted by talkPercentage DESC, efficiencyValue DESC. Each item: { userId, detailedInsight, efficiencyValue (0..100), evaluationCriteria (map of criterion->{value,criteria,title}), talkPercentage, duration (seconds), durationFormat (localized human label) }.')]
	public ?array $speakerAnalysis = null;

	/**
	 * AI-detected meeting strengths.
	 *
	 * @var array<int, array{strengthTitle: string, strengthExplanation: string}>|null
	 */
	#[Description('Meeting strengths. Each item: { strengthTitle: string (short label), strengthExplanation: string (detailed reasoning) }.')]
	public ?array $meetingStrengths = null;

	/**
	 * AI-detected meeting weaknesses.
	 *
	 * @var array<int, array{weaknessTitle: string, weaknessExplanation: string}>|null
	 */
	#[Description('Meeting weaknesses. Each item: { weaknessTitle: string, weaknessExplanation: string }.')]
	public ?array $meetingWeaknesses = null;

	#[Description('AI commentary on how speakers\' communication style affected the meeting outcome.')]
	public ?string $speechStyleInfluence = null;

	#[Description('Free-form AI assessment of overall meeting engagement.')]
	public ?string $engagementLevel = null;

	#[Description('AI-detected delegated areas of responsibility and ownership coming out of the meeting.')]
	public ?string $areasOfResponsibility = null;

	#[Description('Final AI recommendations for future meetings of this team or topic.')]
	public ?string $finalRecommendations = null;
}
