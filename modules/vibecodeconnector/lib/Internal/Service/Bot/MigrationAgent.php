<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Bot;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * One-shot CAgent: after a module update, looks for legacy bots and migrates them to the new scheme.
 * Returns an empty string on success (agent self-removes) or the re-run statement on failure.
 */
final class MigrationAgent
{
	public const RUN_STATEMENT = '\\Bitrix\\Vibecodeconnector\\Internal\\Service\\Bot\\MigrationAgent::run();';

	public static function run(): string
	{
		if (!Loader::includeModule('vibecodeconnector'))
		{
			return self::RUN_STATEMENT;
		}

		try
		{
			$legacyBots = (new LegacyBotDetector())->find();

			if ($legacyBots === [])
			{
				return '';
			}

			$service = ServiceLocator::getInstance()->get(BotService::class);
			if (!$service instanceof BotService)
			{
				return self::RUN_STATEMENT;
			}

			$service->migrate($legacyBots);

			return '';
		}
		catch (\Throwable $e)
		{
			\AddMessage2Log(
				'MigrationAgent failed: ' . $e->getMessage(),
				'vibecodeconnector',
			);

			return self::RUN_STATEMENT;
		}
	}
}
