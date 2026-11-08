<?php

namespace Bitrix\TimemanMobile\Internal\Integration\Timeman;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use Bitrix\Timeman\V2\Public\Command\FullReport\AddCommand;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Psr\Log\LoggerInterface;

/**
 * Report sent the way the old scheme sends it: the same fields, the same auto approval of an owner without
 * a manager and the same legacy side effects as the web path of `timeman/tools/timeman.php`
 * (`save_full_report`). Publishing the entry into the livefeed and notifying the manager belong to that
 * path, not to this one. What the web path reads from its form (the period, the tasks, the events, the
 * files and the delay of the reminder) stays untouched here: the mobile client sends none of that and the
 * stored row already holds the period of the report.
 */
final class FullReportSend
{
	private const MARK_UNCONFIRMED = 'X';
	private const MARK_NEUTRAL = 'N';
	private const LOGGER_ID = 'timemanmobile.full-report-send';
	private const LOGGER_AUDIT_TYPE = 'TIMEMANMOBILE_FULL_REPORT_SEND';

	public static function send(int $reportId, string $reportText): Result
	{
		$result = new Result();

		if (!Loader::includeModule('timeman'))
		{
			return $result->addError(new Error('Module timeman is not installed.', 'MODULE_NOT_INSTALLED'));
		}

		// A portal with worktime turned off as a tool keeps no reports, so nothing here has anywhere to be
		// written; the read actions of the module ask this same question before they answer.
		if (!FullReportAccess::isWorktimeToolAvailable())
		{
			return $result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
		}

		if ($reportId <= 0)
		{
			$startResult = self::startReportOfTheSender();
			if (!$startResult->isSuccess())
			{
				return $result->addErrors($startResult->getErrors());
			}

			$reportId = (int)$startResult->getData()['id'];
		}

		$report = \CTimeManReportFull::GetByID($reportId)->Fetch();
		if (!is_array($report))
		{
			return $result->addError(new Error('Report not found.', 'REPORT_NOT_FOUND'));
		}

		$ownerId = (int)$report['USER_ID'];
		if (!FullReportAccess::canSendReportOf($ownerId))
		{
			return $result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
		}

		// A report already sent is answered with success and left alone: the client may repeat the send, and
		// the entry must not be published nor the manager notified twice. The V2 path returns early too.
		if ($report['ACTIVE'] === 'Y')
		{
			return $result;
		}

		$externalApproverIds = self::externalApproverIdsOf($ownerId);
		$fields = self::buildFields(
			$reportText,
			$ownerId,
			hasExternalApprover: !empty($externalApproverIds),
			isSentByOwner: FullReportAccess::currentUserId() === $ownerId,
		);

		if (!\CTimeManReportFull::Update($reportId, $fields) && !self::isSendStored($reportId, $fields))
		{
			return $result->addError(new Error('Report is not sent.', 'REPORT_NOT_SENT'));
		}

		self::cancelDelayOf($ownerId);
		self::clearCachesOf($ownerId);

		if (!empty($externalApproverIds))
		{
			self::publish($reportId, $ownerId);
		}

		return $result;
	}

	/**
	 * The first send of a period arrives without a report: nothing was saved before it, so the row is started
	 * here. Both reference paths do the same: the web path adds a row when its form carries no report id, and
	 * `timeman.V2.FullReport.submit` runs AddCommand. That very command creates the row instead of a legacy
	 * write of our own, because the period of a report is decided by the command and the mobile client sends
	 * none of it.
	 *
	 * The row belongs to the sender: a report of somebody else is only ever sent, never started, and the web
	 * path adds a row for the current user too.
	 */
	private static function startReportOfTheSender(): Result
	{
		$result = new Result();

		$senderId = FullReportAccess::currentUserId();
		if ($senderId <= 0)
		{
			return $result->addError(new Error('User is required.', 'USER_NOT_FOUND'));
		}

		// The row is created before the report of the send can be read, so the right to send is asked here
		// too: the check further along the path would only come after a row had already been written.
		if (!FullReportAccess::canSendReportOf($senderId))
		{
			return $result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
		}

		// A draft saved earlier is the report of this period, so it is sent instead of a second row being
		// created. The web path takes the draft the same way when its form carries no id.
		$draftId = self::draftIdOf($senderId);
		if ($draftId > 0)
		{
			return $result->setData(['id' => $draftId]);
		}

		try
		{
			$addResult = (new AddCommand(userId: $senderId))->run();
		}
		catch (\Throwable $error)
		{
			return $result->addError(new Error($error->getMessage(), 'REPORT_NOT_CREATED'));
		}

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		$createdId = (int)($addResult->getData()['id'] ?? 0);
		if ($createdId <= 0)
		{
			return $result->addError(new Error('Report is not created.', 'REPORT_NOT_CREATED'));
		}

		return $result->setData(['id' => $createdId]);
	}

