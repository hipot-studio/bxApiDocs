<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class ImplementationSpecialistsFeature extends FeatureFlag
{
	use MyCompanySectionElement;

	private const OPTION_CODE = 'feature_implementation_specialists_enabled';

	protected function isOptionEnabled(): bool
	{
		return $this->isOptionOn('mobile', self::OPTION_CODE, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'Y');
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}
}
