<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version;

/**
 * DTO-10: render parameters of the version cell of an AI-agents grid row.
 *
 * The field names and the state codes are an owner contract that the client
 * extension relies on. Do not rename or repurpose them.
 *
 * The two axes are independent: a date does not imply a state and a state does
 * not imply a date. Both empty is a defensive path, not a row category: only a
 * system template row without a usable revision date ends up there.
 *
 * The cell deliberately carries no version labels: the installed-version label
 * is derived from the copy logic hash (UpgradeAvailabilityService::makeVersionLabel())
 * and must not be exposed to every viewer of the grid.
 */
final class VersionCellDto
{
	public function __construct(
		/** State of the row; null only for a system template, which shows a date instead. */
		public readonly ?VersionCellState $state = null,
		/** Timestamp of the system template revision date; null when there is no date. */
		public readonly ?int $date = null,
	) {}

	/**
	 * @return array{
	 *     state: string|null,
	 *     date: int|null,
	 * }
	 */
	public function toArray(): array
	{
		return [
			'state' => $this->state?->value,
			'date' => $this->date,
		];
	}
}
