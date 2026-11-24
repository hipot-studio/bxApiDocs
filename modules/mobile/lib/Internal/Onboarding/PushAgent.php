<?php

namespace Bitrix\Mobile\Internal\Onboarding;

use Bitrix\Main\Config\Feature;
use Bitrix\Mobile\Public\Feature\OnboardingPushFlag;
use Bitrix\Mobile\Internal\Onboarding\Service\OnboardingService;

class PushAgent
{
	private const AGENT_INTERVAL = 3600; // 1 hour
	private const MODULE_ID = 'mobile';

	public static function run(): string
	{
		// no user in the context: the agent decides for the portal, and it runs in the epilog of
		// somebody's hit - see RegionConfig::isSupported()
		if (Feature::isEnabled(OnboardingPushFlag::class, new Feature\Context()))
		{
			try
			{
				// the pass reports its own outcome counters to the log, so the agent has
				// nothing to add to the result it gets back
				(new OnboardingService())->processAllUsers();
			}
			catch (\Throwable $e)
			{
				AddMessage2Log('Onboarding push agent: ' . $e->getMessage(), self::MODULE_ID);
			}
		}

		return static::getAgentName();
	}

	public static function getAgentName(): string
	{
		return static::class . '::run();';
	}

	public static function register(): void
	{
		if (static::isRegistered())
		{
			return;
		}

		\CAgent::AddAgent(
			static::getAgentName(),
			self::MODULE_ID,
			'N',
			self::AGENT_INTERVAL,
		);
	}

	public static function unregister(): void
	{
		\CAgent::RemoveAgent(
			static::getAgentName(),
			self::MODULE_ID,
		);
	}

	public static function isRegistered(): bool
	{
		$agents = \CAgent::GetList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => self::MODULE_ID,
				'NAME' => static::getAgentName(),
			],
		);

		return (bool)$agents->Fetch();
	}
}
