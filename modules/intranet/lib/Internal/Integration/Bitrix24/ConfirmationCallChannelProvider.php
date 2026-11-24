<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * The channel implementation is registered by the settings of the bitrix24 module, and they reach
 * the service locator only once that module is included, so the lookup must not depend on whether
 * something else has already included it during the hit.
 */
final class ConfirmationCallChannelProvider
{
	public function find(): ?ConfirmationCallChannelInterface
	{
		$locator = ServiceLocator::getInstance();
		if (!$locator->has(ConfirmationCallChannelInterface::class))
		{
			Loader::includeModule('bitrix24');
		}

		if (!$locator->has(ConfirmationCallChannelInterface::class))
		{
			return null;
		}

		$channel = $locator->get(ConfirmationCallChannelInterface::class);

		return $channel instanceof ConfirmationCallChannelInterface ? $channel : null;
	}
}
