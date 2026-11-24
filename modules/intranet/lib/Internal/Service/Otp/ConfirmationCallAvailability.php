<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Integration\Bitrix24\ConfirmationCallChannelProvider;
use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;

/**
 * Single reading of the portal policy for the confirmation call, shared by the setup and the login
 * scenario. An installation without a registered channel implementation answers the same way a
 * disabled portal does, so a failure of the policy never opens the scenario it guards.
 */
final class ConfirmationCallAvailability
{
	private readonly \Closure $channelResolver;

	public function __construct(?\Closure $channelResolver = null)
	{
		$this->channelResolver = $channelResolver ?? static fn(): ?ConfirmationCallChannelInterface =>
			(new ConfirmationCallChannelProvider())->find()
		;
	}

	public function forSetup(int $userId): bool
	{
		return $this->isAvailable($userId, ConfirmationCallChannelInterface::SCENARIO_PORTAL_2FA_SETUP);
	}

	public function forLogin(int $userId): bool
	{
		return $this->isAvailable($userId, ConfirmationCallChannelInterface::SCENARIO_PORTAL_2FA_LOGIN);
	}

	private function isAvailable(int $userId, string $scenario): bool
	{
		try
		{
			return ($this->channelResolver)()?->isAvailable($userId, $scenario) === true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
