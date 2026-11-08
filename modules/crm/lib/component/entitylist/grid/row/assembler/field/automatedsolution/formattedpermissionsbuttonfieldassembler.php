<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Row\Assembler\Field\AutomatedSolution;

use Bitrix\Crm\Integration\Analytics\Builder\Security\ViewEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Web\Json;
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Buttons\Size;

final class FormattedPermissionsButtonFieldAssembler extends FieldAssembler
{
	private readonly Router $router;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);

		$this->router = Container::getInstance()->getRouter();

		Asset::getInstance()->addJs('/bitrix/js/crm/common.js');
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->prepareContent($row['data'][$columnId]);
		}

		return $row;
	}

	private function prepareContent(string $automatedSolutionCode): string
	{
		$crmPermsViewEventBuilder = (new ViewEvent())
			->setSection(Dictionary::SECTION_AUTOMATION)
			->setSubSection(Dictionary::SUB_SECTION_CONTROL_PANEL)
		;

		$url = (string)$crmPermsViewEventBuilder->buildUri($this->router->getCustomSectionPermissionsUrl($automatedSolutionCode));

		$button = new Button([
			'text' => Loc::getMessage('CRM_GRID_ROW_ASSEMBLER_AUTOMATED_SOLUTION_PERMISSIONS_BUTTON_TITLE'),
			'size' => Size::MEDIUM,
			'color' => Color::PRIMARY,
			'style' => AirButtonStyle::OUTLINE,
			'air' => true,
			'link' => $url,
			'dataset' => [
				'slider-ignore-autobinding' => 'true',
			],
			'onclick' => $this->createOpenSliderHandler($url),
		]);

		return $button->render();
	}

	/**
	 * Anchor rules of the side panel are bound once per page render, so a solution created without
	 * a page reload has no rule for its section root and its link would navigate instead of opening.
	 * The options are the ones of the rule the handler stands in for.
	 *
	 * @see \Bitrix\Crm\Service\Router\Page\PermissionsPage::getSidePanelAnchorRules()
	 */
	private function createOpenSliderHandler(string $url): JsCode
	{
		$urlLiteral = Json::encode($url);

		// the button stays a link, so a modifier click and a page without the side panel are left to the browser
		return new JsCode(<<<JS
			var event = arguments[1];
			var canOpenSlider = BX.Crm && BX.Crm.Page && window.top.BX && window.top.BX.SidePanel && window.top.BX.SidePanel.Instance;
			if (event.which === 1 && !event.ctrlKey && !event.metaKey && canOpenSlider)
			{
				event.preventDefault();
				BX.Crm.Page.openSlider($urlLiteral, { cacheable: false, allowChangeHistory: false, customLeftBoundary: 0 });
			}
			JS
		);
	}
}
