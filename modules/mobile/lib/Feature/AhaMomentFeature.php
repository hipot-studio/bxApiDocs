<?php
declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

class AhaMomentFeature extends FeatureFlag
{
	private const OPTION_CODE = 'should_show_aha_moments';

	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', self::OPTION_CODE, true);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}
}
