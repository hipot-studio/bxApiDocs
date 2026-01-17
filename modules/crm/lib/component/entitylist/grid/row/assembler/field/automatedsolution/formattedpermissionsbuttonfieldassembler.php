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
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
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
		]);

		return $button->render();
	}
}