<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content\Date;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Grid\Task\Row\Content\Date;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\Calendar;
use Bitrix\Tasks\Util\Type\DateTime;
use CTasks;
use CTasksTools;
use CTimeZone;

/**
 * Class Deadline
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content\Date
 */
class Deadline extends Date
{
	private static $workTimeSettings = [];

	public function prepare()
	{
		$row = $this->getRowData();

		$state = $this->getDeadlineStateData();
		$timestamp = ($row['DEADLINE'] ? $this->getDateTimestamp($row['DEADLINE']) : $this->getCompanyWorkTimeEnd());

		$jsDeadline = DateTime::createFromTimestamp($timestamp - CTimeZone::GetOffset());
		$text = htmlspecialcharsbx(($state['text'] ?: $this->formatDate($row['DEADLINE'])));

		$canChange = ($state['clickable'] ?? true) && $row['ACTION']['CHANGE_DEADLINE'];
		$onClick = '';

		if ($canChange)
		{
			$taskId = (int)$row['ID'];
			$onClick = "onclick=\"BX.Tasks.GridActions.onDeadlineChangeClick({$taskId}, this, '{$jsDeadline}'); event.stopPropagation();\"";
		}

		$readonlyClass = !$canChange ? 'task-deadline-readonly' : '';
		$designClass = !empty($state['design']) ? '--' . $state['design'] : '--outline';

		return <<<HTML
			<div class="ui-chip $designClass --s --rounded --compact $readonlyClass" $onClick>
				<div class="ui-chip-text">$text</div>
			</div>
		HTML;
	}

	/**
	 * @return array
	 */
	public function getDeadlineStateData(): array
	{
		$row = $this->getRowData();

		return (new UI\Task\Deadline())->buildChipState($row['REAL_STATUS'], $row['DEADLINE']);
	}

	private function getCompanyWorkTimeEnd(): int
	{
		if (empty(self::$workTimeSettings))
		{
			self::$workTimeSettings = Calendar::getSettings();
		}

		return (new DateTime())->setTime(
			self::$workTimeSettings['HOURS']['END']['H'],
			self::$workTimeSettings['HOURS']['END']['M'],
			self::$workTimeSettings['HOURS']['END']['S']
		)->getTimestamp();
	}
}
