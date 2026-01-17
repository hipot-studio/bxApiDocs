<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Row\Assembler\Field\AutomatedSolution;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\Size;

final class TypesFieldAssembler extends FieldAssembler
{
	private const MAX_TYPES_IN_ROW = 10;

	private Router $router;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);

		$this->router = Container::getInstance()->getRouter();
	}

	protected function prepareColumn($value)
	{
		if (!is_array($value))
		{
			return '';
		}

		return $this->prepareContent($value);
	}

	private function prepareContent(array $typeIds): string
	{
		sort($typeIds);

		$typesToRenderAsLinks = $typeIds;
		$totalTypes = count($typesToRenderAsLinks);

		if ($totalTypes > self::MAX_TYPES_IN_ROW)
		{
			$typesToRenderAsLinks = array_slice($typesToRenderAsLinks, 0, self::MAX_TYPES_IN_ROW);
		}

		$links = [];
		$customSectionId = null;

		foreach ($typesToRenderAsLinks as $typeId)
		{
			$type = $this->getType($typeId);
			if (!$type)
			{
				continue;
			}

			$customSectionId ??= $type->getCustomSectionId();

			$url = $this->router->getItemListUrlInCurrentView($type->getEntityTypeId());

			$button = new Button([
				'text' => $type->getTitle(),
				'size' => Size::SMALL,
				'color' => Color::SECONDARY,
				'style' => AirButtonStyle::TINTED,
				'air' => true,
				'link' => $url?->getUri(),
			]);

			$links[] = $button->render();
		}

		$content = implode(' ', $links);
		if (!empty($links))
		{
			$content = '<div class="crm-automated-solution-grid-types-container">' . $content . '</div>';
		}

		if (!empty($links))
		{
			$text = Loc::getMessage(
				'CRM_GRID_ROW_ASSEMBLER_AUTOMATED_SOLUTION_TYPES_SHOW_LIST',
				['#TOTAL_COUNT#' => $totalTypes],
			);

			$url = $this->router->getExternalTypeListUrl()->addParams([
				'AUTOMATED_SOLUTION' => $customSectionId,
				'apply_filter' => 'Y',
			]);

			$button = new Button([
				'text' => $text,
				'size' => Size::SMALL,
				'color' => Color::LINK,
				'style' => AirButtonStyle::PLAIN,
				'air' => true,
				'link' => $url->getUri(),
				'classList' => ['crm-automated-solution-grid-types-show-list-button'],
			]);

			$content .= '<div>' . $button->render() . '</div>';
		}

		return $content;
	}

	private function getType(int $id): ?Type
	{
		return Container::getInstance()->getType($id);
	}
}
