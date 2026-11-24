<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class PersonalAccountFeature extends FeatureFlag
{
	use MyCompanySectionElement;

	private const OPTION_NAME = 'feature_personal_account_enabled';

	protected function isOptionEnabled(): bool
	{
		return Option::get('mobile', self::OPTION_NAME, 'Y') === 'Y';
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
