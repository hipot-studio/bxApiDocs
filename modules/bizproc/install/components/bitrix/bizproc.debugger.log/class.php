<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class BizprocDebuggerLogComponent extends CBitrixComponent
{
	public function executeComponent()
	{
		global $APPLICATION;
		if ($this->arParams['SET_TITLE'] === true)
		{
			$APPLICATION->SetTitle(\Bitrix\Main\Localization\Loc::getMessage('BIZPROC_CMP_DEBUGGER_LOG_TITLE'));
		}

		// module
		if (!\Bitrix\Main\Loader::includeModule('bizproc'))
		{
			return $this->showError(
				\Bitrix\Main\Localization\Loc::getMessage('BIZPROC_MODULE_NOT_INSTALLED')
			);
		}

		// session
		$session = \Bitrix\Bizproc\Debugger\Session\Manager::getSessionById($this->arParams['SESSION_ID']);
		if (!$session)
		{
			return $this->showError(\Bitrix\Main\Localization\Loc::getMessage('BIZPROC_CMP_DEBUGGER_LOG_NO_SESSION'));
		}

		// rights
		$userId = (int)(\Bitrix\Main\Engine\CurrentUser::get()->getId());
		$documentType = $session->getParameterDocumentType();
		if (!\Bitrix\Bizproc\Debugger\Session\Manager::canUserDebugAutomation($userId, $documentType))
		{
			return $this->showError(
				\Bitrix\Main\Localization\Loc::getMessage('BIZPROC_CMP_DEBUGGER_LOG_NO_RIGHTS')
			);
		}

		// document
		if (!$session->getFixedDocument())
		{
			return $this->showError(
				\Bitrix\Main\Localization\Loc::getMessage('BIZPROC_CMP_DEBUGGER_LOG_NO_DOCUMENT')
			);
		}

		$this->arResult = [
			'Session' => $session->toArray(),
			'Logs' => $this->getLog($session),
			'WorkflowRobots' => $session->getRobots(),
		];

		return $this->includeComponentTemplate();
	}

	protected function getLog(\Bitrix\Bizproc\Debugger\Session\Session $session): array
	{
		$logs = [];

		$trackingResult = new \CBPTrackingServiceResult();
		$trackingResult->InitFromArray($session->getLogs());

		while ($log = $trackingResult->fetch())
		{
			/** @var $log \Bitrix\Bizproc\Service\Entity\EO_Tracking*/
			$values = $log->collectValues();
			$values['MODIFIED'] = (string)($values['MODIFIED']);
			$logs[] = $values;
		}

		return $logs;
	}

	private function showError(string $message)
	{
		$this->arResult['errorMessage'] = $message;
		$this->includeComponentTemplate('error');

		return null;
	}
}