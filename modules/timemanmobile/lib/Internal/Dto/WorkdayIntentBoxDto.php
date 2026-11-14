<?php

namespace Bitrix\TimemanMobile\Internal\Dto;

use Bitrix\Mobile\Dto\Dto;

class WorkdayIntentBoxDto extends Dto
{
	public const SUPPRESS_REASON_SHIFTS_DISABLED = 'shiftsDisabled';

	// Refusal reasons in the order of the checks: admission, workday state, show window, daily conditions
	public const REFUSAL_REASON_NO_USER = 'noUser';
	public const REFUSAL_REASON_SHIFTS_DISABLED = self::SUPPRESS_REASON_SHIFTS_DISABLED;
	public const REFUSAL_REASON_TIMEMAN_UNAVAILABLE = 'timemanUnavailable';
	public const REFUSAL_REASON_MOBILE_MANAGEMENT_DISABLED = 'mobileManagementDisabled';
	public const REFUSAL_REASON_DAY_OPENED = 'dayOpened';
	public const REFUSAL_REASON_DAY_PAUSED = 'dayPaused';
	public const REFUSAL_REASON_DAY_EXPIRED = 'dayExpired';
	public const REFUSAL_REASON_DAY_REOPEN_ONLY = 'dayReopenOnly';
	public const REFUSAL_REASON_START_UNAVAILABLE = 'startUnavailable';
	public const REFUSAL_REASON_NO_SHIFT = 'noShift';
	public const REFUSAL_REASON_TOO_EARLY = 'tooEarly';
	public const REFUSAL_REASON_PERIOD_ENDED = 'periodEnded';
	public const REFUSAL_REASON_SHOW_LIMIT = 'showLimit';
	public const REFUSAL_REASON_DISMISS_LIMIT = 'dismissLimit';
	public const REFUSAL_REASON_SUPPRESSED = 'suppressed';
	public const REFUSAL_REASON_TARGET_ACTION_DONE = 'targetActionDone';
	public const REFUSAL_REASON_RETRY_INTERVAL = 'retryInterval';
	public const REFUSAL_REASON_SHIFT_NOT_STARTABLE = 'shiftNotStartable';
	public const REFUSAL_REASON_DAY_OFF = 'dayOff';
	public const REFUSAL_REASON_ABSENCE = 'absence';

	public bool $canOpen = false;

	/** Diagnostic reason of a daily-condition refusal; not read by the client */
	public ?string $suppressReason = null;

	/** Code of the first failed check when the box is refused, null when it is shown */
	public ?string $refusalReason = null;

	/** Unix time the server made the decision at */
	public int $checkedAt = 0;

	public bool $isDayStartCheckInEnabled = false;
	public int $chatIdWithAiAdvice = 0;
	public string $preselectedIntentId = 'WORKDAY';

	/** @var WorkdayIntentDto[] */
	public array $intentList = [];

	/** @var ShiftDto[] */
	public array $shifts = [];

	public ?WorkdayIntentScheduleDto $schedule = null;
}
