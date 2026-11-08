<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content\Date;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Grid\ControlButtonTrait;
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
	use ControlButtonTrait;

	private static $workTimeSettings = [];

	public function prepare()
	{
		$row = $this->getRowData();

		$state = $this->getDeadlineStateData();
		$timestamp = ($row['DEADLINE'] ? $this->getDateTimestamp($row['DEADLINE']) : $this->getCompanyWorkTimeEnd());

		$jsDeadline = DateTime::createFromTimestamp($timestamp - CTimeZone::GetOffset());
		$text = htmlspecialcharsbx(($state['text'] ?: $this->formatDate($row['DEADLINE'])));

		$canChange = ($state['clickable'] ?? true) && $row['ACTION']['CHANGE_DEADLINE'];
		$designClass = !empty($state['design']) ? '--' . $state['design'] : '--outline';

		if ($canChange)
		{
			$taskId = (int)$row['ID'];

			return $this->renderControlButton(
				[
					'class' => "ui-chip {$designClass} --s --rounded --compact",
					'aria-haspopup' => 'dialog',
					'aria-expanded' => 'false',
				],
				'<div class="ui-chip-text">' . $text . '</div>',
				"BX.Tasks.GridActions.onDeadlineChangeClick({$taskId}, this, '{$jsDeadline}'); event.stopPropagation();"
			);
		}

		return <<<HTML
			<div class="ui-chip $designClass --s --rounded --compact task-deadline-readonly">
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
