<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\ContentType;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity\HistoryGridLog;
use Bitrix\Tasks\V2\Internal\Service\Grid;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskHistoryGridParams;
use Bitrix\Tasks\V2\Public\Provider\TaskHistoryGridProvider;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Column\DataProvider\HistoryProvider;

class HistoryGrid extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.HistoryGrid.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Task $task,
		TaskHistoryGridProvider $taskHistoryGridProvider,
		PageNavigation $pageNavigation,
	): Component
	{
		return $this->getGridComponent(
			taskId: (int)$task->id,
			pageNavigation: $pageNavigation,
			taskHistoryGridProvider: $taskHistoryGridProvider
		);
	}

	/**
	 * @ajaxAction tasks.V2.Task.HistoryGrid.getData
	 */
	#[ContentType(type: FilterType::DisablePrefilter)]
	#[CloseSession]
	public function getDataAction(
		#[Permission\Read]
		Entity\Task $task,
		TaskHistoryGridProvider $taskHistoryGridProvider,
		PageNavigation $pageNavigation,
	): HttpResponse
	{
		$component =
			$this->getGridComponent(
				taskId: (int)$task->id,
				pageNavigation: $pageNavigation,
				taskHistoryGridProvider: $taskHistoryGridProvider
			)
		;

		$content = Json::decode($component->getContent());

		return (new HttpResponse())->setContent($content['data']['html']);
	}

	private function getGridComponent(
		int $taskId,
		PageNavigation $pageNavigation,
		TaskHistoryGridProvider $taskHistoryGridProvider,
	): Component
	{
		$gridSettings = new Grid\History\Settings\HistoryGridSettings();
		$grid = new Grid\History\HistoryGrid($gridSettings);
		$pageNavigation->setPageSize(20);

		$pager = Pager::buildFromPageNavigation($pageNavigation);
		$pager->setLimit($pageNavigation->getLimit() + 1);

		$params = new TaskHistoryGridParams(
			taskId: $taskId,
			userId: $this->userId,
			pager: $pager,
			checkAccess: false,
		);

		$historyLogCollection = $taskHistoryGridProvider->tail($params);

		$mappedHistoryLog = $historyLogCollection->map(fn (HistoryGridLog $log) => [
			HistoryProvider::TIME_COLUMN => $log->createdDateTs,
			HistoryProvider::AUTHOR_COLUMN => [
				'id' => $log->user?->id,
				'name' => $log->user?->name,
				'type' => $log->user?->type?->value,
			],
			HistoryProvider::CHANGE_TYPE_COLUMN => $log->field,
			HistoryProvider::CHANGE_VALUE_COLUMN => [
				'fromValue' => $log->fromValue,
				'toValue' => $log->toValue,
			],
		]);

		$grid->setRawRows(array_slice($mappedHistoryLog, 0, $pageNavigation->getLimit()));
		$pageNavigation->setRecordCount($pageNavigation->getOffset() + count($mappedHistoryLog));

		return new Component(
			componentName: 'bitrix:main.ui.grid',
			componentParams: $this->getComponentParams($grid, $pageNavigation),
		);
	}

	private function getComponentParams(
		Grid\History\HistoryGrid $grid,
		PageNavigation $pageNavigation,
	): array
	{
		return [
			'GRID_ID' => $grid->getSettings()->getID(),
			'COLUMNS' => $grid->prepareColumns(),
			'ROWS' => $grid->prepareRows(),
			'NAV_OBJECT' => $pageNavigation,
			'SHOW_GRID_SETTINGS_MENU' => false,
			'SHOW_SELECT_ALL_RECORDS_CHECKBOX' => false,
			'SHOW_TOTAL_COUNTER' => false,
			'SHOW_ROW_CHECKBOXES' => false,
			'SHOW_SELECTED_COUNTER' => false,
			'AJAX_MODE' => 'N',
		];
	}
}