	/**
	 * The report of the period that is waiting to be sent: the legacy layer keeps it as the last inactive row
	 * of its owner, and that is the row the web path picks when its form carries no id.
	 */
	private static function draftIdOf(int $ownerId): int
	{
		$draft = \CTimeManReportFull::GetList(
			['ID' => 'DESC'],
			[
				'=ACTIVE' => 'N',
				'USER_ID' => $ownerId,
			],
			['ID'],
			['nTopCount' => 1],
		)->Fetch();

		return is_array($draft) ? (int)$draft['ID'] : 0;
	}

	/**
	 * The mark of a sent report is the unconfirmed one: the web path writes it so that the author may still
	 * correct the text and the manager still sees the report as one to look at. An owner whose report has
	 * nobody to approve it approves it himself, which is the only case with a decision already made.
	 *
	 * The text of the report is written by its owner and by nobody else, the way the web path writes it
	 * (`tools/timeman.php`, `$USER->GetID() == $arReport['USER_ID']`). A manager keeps the right to send a
	 * report of a subordinate, but sends the text the subordinate wrote: the entry of the livefeed and the
	 * notification of the manager both name the owner as the author, so text of somebody else would be
	 * published under his name.
	 *
	 * @return array<string, mixed>
	 */
	private static function buildFields(
		string $reportText,
		int $ownerId,
		bool $hasExternalApprover,
		bool $isSentByOwner,
	): array
	{
		$fields = [
			'ACTIVE' => 'Y',
			'MARK' => self::MARK_UNCONFIRMED,
		];

		if ($isSentByOwner)
		{
			$fields['REPORT'] = self::sanitize($reportText);
		}

		if ($hasExternalApprover)
		{
			// A decision left on the row before the send would outlive it: a mark set on a draft keeps its
			// approval fields, and the send would store a report both approved and waiting to be looked at.
			// The V2 path clears them here for the same reason.
			$fields['APPROVE'] = 'N';
			$fields['APPROVER'] = 0;
			// The legacy write skips a field holding null (it asks isset) and empties one holding false.
			$fields['APPROVE_DATE'] = false;

			return $fields;
		}

		$fields['MARK'] = self::MARK_NEUTRAL;
		$fields['APPROVE'] = 'Y';
		$fields['APPROVER'] = $ownerId;
		$fields['APPROVE_DATE'] = ConvertTimeStamp(time(), 'FULL');

		return $fields;
	}

	/**
	 * The very sanitizer of the web path: the text of a report keeps its markup, so it is cleaned rather
	 * than encoded.
	 */
	private static function sanitize(string $reportText): string
	{
		$sanitizer = new \CBXSanitizer();
		$sanitizer->ApplyDoubleEncode(false);
		$sanitizer->SetLevel(\CBXSanitizer::SECURE_LEVEL_LOW);

		return $sanitizer->SanitizeHtml($reportText);
	}

	/**
	 * The approver of the old scheme is the FIRST manager of the owner and nobody else: the web form takes
	 * `array_slice(CTimeMan::GetUserManagers(), 0, 1)`, resolves that id against the user list and substitutes
	 * the owner when the row is not there, which it reads as "approve it yourself". The whole list must not be
	 * scanned instead: the notification of the legacy publication goes to the first manager anyway, so a
	 * second manager taken as an approver would announce the report to somebody the web path already refused.
	 *
	 * @return list<int>
	 */
	private static function externalApproverIdsOf(int $ownerId): array
	{
		$managerIds = \CTimeMan::GetUserManagers($ownerId);
		$managerId = is_array($managerIds) ? (int)(reset($managerIds) ?: 0) : 0;

		if ($managerId <= 0 || $managerId === $ownerId || !self::userExists($managerId))
		{
			return [];
		}

		return [$managerId];
	}

