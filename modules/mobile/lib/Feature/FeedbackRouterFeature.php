<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class FeedbackRouterFeature extends FeatureFlag
{
	private const OPTION_NAME = 'feature_feedback_router_enabled';

	public function isEnabled(): bool
	{
		return Option::get('mobile', self::OPTION_NAME, 'Y') !== 'N';
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
