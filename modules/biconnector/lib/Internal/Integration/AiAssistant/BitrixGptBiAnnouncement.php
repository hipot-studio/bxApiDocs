<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

/**
 * Single source of truth for the right to show the one-time announcement about BitrixGPT
 * working with BI dashboards. Read-only: the personal mark of a spent show is written by
 * the claim action, the portal-wide startup mark - by the instance lifecycle.
 */
final class BitrixGptBiAnnouncement
{
	public const USER_OPTION_CATEGORY = 'biconnector';
	public const USER_OPTION_NAME = 'bitrixgpt_bi_announce_shown';

	/**
	 * Portal timestamp of the very first instance startup. Lives as a pair with
	 * ~superset_instance_exists and is maintained by SupersetInitializer::markSupersetInstanceExists().
	 */
	public const INSTANCE_STARTUP_DATE_OPTION = '~superset_instance_startup_date';

	/**
	 * The announcement is a time-boxed campaign. The shipped date is the product decision;
	 * the portal option exists so it can be moved without waiting for a module update.
	 */
	public const TERMINATION_DATE_OPTION = '~bitrixgpt_bi_announce_termination_date';
	public const DEFAULT_TERMINATION_DATE = '01.12.2026';

	private const TERMINATION_DATE_FORMAT = 'd.m.Y';

	private const GRACE_PERIOD_SECONDS = 2 * 86400;

	public static function canShowForCurrentUser(): bool
	{
		if (self::isTerminated())
		{
			return false;
		}

		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		// The spent mark comes before the assistant check on purpose: it is one option read, while
		// BitrixGptChat::isAvailable() loads aiassistant and im and asks the service. For everyone
		// who has already seen the announcement the answer is false either way, and on the report
		// list that whole chain is work this feature adds to the page.
		if (self::isShownForUser($userId))
		{
			return false;
		}

		if (!BitrixGptChat::isAvailable())
		{
			return false;
		}

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return false;
		}

		return self::isGracePeriodPassed();
	}

	public static function isShownForUser(int $userId): bool
	{
		$mark = \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_NAME,
			null,
			$userId,
		);

		return $mark !== null;
	}

	/**
	 * The campaign is over from the termination date onwards, so the last full day of showing
	 * is the day before it. A malformed option must not silently extend the campaign forever.
	 *
	 * Public because the claim action rechecks it: a page rendered before the date and left open
	 * would otherwise still spend a show after the campaign ended.
	 */
	public static function isTerminated(): bool
	{
		$configured = (string)Option::get(
			'biconnector',
			self::TERMINATION_DATE_OPTION,
			self::DEFAULT_TERMINATION_DATE,
		);

		try
		{
			$terminationDate = new DateTime($configured, self::TERMINATION_DATE_FORMAT);
		}
		catch (ObjectException)
		{
			$terminationDate = new DateTime(self::DEFAULT_TERMINATION_DATE, self::TERMINATION_DATE_FORMAT);
		}

		return new DateTime() >= $terminationDate;
	}

	/**
	 * An empty startup mark means the instance predates the feature rollout, so there is nothing to wait for.
	 */
	private static function isGracePeriodPassed(): bool
	{
		$startedAt = (int)Option::get('biconnector', self::INSTANCE_STARTUP_DATE_OPTION, '0');
		if ($startedAt <= 0)
		{
			return true;
		}

		return (time() - $startedAt) >= self::GRACE_PERIOD_SECONDS;
	}
}
