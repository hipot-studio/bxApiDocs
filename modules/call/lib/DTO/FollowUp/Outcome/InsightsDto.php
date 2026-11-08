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
	 * Region-gated by the portal license; the region list is owned by
	 * {@see \Bitrix\Call\Integration\AI\CallAISettings::isSpeakerEvaluationAvailable()}.
	 * When false, new outcomes are analysed by a prompt that carries no evaluation, and on read the
	 * whole speakerAnalysis is withheld — including an analysis stored by an earlier, wider rule:
	 * the field comes as null in the full response shape and is absent where nulls are compacted.
	 * The rest of the AI insights (meeting strengths, weaknesses, recommendations) is unaffected.
	 *
	 * Nullable on purpose: when `select` does not include `insights` or
	 * `insights.speakerEvaluationAvailable`, the field stays null and is stripped
	 * from the response by {@see NullCompactArrayTrait}. Without nullability the
	 * default `false` would leak into responses regardless of select, violating
	 * the "return only listed fields" contract.
	 */
	#[Description('Whether per-speaker evaluation is available on this portal, gated by the license region. When false, speakerAnalysis carries no data — null in the full response shape, absent where nulls are compacted; the rest of the insights is unaffected.')]
	public ?bool $speakerEvaluationAvailable = null;

	/**
	 * Per-speaker analysis, carried only while {@see self::$speakerEvaluationAvailable} is true: items
	 * carry userId and efficiencyValue, every criterion is an object {value, criteria, title}, the
	 * enrichment fields (talkPercentage, duration, durationFormat) come from the Transcription, the
	 * list is limited to speakers matched to portal users and sorted by talkPercentage DESC, then
	 * efficiencyValue DESC. With the gate closed the field carries no value in any form: null where the
	 * response keeps the full DTO shape (`get`, or `insights` / `insights.speakerAnalysis` in `select`)
	 * and absent where nulls are compacted, so an analysis stored by an earlier, wider rule never
	 * reaches a consumer.
	 *
	 * @var array<int, array{userId?: int, detailedInsight?: string, efficiencyValue?: int, evaluationCriteria?: array<string, array{value: bool, criteria: string, title: string}>, talkPercentage?: int, duration?: int, durationFormat?: string}>|null
	 */
	#[Description('Per-speaker analysis, filled only when speakerEvaluationAvailable is true; when it is false the field carries no data — null where the response keeps the full shape (get, or insights / insights.speakerAnalysis in select) and absent where nulls are compacted. Each item: { userId, detailedInsight, efficiencyValue (0..100), evaluationCriteria (map of criterion->{value,criteria,title}), talkPercentage, duration (seconds), durationFormat (localized human label) }, limited to speakers matched to portal users and sorted by talkPercentage DESC, efficiencyValue DESC.')]
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
