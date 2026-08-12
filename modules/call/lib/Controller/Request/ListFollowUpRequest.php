<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Request;

use Bitrix\Call\DTO\FollowUp\Enum\MentionFormat;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Interaction\Request\Request;

class ListFollowUpRequest extends Request
{
	/**
	 * Whitelisted top-level fields and dotted-paths.
	 *
	 * Allowed values (see FollowUpReader for the canonical list):
	 *  - meta:   `callId`, `callType`, `initiatorId`, `startDate`, `endDate`,
	 *            `durationSeconds`, `uuid`, `participants`, `tracks`, `outcomes`,
	 *            `language`, `version`, `createdAt`
	 *  - AI:     `transcription`, `overview`, `summary`, `insights`, `evaluation`
	 *  - dotted: `overview.topic`, `overview.actionItems`,
	 *            `insights.speakerAnalysis`, `evaluation.criteria`, …
	 *
	 * When omitted, the response contains only cheap meta-fields (callId, callType,
	 * initiatorId, startDate, endDate, durationSeconds). Anything else must be opted in.
	 * Fields listed in `select` always appear in the output, even when their value is null.
	 *
	 * @var string[]|null
	 */
	#[Description('List of fields/dotted-paths to include in each item. Unknown entries → HTTP 400 (invalid_select_field). When omitted, only cheap meta-fields are returned. Examples: ["participants"], ["overview.topic","overview.actionItems"], ["insights.speakerAnalysis"], ["evaluation"].')]
	public ?array $select = null;

	/**
	 * Filtering options. `startDate.from` and `startDate.to` are REQUIRED for `list`;
	 * the date range is mandatory. `participantId` is an opt-in filter — admins may
	 * scope to any user, regular users are silently forced to themselves.
	 *
	 * @var array{
	 *     startDate?: array{from?: string, to?: string},
	 *     participantId?: int
	 * }|null
	 */
	#[Description('Filter. Shape: { startDate: { from: ISO-8601 string, to: ISO-8601 string } (required), participantId?: int (admin-only; ignored for non-admins) }. Missing or reversed date range → HTTP 400 (invalid_date_range).')]
	public ?array $filter = null;

	/**
	 * Sort configuration. Only `startDate` is currently supported; values
	 * are `asc` or `desc` (case-insensitive, defaults to `desc`).
	 *
	 * @var array<string, string>|null
	 */
	#[Description('Sort. Map of field → direction (asc|desc). Only `startDate` is supported. Default: { startDate: "desc" }.')]
	public ?array $order = null;

	/**
	 * Cursor-based pagination control.
	 *  - `limit` is clamped to MAX_LIMIT_LIGHT (200) for cheap selects and
	 *    MAX_LIMIT_HEAVY (20) when select includes heavy paths
	 *    (`transcription`, `overview`, `insights`).
	 *  - `afterCursor` is echoed back from the previous response's `afterCursor`.
	 *
	 * @var array{
	 *     limit?: int,
	 *     afterCursor?: array{startDate: string, id: int}
	 * }|null
	 */
	#[Description('Pagination. Shape: { limit?: int (default 50; capped at 200 for light selects, 20 when select includes heavy AI blocks), afterCursor?: { startDate: ISO-8601 string, id: int } — pass back the previous response\'s `afterCursor` to fetch the next page }.')]
	public ?array $pagination = null;

	/**
	 * How to render @-mentions inside AI text fields.
	 *
	 * Accepted values: 'bb' (default), 'html', 'none'. Source-of-truth list: {@see MentionFormat}.
	 * Stored as `?string` (not `?MentionFormat`) because V3 framework cannot auto-convert
	 * string → BackedEnum for Request DTO properties; validated by InArray attribute.
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
