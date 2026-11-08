<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class IntegratorFeature extends FeatureFlag
{
	use MyCompanySectionElement;

	public const OPTION_NAME = 'integrator_card_enabled';

	protected function isOptionEnabled(): bool
	{
		return $this->isOptionOn('mobile', self::OPTION_NAME, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::set('mobile', self::OPTION_NAME, 'N');
	}
}
