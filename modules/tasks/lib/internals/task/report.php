<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Task;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Report extends Base
{
	public const YES = 'Y';
	public const NO = 'N';

	public static function getMessage(string $report): ?string
	{
		return Loc::getMessage("TASKS_REPORT_{$report}");
	}

	public static function getTitle(): ?string
	{
		return Loc::getMessage('TASKS_REPORT_TITLE');
	}
}
