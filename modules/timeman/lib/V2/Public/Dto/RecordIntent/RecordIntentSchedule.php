<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\RecordIntent;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;

/**
 * Public, immutable view of the welcome-box display state (DTO-01). All timestamps are unix seconds
 * UTC; 0 means "not applicable". The internal canShowNow derivative is intentionally not exposed —
 * the consumer derives it from these fields.
 *
 * targetStartBasis names the source of targetStartTimestamp (history, schedule, shift, dataCollection or
 * default; empty when not reported). The target is fixed by the first resolve of the period: once its
 * sources change within the period and give another target, the basis is period.
 */
final class RecordIntentSchedule implements Arrayable
{
	use MapTypeTrait;

	public function __construct(
		public readonly string $mode,
		public readonly string $periodKey,
		public readonly int $targetStartTimestamp,
		public readonly int $periodEndTimestamp,
		public readonly int $targetShiftStartTimestamp,
		public readonly int $targetShiftStopTimestamp,
		public readonly int $retryIntervalSeconds,
		public readonly int $lastShownTimestamp,
		public readonly int $maxShows,
		public readonly int $maxDismisses,
		public readonly int $showCount,
		public readonly int $dismissCount,
		public readonly int $suppressedUntilTimestamp,
		public readonly bool $hasTargetAction,
		public readonly bool $shiftStartable,
		public readonly string $targetStartBasis = '',
	)
	{
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			mode: static::mapString($props, 'mode', ''),
			periodKey: static::mapString($props, 'periodKey', ''),
			targetStartTimestamp: static::mapInteger($props, 'targetStartTimestamp', 0),
			periodEndTimestamp: static::mapInteger($props, 'periodEndTimestamp', 0),
			targetShiftStartTimestamp: static::mapInteger($props, 'targetShiftStartTimestamp', 0),
			targetShiftStopTimestamp: static::mapInteger($props, 'targetShiftStopTimestamp', 0),
			retryIntervalSeconds: static::mapInteger($props, 'retryIntervalSeconds', 0),
			lastShownTimestamp: static::mapInteger($props, 'lastShownTimestamp', 0),
			maxShows: static::mapInteger($props, 'maxShows', 0),
			maxDismisses: static::mapInteger($props, 'maxDismisses', 0),
			showCount: static::mapInteger($props, 'showCount', 0),
			dismissCount: static::mapInteger($props, 'dismissCount', 0),
			suppressedUntilTimestamp: static::mapInteger($props, 'suppressedUntilTimestamp', 0),
			hasTargetAction: static::mapBool($props, 'hasTargetAction', false),
			shiftStartable: static::mapBool($props, 'shiftStartable', true),
			targetStartBasis: static::mapString($props, 'targetStartBasis', ''),
		);
	}

	public function toArray(): array
	{
		return [
			'mode' => $this->mode,
			'periodKey' => $this->periodKey,
			'targetStartTimestamp' => $this->targetStartTimestamp,
			'periodEndTimestamp' => $this->periodEndTimestamp,
			'targetShiftStartTimestamp' => $this->targetShiftStartTimestamp,
			'targetShiftStopTimestamp' => $this->targetShiftStopTimestamp,
			'retryIntervalSeconds' => $this->retryIntervalSeconds,
			'lastShownTimestamp' => $this->lastShownTimestamp,
			'maxShows' => $this->maxShows,
			'maxDismisses' => $this->maxDismisses,
			'showCount' => $this->showCount,
			'dismissCount' => $this->dismissCount,
			'suppressedUntilTimestamp' => $this->suppressedUntilTimestamp,
			'hasTargetAction' => $this->hasTargetAction,
			'shiftStartable' => $this->shiftStartable,
			'targetStartBasis' => $this->targetStartBasis,
		];
	}
}
