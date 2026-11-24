<?php

namespace Bitrix\Mobile\Internal\Onboarding\Activation;

use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * A single "step -> its push" pair inside an ActivationPath, as the campaign document declares it.
 * Whether the step is still open is decided by the rules of the push channel (CampaignRuleSet): the
 * pair composes only a registered ActivationStep and a PushType, so no arbitrary logic can be expressed.
 */
final class PushRule
{
	public function __construct(
		private readonly ActivationStep $step,
		private readonly PushType $pushType,
	)
	{
	}

	public function getStep(): ActivationStep
	{
		return $this->step;
	}

	public function getPushType(): PushType
	{
		return $this->pushType;
	}
}
