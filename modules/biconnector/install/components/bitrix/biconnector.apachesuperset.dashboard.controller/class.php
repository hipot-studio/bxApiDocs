<?php

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Superset\Synchronizer;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Integration\Superset\Stepper\DashboardOwner;
use Bitrix\BIConnector\Superset;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\Facade\Toolbar;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class ApacheSupersetDashboardController extends CBitrixComponent
{
	private const URL_TEMPLATE_LIST = 'list';
	private const URL_TEMPLATE_DETAIL = 'detail';

	public function onPrepareComponentParams($params)
	{
		if (!is_array($params))
		{
			$params = [];
		}

		$params['SEF_URL_TEMPLATES'] = $params['SEF_URL_TEMPLATES'] ?? [];
		$params['VARIABLE_ALIASES'] = $params['VARIABLE_ALIASES'] ?? [];

		return parent::onPrepareComponentParams($params);
	}

	public function executeComponent()
	{
		global $APPLICATION;
		$APPLICATION->setTitle(Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_TITLE'));

		(new Synchronizer(CurrentUser::get()->getId()))->sync();

		$templateUrls = self::getTemplateUrls();

		$variables = [];
		$template = '';

		if ($this->arParams['SEF_MODE'] === 'Y')
		{
			[$template, $variables] = $this->processSefMode($templateUrls);
		}

		$this->arResult['VARIABLES'] = $variables;

		$this->arResult['CAN_SEND_STARTUP_METRIC'] = self::canSendStartupSupersetMetric();

		$this->arResult['ERROR_MESSAGES'] = [];
		$this->arResult['FEATURE_AVAILABLE'] = true;
		$this->arResult['TOOLS_AVAILABLE'] = true;
		$this->arResult['HELPER_CODE'] = null;

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_PERMISSION_ERROR');
			$this->includeComponentTemplate($template);

			return;
		}

		if (Loader::includeModule('bitrix24'))
		{
			if (!\Bitrix\Bitrix24\Feature::isFeatureEnabled('bi_constructor'))
			{
				if (SupersetInitializer::isSupersetExist())
				{
					$this->initDeleteButton();
				}
				$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_TARIFF_ERROR');
				$this->arResult['FEATURE_AVAILABLE'] = false;
				$this->arResult['HELPER_CODE'] = 'limit_crm_BI_constructor';
				$this->includeComponentTemplate($template);

				return;
			}

			if (SupersetInitializer::getSupersetStatus() === SupersetInitializer::SUPERSET_STATUS_DELETED_BY_CLIENT)
			{
				$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_DISABLED_MANUALLY');
				$this->arResult['ERROR_DESCRIPTIONS'][] = '';
				$this->includeComponentTemplate($template);

				return;
			}
		}
		else
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_BOX_ERROR');
			$this->includeComponentTemplate($template);

			return;
		}

		if (
			class_exists('Bitrix\Intranet\Settings\Tools\ToolsManager')
			&& !ToolsManager::getInstance()->checkAvailabilityByMenuId('crm_bi')
		)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_PERMISSION_ERROR');
			$this->arResult['TOOLS_AVAILABLE'] = false;
			$this->arResult['HELPER_CODE'] = 'limit_BI_off';
		}

		if (!DashboardOwner::isFinished())
		{
			DashboardOwner::bind(60);
		}
		Application::getInstance()->addBackgroundJob(fn() => Superset\Updater\ClientUpdater::update());

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

	private function initDeleteButton(): void
	{
		if (Superset\UI\UIHelper::needShowDeleteInstanceButton())
		{
			$clearButton = new Buttons\Button([
				'color' => Buttons\Color::DANGER,
				'text' => Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_CONTROLLER_CLEAR_BUTTON'),
				'click' => new Buttons\JsCode(
					'BX.BIConnector.ApacheSupersetCleaner.Instance.handleButtonClick(this)'
				),
			]);
			Toolbar::addButton($clearButton);
		}
	}

	private static function getTemplateUrls(): array
	{
		return [
			self::URL_TEMPLATE_LIST => 'bi/dashboard/',
			self::URL_TEMPLATE_DETAIL => 'bi/dashboard/detail/',
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
