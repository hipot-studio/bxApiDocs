<?php

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Integration\Superset\Stepper\DashboardOwner;
use Bitrix\BIConnector\Superset;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\Main\Web\Uri;
use Bitrix\BIConnector;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class ApacheSupersetDashboardController extends CBitrixComponent
{
	private const URL_TEMPLATE_LIST = 'list';
	private const URL_TEMPLATE_DETAIL = 'detail';

	public function onPrepareComponentParams($arParams)
	{
		if (!is_array($arParams))
		{
			$arParams = [];
		}

		$arParams['SEF_URL_TEMPLATES'] ??= [];
		$arParams['VARIABLE_ALIASES'] ??= [];

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		global $APPLICATION;
		$APPLICATION->setTitle(Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_TITLE'));

		$templateUrls = self::getTemplateUrls();

		$variables = [];
		$template = '';

		if ($this->arParams['SEF_MODE'] === 'Y')
		{
			[$template, $variables] = $this->processSefMode($templateUrls);
		}

		if ($template === self::URL_TEMPLATE_DETAIL)
		{
			$listUri = (new Uri('/' . $templateUrls[self::URL_TEMPLATE_LIST]))->toAbsolute()->getLocator();

			LocalRedirect($listUri);
		}

		$this->arResult['VARIABLES'] = $variables;

		$this->arResult['CAN_SEND_STARTUP_METRIC'] = self::canSendStartupSupersetMetric();

		$this->arResult['ERROR_MESSAGES'] = [];
		$this->arResult['FEATURE_AVAILABLE'] = true;
		$this->arResult['HELPER_CODE'] = null;

		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		)
		{
			$this->includeComponentTemplate('tool_disabled');

			return;
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_PERMISSION_ERROR');
			$this->includeComponentTemplate($template);

			return;
		}

		if (SupersetInitializer::getSupersetStatus() === SupersetInitializer::SUPERSET_STATUS_DELETED)
		{
			$this->includeComponentTemplate('create_superset');

			return;
		}

		if (!Superset\DomainLinkService::getInstance()->isLinked())
		{
			$this->arResult['IS_ADMIN'] = \Bitrix\BIConnector\Manager::isAdmin();
			$this->includeComponentTemplate('link_superset');

			return;
		}

		if (SupersetInitializer::isSupersetReady())
		{
			(new BIConnector\Access\Superset\Synchronizer(CurrentUser::get()->getId()))->sync();
		}

		if (!DashboardOwner::isFinished())
		{
			DashboardOwner::bind(60);
		}
		Application::getInstance()->addBackgroundJob(fn() => Superset\Updater\ClientUpdater::update());

		$superset = new SupersetController(Integrator::getInstance());
		$superset->initializeOrCheckSupersetStatus();

		$this->includeComponentTemplate($template);
	}

	private static function canSendStartupSupersetMetric(): bool
	{
		$supersetStatus = SupersetInitializer::getSupersetStatus();
		$metricAlreadySend = Option::get('biconnector', 'superset_startup_metric_send', false);

		return (
			$supersetStatus === SupersetInitializer::SUPERSET_STATUS_READY
			&& !$metricAlreadySend
		);
	}

	private static function getTemplateUrls(): array
	{
		return [
			self::URL_TEMPLATE_DETAIL => 'bi/dashboard/detail/',
			self::URL_TEMPLATE_LIST => 'bi/dashboard/',
		];
	}

	private function processSefMode($templateUrls): array
	{
		$templateUrls = CComponentEngine::MakeComponentUrlTemplates($templateUrls, $this->arParams['SEF_URL_TEMPLATES']);

		foreach ($templateUrls as $name => $url)
		{
			$this->arResult['PATH_TO'][strtoupper($name)] = $this->arParams['SEF_FOLDER'].$url;
		}

		$variableAliases = CComponentEngine::MakeComponentVariableAliases([], $this->arParams['VARIABLE_ALIASES']);

		$variables = [];
		$template = CComponentEngine::ParseComponentPath($this->arParams['SEF_FOLDER'], $templateUrls, $variables);

		if (!is_string($template) || !isset($templateUrls[$template]))
		{
			$template = key($templateUrls);
		}

		CComponentEngine::InitComponentVariables($template, [], $variableAliases, $variables);

		return [$template, $variables, $variableAliases];
	}

	public function isIframeMode(): bool
	{
		return $this->request->get('IFRAME') === 'Y' && $this->request->get('IFRAME_TYPE') === 'SIDE_SLIDER';
	}
}
