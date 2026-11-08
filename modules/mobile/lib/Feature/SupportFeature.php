<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Provider\SupportProvider;

final class SupportFeature extends FeatureFlag
{
	private const OPTION_CODE = 'feature_support_enabled';

	/**
	 * The portal option is a single read and the provider behind it walks the imbot session of the
	 * user, so the option is asked first and a switched-off support costs nothing more.
	 */
	public function isEnabled(): bool
	{
		return $this->isMobileSupportIsOn() && (new SupportProvider())->isEnabled();
	}

	public function isMobileSupportIsOn(): bool
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
