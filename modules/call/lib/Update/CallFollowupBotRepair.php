<?php

declare(strict_types=1);

namespace Bitrix\Call\Update;

use Bitrix\Call\Integration\Im\CallFollowupBot;
use Bitrix\Im;
use Bitrix\Im\Command;
use Bitrix\Im\Model\BotTable;
use Bitrix\Im\Model\CommandTable;
use Bitrix\Im\Model\StatusTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

/**
 * Removes the traces the CallFollowupBot registration leaves behind, without touching the account.
 *
 * Bitrix\Im\Bot::unRegister() is deliberately not used here: it deletes the user account the id
 * points at, and on a portal where the id belongs to a live employee that would delete the employee.
 *
 * @internal
 */
final class CallFollowupBotRepair
{
	private const ATTEMPTS_OPTION = 'call_followup_bot_repair_attempts';
	private const MAX_ATTEMPTS = 5;

	/**
	 * Agent set by the update: the repair is a one-time conversion, so it is not declared in
	 * install/migrations and removes itself - by returning an empty string - as soon as it succeeds.
	 *
	 * A failed run keeps the agent for another try at the interval the update set: the modules it
	 * needs may be unavailable at this moment, and the registration may fail on a state only a later
	 * run finds cleaned. The tries are counted in an option and limited, so a portal the repair
	 * cannot heal does not carry the agent forever.
	 */
	public static function runAgent(): string
	{
		if (self::repair())
		{
			self::forgetAttempts();

			return '';
		}

		$attempt = (int)Option::get(CallFollowupBot::MODULE_ID, self::ATTEMPTS_OPTION, 0) + 1;
		if ($attempt >= self::MAX_ATTEMPTS)
		{
			self::log('followup bot repair gave up after ' . $attempt . ' attempts');
			self::forgetAttempts();

			return '';
		}

		Option::set(CallFollowupBot::MODULE_ID, self::ATTEMPTS_OPTION, $attempt);

		return self::class . '::runAgent();';
	}

	/**
	 * Drops the bot registry row, the call commands, the bot status color and the stored id.
	 * Idempotent: a repeated call on an already cleaned state neither fails nor writes.
	 */
	public static function unbind(int $botId): bool
	{
		if ($botId <= 0)
		{
			return false;
		}

		if (!Loader::includeModule('im'))
		{
			return false;
		}

		self::dropCommands($botId);
		self::dropRegistryRow($botId);
		self::resetStatusColor($botId);

		Im\Bot::clearCache();
		Command::clearCache();

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->clearByTag('USER_NAME_' . $botId);
		$taggedCache->clearByTag('IM_CONTACT_LIST');

		// The stored id is cleared last: it is the address of every other trace.
		if (CallFollowupBot::getBotId() === $botId)
		{
			CallFollowupBot::setBotId(0);
		}

		return true;
	}

	/**
	 * Brings the follow-up bot registration on this portal back to a correct state.
	 *
	 * Suspicious ids are collected from every place the registration leaves a trace - the registry
	 * rows, the stored option and the commands of module call - because support may already have
	 * removed part of them by hand. Every id that does not belong to a bot account loses its traces,
	 * then the regular registration runs and the commands are synchronized.
	 *
	 * On a correct portal nothing changes: no id fails the check, register() returns the stored id
	 * and the command synchronization finds no difference.
	 */
	public static function repair(): bool
	{
		foreach (['im', 'imbot'] as $moduleId)
		{
			if (!Loader::includeModule($moduleId))
			{
				self::log('followup bot repair could not include module ' . $moduleId);

				return false;
			}
		}

		foreach (self::collectSuspectedIds() as $botId)
		{
			if (!CallFollowupBot::isBotUser($botId))
			{
				self::unbind($botId);
			}
		}

		$botId = CallFollowupBot::register();
		if ($botId <= 0)
		{
			// No second attempt here: repeating the registration is the job of the regular
			// delayRegister agent, which already limits the number of tries.
			self::log('followup bot repair could not register the bot');

			return false;
		}

		// Also brings a portal registered long ago up to the current command list.
		CallFollowupBot::registerCommands();

		return true;
	}

	/**
	 * @return int[] Distinct ids that may carry a follow-up bot registration.
	 */
	private static function collectSuspectedIds(): array
	{
		$suspects = [];

		// The pair MODULE_ID + CODE is not unique - the primary key of the registry is BOT_ID - so a
		// damaged and a correct row may coexist on a portal touched by hand.
		$rows = BotTable::getList([
			'select' => ['BOT_ID'],
			'filter' => [
				'=MODULE_ID' => CallFollowupBot::MODULE_ID,
				'=CODE' => CallFollowupBot::BOT_CODE,
			],
		]);
		while ($row = $rows->fetch())
		{
			$suspects[] = (int)$row['BOT_ID'];
		}

		$savedId = CallFollowupBot::getBotId();
		if ($savedId > 0)
		{
			$suspects[] = $savedId;
		}

		// On a portal where the registry row and the option were removed by hand the commands are
		// the only trace left.
		$commands = CommandTable::getList([
			'select' => ['BOT_ID'],
			'filter' => ['=MODULE_ID' => CallFollowupBot::MODULE_ID],
		]);
		while ($command = $commands->fetch())
		{
			$suspects[] = (int)$command['BOT_ID'];
		}

		return array_values(array_unique(array_filter($suspects, static fn (int $id): bool => $id > 0)));
	}

	/**
	 * Diagnostics goes to the portal error log, so support sees the case without reading the database.
	 */
	private static function log(string $reason): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(
			new SystemException(CallFollowupBot::BOT_CODE . ': ' . $reason)
		);
	}

	private static function forgetAttempts(): void
	{
		Option::delete(CallFollowupBot::MODULE_ID, ['name' => self::ATTEMPTS_OPTION]);
	}

	/**
	 * Commands are dropped regardless of the registry row: on a portal where the row was already
	 * removed by hand the commands stay orphaned and would never be cleaned otherwise.
	 */
	private static function dropCommands(int $botId): void
	{
		$rows = CommandTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=MODULE_ID' => CallFollowupBot::MODULE_ID,
				'=BOT_ID' => $botId,
			],
		]);
		while ($row = $rows->fetch())
		{
			Command::unRegister([
				'COMMAND_ID' => $row['ID'],
				'BOT_ID' => $botId,
				'FORCE' => 'Y',
			]);
		}
	}

	/**
	 * Only our own row is removed: another module's bot sitting on this id is none of our business.
	 */
	private static function dropRegistryRow(int $botId): void
	{
		$row = BotTable::getRow([
			'select' => ['BOT_ID', 'MODULE_ID', 'CODE'],
			'filter' => ['=BOT_ID' => $botId],
		]);
		if (
			$row
			&& $row['MODULE_ID'] === CallFollowupBot::MODULE_ID
			&& $row['CODE'] === CallFollowupBot::BOT_CODE
		)
		{
			BotTable::delete($botId);
		}
	}

	/**
	 * An empty stored color is what makes the messenger fall back to the automatic one. A color the
	 * user picked themselves is left alone - only the one the registration wrote is reset.
	 */
	private static function resetStatusColor(int $botId): void
	{
		$status = StatusTable::getRow([
			'select' => ['USER_ID', 'COLOR'],
			'filter' => ['=USER_ID' => $botId],
		]);
		if ($status && (string)$status['COLOR'] === CallFollowupBot::BOT_COLOR)
		{
			StatusTable::update($botId, ['COLOR' => '']);
		}
	}
}
