<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

/**
 * One row of a portion of the presence list (`presence.list.v2`), its fields in the order of the contract.
 *
 * `state` is the group the row stands in, the rest what the row draws: the bounds of the day, the zone they are
 * printed in, the marks, the visits of the date and the badge of the absence of the date with its last day. What the
 * group was decided by - the closing of the record and the facts of the check-ins - stays on the server.
 *
 * `zoneOffsetSec` is the offset east of UTC, in seconds, at the start of the day, taken from where the panel of the
 * route of the same employee takes the zone of that day. It is null for a day without a start or without a readable
 * zone, and a client without it prints by the clock of the device.
 */
final class PresenceItemDto
{
	public function __construct(
		public readonly int $userId,
		public readonly PresenceGroup $state,
		public readonly ?int $startTs = null,
		public readonly ?int $endTs = null,
		public readonly ?int $zoneOffsetSec = null,
		public readonly bool $isPaused = false,
		public readonly bool $noConsent = false,
		public readonly int $checkInCount = 0,
		public readonly ?array $absence = null,
	)
	{
	}

	/**
	 * @return array{
	 *     userId: int,
	 *     state: string,
	 *     startTs: int|null,
	 *     endTs: int|null,
	 *     zoneOffsetSec: int|null,
	 *     isPaused: bool,
	 *     noConsent: bool,
	 *     checkInCount: int,
	 *     absence: array{kind: string, dateTo: string}|null,
	 * }
	 */
	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'state' => $this->state->value,
			'startTs' => $this->startTs,
			'endTs' => $this->endTs,
			'zoneOffsetSec' => $this->zoneOffsetSec,
			'isPaused' => $this->isPaused,
			'noConsent' => $this->noConsent,
			'checkInCount' => $this->checkInCount,
			'absence' => $this->absence === null
				? null
				: ['kind' => $this->absence['kind'], 'dateTo' => $this->absence['dateTo']]
			,
		];
	}
}
