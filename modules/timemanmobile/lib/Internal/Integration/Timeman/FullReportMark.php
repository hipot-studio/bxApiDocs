<?php

namespace Bitrix\TimemanMobile\Internal\Integration\Timeman;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Psr\Log\LoggerInterface;

/**
 * Manager decision written the way the old scheme writes it: the same fields, the same right over the report
 * owner and the same legacy side effects as the web path of `timeman/tools/timeman.php` (`admin_report_full`).
 * Refreshing the livefeed entry and notifying the author are part of that path, not an addition of this one.
 */
final class FullReportMark
{
	private const MARKS_WITH_DECISION = ['G', 'B', 'N'];
	private const MARK_UNCONFIRMED = 'X';
	private const LOGGER_ID = 'timemanmobile.full-report-mark';
	private const LOGGER_AUDIT_TYPE = 'TIMEMANMOBILE_FULL_REPORT_MARK';

	/**
	 * The marks of the old scheme, the unconfirmed one included: the AI reports layer has no action writing
	 * it, so `timemanmobile.FullReport.setMark` leaves every unconfirmed mark to this writer whatever layer
	 * the owner of the report reads. The web path of `admin_report_full` writes it off the same form.
	 */
	public static function isSupportedMark(string $mark): bool
	{
		return $mark === self::MARK_UNCONFIRMED || in_array($mark, self::MARKS_WITH_DECISION, true);
	}

	/**
	 * The owner of a report, read from the stored row the way every path of the old scheme reads him. The
	 * scheme a decision belongs to is his, so the caller asks the row rather than the request about it.
	 */
	public static function ownerIdOf(int $reportId): int
	{
		if (!Loader::includeModule('timeman'))
		{
			return 0;
		}

		$report = \CTimeManReportFull::GetByID($reportId)->Fetch();

		return is_array($report) ? (int)$report['USER_ID'] : 0;
	}

	public static function set(int $reportId, string $mark, int $approverId): Result
	{
		$result = new Result();

		if (!self::isSupportedMark($mark))
		{
			return $result->addError(new Error('Unknown report mark.', 'UNKNOWN_MARK'));
		}

		if (!Loader::includeModule('timeman'))
		{
			return $result->addError(new Error('Module timeman is not installed.', 'MODULE_NOT_INSTALLED'));
		}

		$report = \CTimeManReportFull::GetByID($reportId)->Fetch();
		if (!is_array($report))
		{
			return $result->addError(new Error('Report not found.', 'REPORT_NOT_FOUND'));
		}

		$ownerId = (int)$report['USER_ID'];
		if (!FullReportAccess::canWriteReportOf($ownerId))
		{
			return $result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
		}

		$fields = self::buildFields($mark, $approverId);
		if (!\CTimeManReportFull::Update($reportId, $fields) && !self::isDecisionStored($reportId, $fields))
		{
			return $result->addError(new Error('Report mark is not saved.', 'MARK_NOT_SAVED'));
		}

		\CUserReportFull::clearReportCache($ownerId);

		self::refreshLivefeedEntry($reportId, $ownerId, $report, $fields);

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildFields(string $mark, int $approverId): array
	{
		// The way back to a report nobody has decided on, and the only branch here leaving it unapproved:
		// the whole decision is cleared rather than the mark alone, the way the web path clears it.
		if ($mark === self::MARK_UNCONFIRMED)
		{
			return [
				'MARK' => self::MARK_UNCONFIRMED,
				'APPROVE' => 'N',
				'APPROVER' => 0,
				'APPROVE_DATE' => '',
			];
		}

		return [
			'MARK' => $mark,
			'APPROVE' => 'Y',
			'APPROVER' => $approverId,
			'APPROVE_DATE' => ConvertTimeStamp(time(), 'FULL'),
		];
	}

	/**
	 * CTimeManReportFull::Update() answers by the number of changed rows, so repeating a decision the row
	 * already holds is refused the same way a failed write is. The web path does not look at that answer at
	 * all; here the stored row tells the two apart, and only a row without the decision is an error.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function isDecisionStored(int $reportId, array $fields): bool
	{
		$stored = \CTimeManReportFull::GetByID($reportId)->Fetch();
		if (!is_array($stored))
		{
			return false;
		}

		return $stored['MARK'] === $fields['MARK']
			&& $stored['APPROVE'] === $fields['APPROVE']
			&& (int)$stored['APPROVER'] === (int)$fields['APPROVER'];
	}

	/**
	 * The decision is stored by now, so a failure of the legacy refresh must not answer the client with an
	 * error, but it must not pass unnoticed either, because nothing else records it. MessageUpdate() refuses
	 * by returning rather than by throwing: it answers with the entry it refreshed, and a report without an
	 * entry yet is answered with the publication of one, which the legacy layer refuses the same way.
	 *
	 * Both arrays are required: without them the legacy layer refreshes the entry but skips the author's
	 * notification.
	 *
	 * @param array<string, mixed> $report
	 * @param array<string, mixed> $fields
	 */
	private static function refreshLivefeedEntry(int $reportId, int $ownerId, array $report, array $fields): void
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		try
		{
			$logId = (int)\CReportNotifications::MessageUpdate($reportId, $report, $fields);
			// The entry belongs to an owner reading the old scheme and to nobody else, so a refusal to
			// publish one over an owner of the AI reports layer is by design rather than a failure.
			$reason = ($logId > 0 || self::isAiReportsEnabledFor($ownerId)) ? null : self::lastLegacyError();
		}
		catch (\Throwable $error)
		{
			$reason = $error->getMessage();
		}

		if ($reason === null)
		{
			return;
		}

		self::logger()->warning(
			'Legacy livefeed refresh failed for reportId={reportId}: {reason}',
			[
				'reportId' => $reportId,
				'reason' => $reason,
			],
		);
	}

	private static function logger(): LoggerInterface
	{
		return LegacyPathLogger::create(self::LOGGER_ID, self::LOGGER_AUDIT_TYPE);
	}

	/**
	 * The gate the legacy publication holds over the owner of the report: a report of an owner who reads the
	 * AI reports layer is never announced into the livefeed, and that refusal is no failure.
	 */
	private static function isAiReportsEnabledFor(int $ownerId): bool
	{
		return (new SettingsProvider($ownerId))->isReportsEnabledWithAi();
	}

	/**
	 * The reason the legacy layer refused the entry: it is thrown into the application rather than returned,
	 * so the journal would hold a bare "false" without reading it back. The state of the application is
	 * cleared afterwards: a refusal left there would be answered to the next reader as its own error.
	 */
	private static function lastLegacyError(): string
	{
		global $APPLICATION;

		if (!$APPLICATION instanceof \CMain)
		{
			return 'the legacy layer refused the entry without a message';
		}

		$exception = $APPLICATION->GetException();
		$APPLICATION->ResetException();

		return $exception ? (string)$exception->GetString() : 'the legacy layer refused the entry without a message';
	}
}
