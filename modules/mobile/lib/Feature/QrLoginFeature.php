<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class QrLoginFeature extends FeatureFlag
{
	private const OPTION_NAME = 'feature_qr_login_enabled';

	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', self::OPTION_NAME, false);
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