	/**
	 * The web path resolves the manager against the user list and drops him when the row is missing; the
	 * livefeed layer refuses an entry of an author it cannot find, so an unresolvable manager is nobody.
	 */
	private static function userExists(int $userId): bool
	{
		return UserTable::getRow([
			'select' => ['ID'],
			'filter' => ['=ID' => $userId],
		]) !== null;
	}

	/**
	 * CTimeManReportFull::Update() answers by the number of changed rows, so a write that changed nothing
	 * looks exactly like a write that failed. The stored row tells the two apart: a report that carries the
	 * send is a sent report, whatever the answer of the legacy write was.
	 *
	 * Every decision field of the send is compared, not the mark alone: the mark tells the two branches apart
	 * today, but that is a coincidence of the current set of marks rather than a rule to lean on. The text and
	 * the date of the approval are left out: the first one is not written for a manager, the second one is
	 * stored in a format of its own.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function isSendStored(int $reportId, array $fields): bool
	{
		$stored = \CTimeManReportFull::GetByID($reportId)->Fetch();
		if (!is_array($stored))
		{
			return false;
		}

		foreach (['ACTIVE', 'MARK', 'APPROVE', 'APPROVER'] as $field)
		{
			if (!array_key_exists($field, $fields))
			{
				continue;
			}

			if ((string)($stored[$field] ?? '') !== (string)$fields[$field])
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The report is stored by now, so a failure of the legacy publication must not answer the client with an
	 * error, but it must not pass unnoticed either, because nothing else records it. SendMessage() refuses by
	 * returning rather than by throwing: the legacy layer of the livefeed announces a rejected entry through
	 * $APPLICATION->ThrowException(), which is no PHP exception, and answers with false.
	 *
	 * The two refusals the legacy layer makes by design are asked about before it is called, so only real
	 * failures reach the journal: a portal without the livefeed module has nothing to publish into, and an
	 * owner whose AI reports mode is on is refused by the gate inside SendMessage() on every single send.
	 */
	private static function publish(int $reportId, int $ownerId): void
	{
		if (!Loader::includeModule('socialnetwork') || self::isAiReportsEnabledFor($ownerId))
		{
			return;
		}

		// The check of the send and the write of the row are not one operation, so two requests may both get
		// past it. SendMessage() adds an entry every time it is asked, so the report would end up with two
		// entries in the livefeed and two notifications; an entry that is already there means the report has
		// been announced, and the legacy decision path looks it up the same way.
		if (self::hasLivefeedEntry($reportId))
		{
			return;
		}

		try
		{
			$logId = (int)\CReportNotifications::SendMessage($reportId);
			$reason = $logId > 0 ? null : self::lastLegacyError();
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
			'Legacy livefeed publication failed for reportId={reportId}: {reason}',
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
	 * The entry of a report is created once and never removed, so its presence is what tells an announced
	 * report from one still to be announced. The filter is the one the legacy decision path uses.
	 */
	private static function hasLivefeedEntry(int $reportId): bool
	{
		$entry = \CSocNetLog::GetList(
			[],
			[
				'SOURCE_ID' => $reportId,
				'EVENT_ID' => 'report',
			],
			false,
			['nTopCount' => 1],
			['ID'],
		)->Fetch();

		return is_array($entry);
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

	/**
	 * The reminder about a report is postponed by its owner, so a sent report drops the postponement of the
	 * owner. The legacy CUserReportFull::CancelDelay() writes the field of the CURRENT user instead, which
	 * on a send by a manager takes the postponement away from the manager and leaves the owner with a
	 * reminder about a report he has already sent. The V2 path clears the owner too.
	 */
	private static function cancelDelayOf(int $ownerId): void
	{
		(new \CUser())->Update($ownerId, ['UF_DELAY_TIME' => '']);
	}

	/**
	 * The caches the web path drops after a send: the report info of the owner and his report settings. Both
	 * are addressed to the owner, the way the postponement above is.
	 */
	private static function clearCachesOf(int $ownerId): void
	{
		\CUserReportFull::clearReportCache($ownerId);
		\CReportSettings::clearCache($ownerId);
	}
}
