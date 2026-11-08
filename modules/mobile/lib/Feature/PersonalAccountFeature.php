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
		return $this->isOptionOn('mobile', self::OPTION_NAME, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_NAME, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_NAME]);
	}
}
