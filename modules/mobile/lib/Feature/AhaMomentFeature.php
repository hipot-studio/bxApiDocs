<?php
declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

class AhaMomentFeature extends FeatureFlag
{
	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', 'should_show_aha_moments', true);
	}

	public function enable(): void
	{
		Option::set('mobile', 'should_show_aha_moments', true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => 'should_show_aha_moments']);
	}
}
