<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Row\Assembler\Field\AutomatedSolution;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;
use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Grid\Settings;

final class LinkToTypeListFieldAssembler extends FieldAssembler
{
	private readonly Router $router;
	private static array $isRestricted = [];

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);

		$this->router = Container::getInstance()->getRouter();
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		$automatedSolutionId = (int)($row['data']['ID'] ?? null);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->prepareContent(
				$automatedSolutionId,
				(string)($row['data'][$columnId] ?? null),
				(int)($row['data']['SOURCE_ID'] ?? 0),
			);
		}

		return $row;
	}

	private function prepareContent(int $automatedSolutionId, string $automatedSolutionTitle, int $sourceId): string
	{
		$safeTitle = htmlspecialcharsbx($automatedSolutionTitle);
		$externalTypeListUri = $this->router->getFirstItemListUrlInAutomatedSolution($automatedSolutionId);

		if ($externalTypeListUri === null)
		{
			$externalTypeListUri = $this->router->getExternalTypeListUrl();

			$externalTypeListUri->addParams([
				'apply_filter' => 'Y',
				'AUTOMATED_SOLUTION' => $automatedSolutionId,
			]);
		}

		$safeExternalTypeListUri = htmlspecialcharsbx((string)\CUtil::JSEscape($externalTypeListUri));

		$iconHtml = '';
		$lockIconHtml = '';
		if ($this->isImported($sourceId))
		{
			$iconHtml = '<span class="ui-icon-set --o-market"></span>';

			if ($this->isRestricted($automatedSolutionId))
			{
				$lockIconHtml = '<span class="ui-icon-set --lock"></span>';
			}
		}

		return "<span class=\"crm-automated-solution-grid-types-title\">$iconHtml<a href=\"{$safeExternalTypeListUri}\">{$safeTitle}</a>$lockIconHtml</span>";
	}

	private function isImported(int $sourceId): bool
	{
		return AutomatedSolutionTable::isImportedFromMarketplace($sourceId);
	}

	private function isRestricted(int $automatedSolutionId): bool
	{
		if (!isset(self::$isRestricted[$automatedSolutionId]))
		{
			self::$isRestricted[$automatedSolutionId] = CapabilityAccessChecker::getInstance()->isLockedAutomatedSolution($automatedSolutionId);
		}

		return self::$isRestricted[$automatedSolutionId];
	}
}