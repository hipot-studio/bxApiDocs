<?php

namespace Bitrix\TimemanMobile\Internal\Dto;

use Bitrix\Mobile\Dto\Dto;

class WorkdayIntentBoxDto extends Dto
{
	public const SUPPRESS_REASON_SHIFTS_DISABLED = 'shiftsDisabled';

	public bool $canOpen = false;

	/** Diagnostic reason of a daily-condition refusal; not read by the client */
	public ?string $suppressReason = null;

	public bool $isDayStartCheckInEnabled = false;
	public int $chatIdWithAiAdvice = 0;
	public string $preselectedIntentId = 'WORKDAY';

	/** @var WorkdayIntentDto[] */
	public array $intentList = [];

	/** @var ShiftDto[] */
	public array $shifts = [];

	public ?WorkdayIntentScheduleDto $schedule = null;
}
