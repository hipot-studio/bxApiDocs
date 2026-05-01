<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\State;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\ValueObjectInterface;

class StateFlags implements ValueObjectInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?bool $needsControl = null,
		public readonly ?bool $matchesWorkTime = null,
		public readonly ?bool $defaultRequireResult = null,
		public readonly ?bool $allowsTimeTracking = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			StateFlagKey::NeedsControl->value => $this->needsControl,
			StateFlagKey::MatchesWorkTime->value => $this->matchesWorkTime,
			StateFlagKey::DefaultRequireResult->value => $this->defaultRequireResult,
			StateFlagKey::AllowsTimeTracking->value => $this->allowsTimeTracking,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			needsControl: static::mapBool($props, StateFlagKey::NeedsControl->value),
			matchesWorkTime: static::mapBool($props, StateFlagKey::MatchesWorkTime->value),
			defaultRequireResult: static::mapBool($props, StateFlagKey::DefaultRequireResult->value),
			allowsTimeTracking: static::mapBool($props, StateFlagKey::AllowsTimeTracking->value),
		);
	}
}
