<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp;

use Bitrix\Main\Config\Option;

final class OpenAppSettings
{
	private const MODULE_ID = 'vibecodeconnector';
	private const OPTION_OPEN_APP_IN_IFRAME = 'open_app_in_iframe';

	public function isOpenInIframeEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_OPEN_APP_IN_IFRAME, 'N') === 'Y';
	}

	public function setOpenInIframeEnabled(bool $value): void
	{
		Option::set(self::MODULE_ID, self::OPTION_OPEN_APP_IN_IFRAME, $value ? 'Y' : 'N');
	}
}
