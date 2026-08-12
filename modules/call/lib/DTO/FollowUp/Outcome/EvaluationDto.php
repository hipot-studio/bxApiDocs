<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Outcome;

use Bitrix\Call\DTO\FollowUp\NullCompactArrayTrait;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class EvaluationDto extends Dto
{
	use NullCompactArrayTrait;

	#[Description('Overall meeting efficiency score in the range 0..100. Computed as the share of passed criteria, including the calendar overhead penalty.')]
	public ?int $efficiencyValue = null;

	/**
	 * Calendar booking quality signals.
	 *
	 * @var array{overhead: bool}|null
	 */
	#[Description('Calendar booking quality. Shape: { overhead: bool } — whether the meeting ran past its scheduled end time.')]
	public ?array $calendar = null;

	/**
	 * Meeting evaluation criteria as a map keyed by raw AI criterion code
	 * (e.g. `agenda_clearly_stated`, `agenda_items_covered`, …).
	 * Keys are AI-driven and may evolve over time — clients should iterate the map
	 * rather than hard-code key names.
	 *
	 * @var array<string, array{value: bool, criteria: string, thoughts: string, title: string}>|null
	 */
	#[Description('Meeting evaluation criteria map. Keys are AI-driven criterion codes (e.g. agenda_clearly_stated). Each value has shape { value: bool (passed/failed), criteria: string (raw code, mirrors the key), thoughts: string (AI commentary in selected mentionFormat), title: string (localized) }.')]
	public ?array $criteria = null;
}
