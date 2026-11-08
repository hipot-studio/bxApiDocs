<?php

namespace Bitrix\TimemanMobile\Internal\Integration\Timeman;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\Timeman\Service\DependencyManager;

/**
 * Rights over a report of the old scheme, asked the way the legacy paths ask them. The owner is always
 * taken from the stored report rather than from the request, so a foreign report cannot be reached by
 * passing someone else's id.
 */
final class FullReportAccess
{
	/**
	 * The right the decision actions of timeman ask for, and it answers off the same legacy write list the
	 * web path of `admin_report_full` reads by hand. The owner being the caller himself changes nothing in
	 * that answer: neither the right over everybody nor the write list leaves him out, and the decision
	 * actions of `timeman.V2.FullReport` accept him just the same. The mark control of the client is what
	 * keeps an author away from the report of his own.
	 */
	public static function canWriteReportOf(int $ownerId): bool
	{
		global $USER;

		if (!\CTimeMan::CanUse(true))
		{
			return false;
		}

		return DependencyManager::getInstance()
			->getUserPermissionsManager($USER)
			->canUpdateWorktime($ownerId)
		;
	}

	/**
	 * Sending is the owner's own act: the web path lets the row be written by the owner and nobody else
	 * (`tools/timeman.php`, `$USER->GetID() == $arReport['USER_ID']`), and the legacy write list holds a
	 * regular employee for nobody, not even for himself. The right over the owner is accepted on top of
	 * that, the way `timeman.V2.FullReport.submit` accepts it, so choosing the transport by the AI reports
	 * setting does not change who may send a report.
	 *
	 * Whether worktime is kept on the portal at all is asked separately, by the tool gate of the send: the
	 * personal setting the web path reads on top of that (`CTimeMan::CanUse()`, UF_TIMEMAN) is not asked
	 * here, because `timeman.V2.FullReport.submit` — the action of this very screen before the old scheme
	 * was reached — does not ask it either, and refusing it here would narrow who may send a report.
	 */
	public static function canSendReportOf(int $ownerId): bool
	{
		return self::currentUserId() === $ownerId || self::canWriteReportOf($ownerId);
	}

	/**
	 * Worktime as a tool of the portal: a state changing path of the reports has nothing to write when the
	 * tool is off, and the neighbouring read actions of the module ask the same question
	 * (`Controllers/WorkDays.php`).
	 */
	public static function isWorktimeToolAvailable(): bool
	{
		return Loader::includeModule('intranet')
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('worktime')
		;
	}

	public static function currentUserId(): int
	{
		global $USER;

		return $USER instanceof \CUser ? (int)$USER->GetID() : 0;
	}
}
