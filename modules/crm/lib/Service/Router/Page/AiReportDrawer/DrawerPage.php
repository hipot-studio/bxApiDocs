<?php

namespace Bitrix\Crm\Service\Router\Page\AiReportDrawer;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Main\Routing\RoutingConfiguration;
use Bitrix\Main\Localization\Loc;

final class DrawerPage extends AbstractPage
{
	protected bool $isPlainView = true;

	public const SCENARIO_CALL_ASSESSMENT = 'call-assessment';
	public const SCENARIO_SUMMARY_HISTORY = 'summary-history';
	public const SCENARIO_TRANSCRIPT = 'transcript';
	private const COMPONENT_NAME = 'bitrix:crm.ai.report.drawer.wrapper';

	public function __construct(
		private readonly string $scenario,
		\Bitrix\Main\HttpRequest $request,
		?Scope $currentScope,
	)
	{
		parent::__construct($request, $currentScope);

		$this->title = Loc::getMessage('CRM_AI_REPORT_DRAWER_PAGE_TITLE');
	}

	public function component(): Contract\Component
	{
		return new Component(
			name: self::COMPONENT_NAME,
			parameters: [
				'scenario' => $this->scenario,
			],
		);
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->isUsePadding = false;
		$sidePanel->isUseBackgroundContent = false;
		$sidePanel->isPlainView = true;
		$sidePanel->isUseToolbar = false;
		$sidePanel->isHideToolbar = true;
		$sidePanel->pageMode = false;
	}

	public static function routes(): array
	{
		return [
			(new Route('ai-report-drawer/{scenario}/'))
				->configure(static function (RoutingConfiguration $configuration) {
					$pattern = implode('|', [
						self::SCENARIO_CALL_ASSESSMENT,
						self::SCENARIO_SUMMARY_HISTORY,
						self::SCENARIO_TRANSCRIPT,
					]);
					$configuration->where('scenario', $pattern);
				})
			,
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
		];
	}

	public static function getSidePanelAnchorRules(): array
	{
		return [
			(new SidePanelAnchorRule('ai-report-drawer/(call-assessment|summary-history|transcript)/?'))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) {
					$options
						->setWidth(800)
						->setCacheable(false)
						->setAllowChangeHistory(false)
						->setContentClassName('crm-ai-report-drawer-slider')
						->setCopyLinkLabel(true)
						->setNewWindowLabel(false)
					;
				})
			,
		];
	}
}
