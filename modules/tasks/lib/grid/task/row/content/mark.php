<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\Integration\Bitrix24;
use Bitrix\Tasks\Integration\Bitrix24\FeatureDictionary;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit;

/**
 * Class Mark
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content
 */
class Mark extends Content
{
	use ControlButtonTrait;

	/**
	 * @return false|string
	 * @throws Main\ArgumentException
	 */
	public function prepare()
	{
		ob_start();

		$row = $this->getRowData();

		$mark = (isset($row['MARK']) ? strtoupper($row['MARK']) : false);
		$markClass = '';
		$addInReportClass = ((isset($row['ADD_IN_REPORT']) && $row['ADD_IN_REPORT'] === 'Y') ? 'task-in-report' : '');

		if ($mark)
		{
			$markClass = 'task-grade-'.($mark === 'N' ? 'minus' : 'plus');
		}

		$mark = ($mark === 'N' || $mark === 'P' ? $mark : 'NULL');
		$markText = match($mark)
		{
			'N' => 'TASKS_GRID_TASK_ROW_CONTENT_MARK_N_MSGVER_1',
			'P' => 'TASKS_GRID_TASK_ROW_CONTENT_MARK_P_MSGVER_1',
			default => 'TASKS_GRID_TASK_ROW_CONTENT_MARK_NONE_MSGVER_1'
		};

		if ($row['ACTION']['EDIT'])
		{
			if ($this->isRestricted())
			{
				$this->renderLock();
			}
			else
			{
				$taskId = (int)$row['ID'];
				$encodedValue = Json::encode(['listValue' => $mark]);
				echo $this->renderControlButton(
					[
						'id' => "task-grade-button-{$taskId}",
						'class' => "task-grade-and-report {$markClass} {$addInReportClass}",
						'aria-haspopup' => 'menu',
						'aria-expanded' => 'false',
						'aria-label' => Loc::getMessage($markText),
						'title' => Loc::getMessage($markText),
					],
					'<span class="task-grade-and-report-inner"><i class="task-grade-and-report-icon" aria-hidden="true"></i></span>',
					"event.stopPropagation(); return BX.Tasks.GridActions.onMarkChangeClick({$taskId}, this, {$encodedValue});",
					"'"
				);
			}
		}
		else
		{
			$accessibleNameAttributes = '';
			if ($mark !== 'NULL')
			{
				$accessibleNameAttributes = ' role="img" aria-label="' . htmlspecialcharsbx(Loc::getMessage($markText)) . '"';
			}
			?>
			<span class="<?=$markClass?> <?=$addInReportClass?>"<?=$accessibleNameAttributes?>
				  title="<?=Loc::getMessage($markText)?>">
				<span class="task-grade-and-report-inner task-grade-and-report-default-cursor">
					<i class="task-grade-and-report-icon task-grade-and-report-default-cursor"></i>
				</span>
			</span>
			<?php
		}

		return ob_get_clean();
	}

	private function isRestricted(): bool
	{
		return !Bitrix24::checkFeatureEnabled(FeatureDictionary::TASK_RATE);
	}

	private function renderLock(): void
	{
		echo $this->renderControlButton(
			[
				'style' => 'cursor: pointer',
				'class' => 'tasks-list-tariff-lock-container',
				'aria-label' => Loc::getMessage('TASKS_GRID_TASK_ROW_CONTENT_MARK_TARIFF_LOCK_MSGVER_1'),
			],
			'<span class="task-list-tariff-lock" aria-hidden="true"></span>',
			'event.stopPropagation(); ' . Limit::getLimitLockClick(FeatureDictionary::TASK_RATE, null)
		);
	}
}