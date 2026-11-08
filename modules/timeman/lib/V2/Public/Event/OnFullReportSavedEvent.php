<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Event;

use Bitrix\Main\Event;

/**
 * Announces that a full report was written: created, changed, sent or turned back into a draft.
 */
final class OnFullReportSavedEvent extends Event
{
	public const TYPE = 'OnFullReportSaved';

	/**
	 * Creates the event about the report of the owner.
	 *
	 * @param int $userId Owner of the report.
	 * @param int $reportId
	 * @param bool $isSent Whether the report is sent rather than kept as a draft.
	 */
	public function __construct(int $userId, int $reportId, bool $isSent)
	{
		parent::__construct(
			'timeman',
			self::TYPE,
			[
				'userId' => $userId,
				'reportId' => $reportId,
				'isSent' => $isSent,
			],
		);
	}

	/**
	 * Sends the event about the report of the owner; a report without an owner or an id is announced to nobody.
	 *
	 * @param int $userId Owner of the report.
	 * @param int $reportId
	 * @param bool $isSent Whether the report is sent rather than kept as a draft.
	 * @return void
	 */
	public static function sendEvent(int $userId, int $reportId, bool $isSent): void
	{
		if ($userId <= 0 || $reportId <= 0)
		{
			return;
		}

		(new self($userId, $reportId, $isSent))->send();
	}
}
