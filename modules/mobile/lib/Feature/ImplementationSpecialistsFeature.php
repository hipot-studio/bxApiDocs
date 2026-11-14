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
		return Option::get('mobile', self::OPTION_CODE, 'Y') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'Y');
	}

	public function disable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'N');
	}
}
