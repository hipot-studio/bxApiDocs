<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\IntranetOnly;
use Bitrix\StaffTrackMobile\Infrastructure\Controllers\Base;
use Bitrix\StaffTrackMobile\Internal\WorkReport\WorkReportCounter;
use Bitrix\StaffTrackMobile\Public\Features\WorkReportCounterFeature;

/**
 * The counter of the unsent work report of the current user, synced by the device with the state of the report.
 *
 * No gate of the check-in or of the region: where the new check-in is off the rule itself gives 0, and the sync
 * puts out a counter written before. The region is no condition of the counter: where the geo of the check-in is
 * not allowed, the section of the work reports stays open, and its counter is synced as anywhere else.
 */
final class WorkReport extends Base
{
	public const ERROR_BUSY = 'WORK_REPORT_COUNTER_BUSY';

	private ?WorkReportCounter $counter;

	public function __construct(?Request $request = null, ?WorkReportCounter $counter = null)
	{
		parent::__construct($request);

		$this->counter = $counter;
	}

	/**
	 * Writes the counter of the unsent work report of the current user by the state of now and answers it with the
	 * time of the server and whether the counter applies to the user at all.
	 *
	 * The time is taken before the wait for the lock and the calculation: the device passes it on with the value,
	 * and a pull that came after the start of the sync is not overwritten by its answer. serverTimeUnix carries it
	 * with microseconds, as pulls of the counters do, so a pull sent earlier within the same second does not outdate
	 * the answer either; serverTime keeps whole seconds for a client that reads no more.
	 *
	 * Where the switch is off, the counter is put out instead and answered as not applicable. While another request
	 * holds the counter, either way the answer is busy and nothing is written: the device keeps what it has and asks
	 * again.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.WorkReport.syncCounter
	 * @return array{value: int, serverTime: int, serverTimeUnix: float, isApplicable: bool}|null
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function syncCounterAction(): ?array
	{
		$serverTime = time();
		$serverTimeUnix = microtime(true);

		if (!(new WorkReportCounterFeature())->isEnabled())
		{
			if (!$this->getCounter()->clear())
			{
				$this->addBusyError();

				return null;
			}

			return [
				'value' => 0,
				'serverTime' => $serverTime,
				'serverTimeUnix' => $serverTimeUnix,
				'isApplicable' => false,
			];
		}

		$state = $this->getCounter()->sync();
		if ($state === null)
		{
			$this->addBusyError();

			return null;
		}

		return [
			'value' => $state['value'],
			'serverTime' => $serverTime,
			'serverTimeUnix' => $serverTimeUnix,
			'isApplicable' => $state['isApplicable'],
		];
	}

	private function addBusyError(): void
	{
		$this->addError(new Error(
			'The counter of the work report is being written by another request',
			self::ERROR_BUSY,
		));
	}

	/**
	 * Built on demand: the controller is created for hits that never reach the action.
	 */
	private function getCounter(): WorkReportCounter
	{
		return $this->counter ??= new WorkReportCounter();
	}
}
