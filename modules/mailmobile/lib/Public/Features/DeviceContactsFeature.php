<?php

namespace Bitrix\MailMobile\Public\Features;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class DeviceContactsFeature extends FeatureFlag
{
	private const OPTION_NAME = 'device_contacts_enabled';

	public function isEnabled(): bool
	{
		return Option::get('mailmobile', self::OPTION_NAME, 'Y') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mailmobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::set('mailmobile', self::OPTION_NAME, 'N');
	}
}
