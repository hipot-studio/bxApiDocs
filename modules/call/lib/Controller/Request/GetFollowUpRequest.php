<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Request;

use Bitrix\Call\DTO\FollowUp\Enum\MentionFormat;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Interaction\Request\Request;

class GetFollowUpRequest extends Request
{
	#[Description('Bitrix24 call identifier whose follow-up to fetch (b_call.ID). Required.')]
	public int $callId;

	/**
	 * Whitelisted top-level fields and dotted-paths. Mirrors {@see ListFollowUpRequest::$select}:
	 * unknown entries → HTTP 400 (`invalid_select_field`).
	 *
	 * Semantics:
	 *  - `select === null` (omitted)  → legacy `get` behavior: full FollowUpDto shape, every null
	 *                                   field is preserved in the response (stable contract for
	 *                                   clients that do not care about payload size).
	 *  - `select === []`              → only cheap meta-fields (DEFAULT_META_FIELDS) + `callId`.
	 *  - `select === ['…', '…']`     → only the listed fields + `callId`; fields with null values
	 *                                   stay visible because of `NullCompactArrayTrait::setExplicitFields`.
	 *
	 * Allowed values (canonical list — see {@see FollowUpReader::OUTCOME_PATHS}):
	 *  - meta:   `callId`, `callType`, `initiatorId`, `startDate`, `endDate`, `durationSeconds`,
	 *            `uuid`, `participants`, `tracks`, `outcomes`, `language`, `version`, `createdAt`
	 *  - AI:     `transcription`, `overview`, `summary`, `insights`, `evaluation`
	 *  - dotted: `overview.topic`, `overview.actionItems`, `insights.speakerAnalysis`,
	 *            `evaluation.criteria`, …
	 *
	 * @var string[]|null
	 */
	#[Description('Optional list of fields/dotted-paths to include in the response. Same vocabulary as call.followup.list. When omitted — the full FollowUpDto shape is returned (every null field preserved). When provided — only the listed fields (plus always-present `callId`) reach the response. Unknown entries → HTTP 400 (invalid_select_field). Examples: ["callId","startDate"], ["overview.topic","overview.actionItems"], ["insights.speakerAnalysis"], ["evaluation"].')]
	public ?array $select = null;

	/**
	 * How to render @-mentions inside AI text fields.
	 *
	 * Accepted values: 'bb' (default — BBCode markers), 'html' (anchor links), 'none' (plain text).
	 * Source-of-truth list: {@see MentionFormat}. V3 framework cannot auto-convert string → BackedEnum
	 * for Request DTO properties (it only does that for Structure filter/select fields), so this is
	 * a `?string` validated by InArray.
	 *
	 * @see MentionFormat
	 */
	#[Description('Mention rendering format. Accepted values: bb | html | none (default bb). Invalid value → HTTP 400.')]
	#[InArray(
		validValues: [null, MentionFormat::Bb->value, MentionFormat::Html->value, MentionFormat::None->value],
		strict: true,
	)]
	public ?string $mentionFormat = null;
}
