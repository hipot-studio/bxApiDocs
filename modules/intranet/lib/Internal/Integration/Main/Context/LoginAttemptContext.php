<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main\Context;

use Bitrix\Intranet\Internal\Entity\Otp\LoginAttemptContextField;
use Bitrix\Main\Context;
use Bitrix\Main\Web\UserAgent\Browser;
use Bitrix\Main\Web\UserAgent\DeviceType;

/**
 * The circumstances of a login attempt: what the card shows to its owner and what the journal records.
 */
final class LoginAttemptContext
{
	/**
	 * @return array<string, string>
	 */
	public function read(): array
	{
		$browser = Browser::detect();
		$deviceTypes = DeviceType::getDescription();

		return [
			// Never X-Forwarded-For: the card is where a person tells their own login from a stranger's,
			// and the requester must not choose the evidence.
			LoginAttemptContextField::IP => (string)(Context::getCurrent()?->getRequest()?->getRemoteAddress() ?? ''),
			LoginAttemptContextField::BROWSER => (string)$browser->getName(),
			LoginAttemptContextField::DEVICE => implode(', ', array_filter([
				$deviceTypes[$browser->getDeviceType()] ?? '',
				$browser->getPlatform(),
			])),
		];
	}
}
