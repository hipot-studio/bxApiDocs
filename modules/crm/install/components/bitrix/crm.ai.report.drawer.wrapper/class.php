<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	return;
}

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Router\Page\AiReportDrawer\DrawerPage;

class CrmAiReportDrawerWrapper extends \Bitrix\Crm\Component\Base
{

	public function executeComponent(): void
	{
		$this->init();

		$ajaxAction = $this->resolveAjaxAction((string)($this->arParams['scenario'] ?? ''));
		if ($ajaxAction === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return;
		}

		$this->arResult['APP_PARAMS'] = [
			'ajaxAction' => $ajaxAction,
			'drawerRequest' => [
				'activityId' => $this->request->get('activityId'),
				'ownerTypeId' => $this->request->get('ownerTypeId'),
				'ownerId' => $this->request->get('ownerId'),
				'jobId' => $this->request->get('jobId'),
				'assessmentSettingsId' => $this->request->get('assessmentSettingsId'),
			],
		];

		$this->includeComponentTemplate();
	}

	private function resolveAjaxAction(string $scenario): ?string
	{
		return match ($scenario)
		{
			DrawerPage::SCENARIO_CALL_ASSESSMENT => 'crm.timeline.aireportdrawer.loadCallAssessmentDrawer',
			DrawerPage::SCENARIO_SUMMARY_HISTORY => 'crm.timeline.aireportdrawer.loadSummaryHistoryDrawer',
			DrawerPage::SCENARIO_TRANSCRIPT => 'crm.timeline.aireportdrawer.loadTranscriptDrawer',
			default => null,
		};
	}

}
