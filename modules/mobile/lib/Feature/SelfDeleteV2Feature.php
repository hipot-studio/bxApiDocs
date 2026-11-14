<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class SelfDeleteV2Feature extends FeatureFlag
{
	private static string $FEATURE_CODE = 'feature_self_delete_v2_enabled';

	public function isEnabled(): bool
	{
		return Option::get('mobile', self::$FEATURE_CODE, 'Y') !== 'N';
	}

	public function enable(): void
	{
		Option::set('mobile', self::$FEATURE_CODE, 'Y');
	}

	public function disable(): void
	{
		Option::set('mobile', self::$FEATURE_CODE, 'N');
	}
}
