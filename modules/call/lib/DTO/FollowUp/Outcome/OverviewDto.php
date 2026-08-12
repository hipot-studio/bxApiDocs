<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Call\DTO\FollowUp\NullCompactArrayTrait;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class OverviewDto extends Dto
{
	use NullCompactArrayTrait;

	#[Description('AI-detected meeting topic in one short phrase.')]
	public ?string $topic = null;

	#[Description('Long-form summary of meeting outcomes (multiple sentences). @-mentions are rendered in the selected mentionFormat.')]
	public ?string $detailedTakeaways = null;

	/**
	 * Meeting type classification produced by AI.
	 *
	 * @var array{explanation: string, typeTag: string, title: string}|null
	 */
	#[Description('Meeting type. Shape: { explanation: string, typeTag: string (raw AI tag, e.g. "planning"), title: string (localized) }.')]
	public ?array $meetingType = null;

	/**
	 * Agenda detection summary.
	 *
	 * @var array{explanation: string, quote: string}|null
	 */
	#[Description('Agenda detection. Shape: { explanation: string (was an agenda announced and how it was set), quote: string (verbatim agenda quote from transcription) }.')]
	public ?array $agenda = null;

	/**
	 * List of explicit agreements reached during the meeting.
	 *
	 * @var array<int, array{agreement: string, quote?: string}>|null
	 */
	#[Description('List of explicit agreements. Each item: { agreement: string (AI-rephrased agreement, may contain @-mentions in selected mentionFormat), quote?: string (supporting transcription excerpt) }.')]
	public ?array $agreements = null;

	/**
	 * Action items detected by AI.
	 *
	 * @var array<int, array{actionItem: string, actionItemMentionLess?: string, quote?: string}>|null
	 */
	#[Description('Action items. Each item: { actionItem: string (with @-mentions), actionItemMentionLess?: string (same text without markup), quote?: string }.')]
	public ?array $actionItems = null;

	/**
	 * Planned follow-up meetings or events mentioned during the call.
	 *
	 * @var array<int, array{meeting: string, meetingMentionLess?: string, quote?: string}>|null
	 */
	#[Description('Planned follow-up meetings. Each item: { meeting: string (with @-mentions), meetingMentionLess?: string, quote?: string }.')]
	public ?array $meetings = null;
}
