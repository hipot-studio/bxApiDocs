<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\Attribute\ShiftsEnabled;
// todo: remove IntranetOnly from timemanmobile and use it from intranet module when ready
use Bitrix\TimemanMobile\Infrastructure\Controllers\ActionFilters\Attributes\IntranetOnly;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentBoxDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentScheduleDto;
use Bitrix\TimemanMobile\Public\Providers\WorkdayIntentProvider;

final class WorkdayIntent extends Base
{
	/**
	 * @ajaxAction timemanmobile.WorkdayIntent.getWorkdayIntentBoxData
	 * @return WorkdayIntentBoxDto
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[ShiftsEnabled]
	public function getWorkdayIntentBoxDataAction(): WorkdayIntentBoxDto
	{
		return (new WorkdayIntentProvider())->getWorkdayIntentBoxData();
	}

	/**
	 * @ajaxAction timemanmobile.WorkdayIntent.addAbsence
	 * @param string $absenceType
	 * @param array $shifts
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[ShiftsEnabled]
	public function addAbsenceAction(string $absenceType, array $shifts): array
	{
		$result = (new WorkdayIntentProvider())->addAbsence($absenceType, $shifts);

		return [
			'data' => $result->getData(),
			'errors' => $result->getErrors(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.WorkdayIntent.registerShow
	 * @param string $periodKey
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[ShiftsEnabled]
	public function registerShowAction(string $periodKey): array
	{
		// RegisterShowCommand has no #[InArray]/validation today, so no CommandValidationException
		// is thrown here. If validation is ever added to the show command, wrap this call in a
		// try/catch for CommandValidationException, the same way registerOutcomeAction does.
		$result = (new WorkdayIntentProvider())->registerShow($periodKey);

		return $this->formatScheduleResult($result);
	}

	/**
	 * @ajaxAction timemanmobile.WorkdayIntent.registerOutcome
	 * @param string $periodKey
	 * @param string $outcome
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[ShiftsEnabled]
	public function registerOutcomeAction(string $periodKey, string $outcome): array
	{
		try
		{
			$result = (new WorkdayIntentProvider())->registerOutcome($periodKey, $outcome);
		}
		catch (CommandValidationException $e)
		{
			return ['data' => [], 'errors' => $e->getValidationErrors()];
		}

		return $this->formatScheduleResult($result);
	}

	private function formatScheduleResult(Result $result): array
	{
		$data = $result->getData();
		if (isset($data['schedule']))
		{
			$data['schedule'] = WorkdayIntentScheduleDto::make($data['schedule']->toArray());
		}

		return [
			'data' => $data,
			'errors' => $result->getErrors(),
		];
	}
}
