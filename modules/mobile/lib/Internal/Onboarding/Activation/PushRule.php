<?php

namespace Bitrix\Mobile\Internal\Onboarding\Activation;

use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * A single "first uncompleted step -> its push" rule inside an ActivationPath.
 * The rule matches when its target step is not yet completed; ordering of rules
 * inside the path (first-match-wins) makes the first uncompleted step win.
 *
 * A rule composes only a registered ActivationStep (whose completion comes from a
 * registered StepChecker) and a PushType - no arbitrary logic can be expressed.
 */
final class PushRule
{
	public function __construct(
		private readonly ActivationStep $step,
		private readonly PushType $pushType,
	)
	{
	}

	public function matches(ActivationState $state): bool
	{
		return !$state->isCompleted($this->step);
	}

	public function getPushType(): PushType
	{
		return $this->pushType;
	}
}
