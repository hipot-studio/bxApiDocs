<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\ContentType;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Column\DataProvider\SystemHistoryProvider;
use Bitrix\Tasks\V2\Public\Provider\Template\Params\TemplateHistoryCountParams;
use Bitrix\Tasks\V2\Public\Provider\Template\Params\TemplateHistoryParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateHistoryProvider;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Internal\Service\Grid;

class History extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.History.getGrid
	 */
	#[CloseSession]
	public function getGridAction(
		#[Permission\Read]
		Entity\Template $template,
		TemplateHistoryProvider $templateHistoryProvider,
		PageNavigation $pageNavigation,
	): Component
	{
		return $this->getGridComponent(
			templateId: (int)$template->id,
			templateHistoryProvider: $templateHistoryProvider,
			pageNavigation: $pageNavigation,
		);
	}

	/**
	 * @ajaxAction tasks.V2.Template.History.getGridData
	 */
	#[ContentType(type: FilterType::DisablePrefilter)]
	#[CloseSession]
	public function getGridDataAction(
		#[Permission\Read]
		Entity\Template $template,
		TemplateHistoryProvider $templateHistoryProvider,
		PageNavigation $pageNavigation,
	): HttpResponse
	{
		$component =
			$this->getGridComponent(
				templateId: (int)$template->id,
				templateHistoryProvider: $templateHistoryProvider,
				pageNavigation: $pageNavigation,
			)
		;

		$content = Json::decode($component->getContent());

		return (new HttpResponse())->setContent($content['data']['html']);
	}

	/**
	 * @ajaxAction tasks.V2.Template.History.getCount
	 */
	public function getCountAction(
		#[Permission\Read]
		Entity\Template $template,
		TemplateHistoryProvider $templateHistoryProvider,
	): array
	{
		return [
			'count' => $templateHistoryProvider->count(
				new TemplateHistoryCountParams(
					templateId: (int)$template->id,
					userId: $this->userId,
					checkAccess: false,
				)
			),
		];
	}

	private function getGridComponent(
		int $templateId,
		TemplateHistoryProvider $templateHistoryProvider,
		PageNavigation $pageNavigation,
	): Component
	{
		$gridSettings = new Grid\SystemHistory\Settings\SystemHistoryGridSettings();
		$grid = new Grid\SystemHistory\SystemHistoryGrid($gridSettings);

		$pageNavigation->setPageSize(8);
		$pager = Pager::buildFromPageNavigation($pageNavigation);
		$pager->setLimit($pageNavigation->getLimit() + 1);

		$systemLogs = $templateHistoryProvider->tail(
			new TemplateHistoryParams(
				templateId: $templateId,
				userId: $this->userId,
				pager: $pager,
				checkAccess: false,
			)
		);

		$mappedSystemLogs = $systemLogs->map(fn (Entity\SystemHistoryLog $log) => [
//			SystemHistoryProvider::TYPE_COLUMN => $log->type,
			SystemHistoryProvider::TIME_COLUMN => $log->createdDateTs,
			SystemHistoryProvider::MESSAGE_COLUMN => [
				'message' => $log->message,
				'link' => $log->link,
				'errors' => $log->errors,
			],
//			SystemHistoryProvider::ERRORS_COLUMN => $log->errors,
		]);

		$grid->setRawRows(array_slice($mappedSystemLogs, 0, $pageNavigation->getLimit()));
		$pageNavigation->setRecordCount($pageNavigation->getOffset() + count($mappedSystemLogs));

		return new Component(
			componentName: 'bitrix:main.ui.grid',
			componentParams: $this->getComponentParams($grid, $pageNavigation),
		);
	}

	private function getComponentParams(
		Grid\SystemHistory\SystemHistoryGrid $grid,
		PageNavigation $pageNavigation,
	): array
	{
		return [
			'GRID_ID' => $grid->getSettings()->getID(),
			'COLUMNS' => $grid->prepareColumns(),
			'ROWS' => $grid->prepareRows(),
			'NAV_OBJECT' => $pageNavigation,
			'SHOW_ROW_ACTIONS_MENU' => false,
			'SHOW_GRID_SETTINGS_MENU' => false,
			'SHOW_SELECT_ALL_RECORDS_CHECKBOX' => false,
			'SHOW_TOTAL_COUNTER' => false,
			'SHOW_ROW_CHECKBOXES' => false,
			'SHOW_SELECTED_COUNTER' => false,
			'AJAX_MODE' => 'N',
		];
	}
}
