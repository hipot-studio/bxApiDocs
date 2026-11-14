<?php

declare(strict_types=1);

namespace Bitrix\Call\Update;

use Bitrix\Call\Public\Features\Registry;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;

/**
 * Moves the state of the call feature switches from the module options to the kernel flags:
 * wherever a legacy option is on and the flag has no record yet, the flag is enabled. A record of
 * either value is a decision already made - by an administrator or centrally - and is left as it is.
 * An option that is off or missing leaves no record, and no record means the transition default of
 * the flag decides - the very same option read through the very same rule.
 *
 * Both the list of flags and what counts as enabled, the Y/N options included, come from the
 * registry: a second copy of either here is exactly the drift this transfer must rule out. The list
 * is {@see Registry::getTransferableFlags()} - an option read per site has no faithful portal-wide
 * record and stays with its transition default.
 *
 * @internal
 */
final class FeatureFlagTransfer
{
	private const MODULE_ID = 'call';

	/**
	 * The mark of a finished transfer. The cumulative updater reads it through the kernel, by this
	 * name, and does not set the agent again once the agent has removed itself.
	 */
	public const TRANSFERRED_OPTION = 'feature_flags_transferred';

	/**
	 * Agent set by the update: the transfer is a one-time conversion, so it is not declared in
	 * install/migrations and removes itself - by returning an empty string - once it is done.
	 *
	 * A portal with the mark of a finished transfer gets nothing: the agent removes itself at once.
	 * It may be set again after the transfer - on a developer installation the cumulative updater
	 * moves the flags before the numbered update arrives, and that update sets the agent without
	 * looking at the mark - and a second pass would store a record again for a flag reset to its
	 * default since. The mark is checked ahead of the kernel mechanism: it is written only after a
	 * transfer, so there is nothing left to wait for, whatever the kernel.
	 *
	 * A flag with a record is skipped whatever its value, so a flag switched off before the transfer
	 * stays off, and a repeated pass writes nothing for it.
	 *
	 * The mark is written only once every flag to transfer has a record. The kernel manager only logs
	 * a failed write, so the agent reads the store itself afterwards; a flag still without a record
	 * keeps the agent, which tries again at the interval the update set.
	 *
	 * Where the kernel has no flag mechanism yet the agent stays and tries again at the interval the
	 * update set. The numbered update cannot get there - its version_control.txt demands the kernel
	 * with the mechanism - but the cumulative updater of developer installations has no such
	 * boundary. Removing the agent there would lose the transfer for good: once the kernel arrives,
	 * nothing would move the options into flags before the transition default is gone. Waiting costs
	 * a class lookup per run and ends on the first run after the kernel update. The mark of a
	 * finished transfer is written only after the transfer, so a waiting agent leaves none.
	 */
	public static function runAgent(): string
	{
		if (Option::get(self::MODULE_ID, self::TRANSFERRED_OPTION, 'N') === 'Y')
		{
			return '';
		}

		if (!self::isMechanismAvailable())
		{
			return self::class . '::runAgent();';
		}

		if (!self::transfer())
		{
			return self::class . '::runAgent();';
		}

		Option::set(self::MODULE_ID, self::TRANSFERRED_OPTION, 'Y');

		return '';
	}

	/**
	 * @return bool Whether every flag with an enabled legacy option has a record now.
	 */
	private static function transfer(): bool
	{
		$flagClasses = [];
		foreach (Registry::getTransferableFlags() as $optionName => $flagClass)
		{
			if (Registry::isLegacyOptionEnabled($optionName))
			{
				$flagClasses[] = $flagClass;
			}
		}

		$missing = self::withoutRecord($flagClasses);
		if ($missing === [])
		{
			return true;
		}

		// The manager skips a write by its cached values: drop them to act on the state just read.
		Feature::clearCache();
		Feature::enable(...$missing);

		return self::withoutRecord($missing) === [];
	}

	/**
	 * Reads the store without the ORM cache. The record code is the flag class name:
	 * AbstractFlag::getCode() is final and returns static::class.
	 *
	 * @param string[] $flagClasses
	 * @return string[] The flags of the list that have no record.
	 */
	private static function withoutRecord(array $flagClasses): array
	{
		if ($flagClasses === [])
		{
			return [];
		}

		$stored = [];
		$rows = FeatureFlagTable::query()
			->setSelect(['CODE'])
			->whereIn('CODE', $flagClasses)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$stored[$row['CODE']] = true;
		}

		return array_values(array_filter(
			$flagClasses,
			static fn (string $flagClass): bool => !isset($stored[$flagClass]),
		));
	}

	/**
	 * The parent of the flags is checked rather than the flags themselves: class_exists() on an heir
	 * whose parent is missing throws instead of returning false.
	 */
	private static function isMechanismAvailable(): bool
	{
		return class_exists(Feature::class) && class_exists(AbstractFlag::class);
	}
}
