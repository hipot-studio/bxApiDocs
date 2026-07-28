<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Localization\Loc;

final class ChartFieldAssembler extends EntityLinkFieldAssembler
{
	protected function getEntityType(): string
	{
		return 'chart';
	}

	protected function getNameKey(): string
	{
		return 'EXTERNAL_CHART_NAME';
	}

	protected function getIdKey(): string
	{
		return 'EXTERNAL_CHART_ID';
	}

	protected function prepareColumn($value): string
	{
		$content = parent::prepareColumn($value);
		if ($content === '')
		{
			return '';
		}

		$source = (string)($value['SOURCE'] ?? '');
		[$iconName, $hint] = match ($source)
		{
			'chart' => ['statistics-arrow', (string)Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_CHART_HINT_CHART')],
			'filter' => ['filter-2-m', (string)Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_CHART_HINT_FILTER')],
			default => [null, null],
		};

		$icon = '';
		if ($iconName !== null)
		{
			$title = htmlspecialcharsbx($hint);
			$icon = <<<HTML
				<span
					class="ui-icon-set --{$iconName} biconnector-usage-stat-link__icon"
					data-hint="{$title}"
					data-hint-no-icon
				></span>
			HTML;
		}

		return <<<HTML
		<div style="display: inline-flex; flex-direction: row;">
			{$icon}
			<span>{$content}</span>
		</div>
		HTML;
	}
}
