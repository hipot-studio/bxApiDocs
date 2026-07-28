<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\LoadIndicator;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\LoadLevel;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\TriggeredFactor;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\TriggeredFactorInfo;
use Bitrix\BIConnector\Internal\Services\LoadIndicator\LoadIndicatorCalculator;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class LoadLevelFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];
		$data = $row['data'] ?? [];

		// Query time is not filled or query is in progress
		if ((float)($data['REAL_TIME'] ?? 0.0) === 0.0)
		{
			return $row;
		}

		$calculator = new LoadIndicatorCalculator();
		$result = $calculator->calculate($data);
		$html = $this->renderPill(LoadIndicator::createFromCheckResult($result));

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}

	private function renderPill(LoadIndicator $indicator): string
	{
		$label = $this->getLevelLabel($indicator->getLevel());
		$color = $this->getLevelColor($indicator->getLevel());
		$hintContent = $this->renderHintContent($indicator);

		return sprintf(
			'<span class="ui-label ui-label-%s ui-label-fill biconnector-usage-stat-load-level">'
			. '<span class="ui-label-inner" data-hint="%s" data-hint-html data-hint-no-icon data-hint-interactivity>%s</span>'
			. '</span>',
			$color,
			htmlspecialcharsbx($hintContent),
			htmlspecialcharsbx($label),
		);
	}

	private function getLevelLabel(LoadLevel $level): string
	{
		return match ($level)
		{
			LoadLevel::High => (string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_LEVEL_HIGH'),
			LoadLevel::Medium => (string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_LEVEL_MEDIUM'),
			LoadLevel::Low => (string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_LEVEL_LOW'),
		};
	}

	private function getLevelColor(LoadLevel $level): string
	{
		return match ($level)
		{
			LoadLevel::High => 'danger',
			LoadLevel::Medium => 'orange',
			LoadLevel::Low => 'success',
		};
	}

	private function renderHintContent(LoadIndicator $indicator): string
	{
		if ($indicator->getLevel() === LoadLevel::Low)
		{
			return htmlspecialcharsbx((string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_LOW_TOOLTIP'));
		}

		$items = [];
		foreach ($indicator->getFactors() as $factorInfo)
		{
			$text = $this->renderFactorText($factorInfo);
			if ($text !== '')
			{
				$items[] = '<li style="margin:6px 0">' . htmlspecialcharsbx($text) . '</li>';
			}
		}

		if ($items === [])
		{
			return '';
		}

		return '<ul style="margin:0;padding-left:18px">' . implode('', $items) . '</ul>';
	}

	private function renderFactorText(TriggeredFactorInfo $info): string
	{
		switch ($info->factor)
		{
			case TriggeredFactor::Duration:
				return (string)Loc::getMessage(
					'BIC_USAGE_STAT_GRID_LOAD_FACTOR_DURATION',
					['#DURATION#' => $this->formatDuration($info->duration ?? 0.0)],
				);

			case TriggeredFactor::PeriodWide:
				return (string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_FACTOR_PERIOD_WIDE');

			case TriggeredFactor::ManyColumns:
				$ratio = (int)round(($info->columnsRatio ?? 0.0) * 100);

				return (string)Loc::getMessage(
					'BIC_USAGE_STAT_GRID_LOAD_FACTOR_MANY_COLUMNS',
					[
						'#SELECTED#' => $info->selectedColumns ?? 0,
						'#TOTAL#' => $info->totalColumns ?? 0,
						'#RATIO#' => $ratio,
					],
				);

			case TriggeredFactor::NoFilters:
				return (string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_FACTOR_NO_FILTERS');

			case TriggeredFactor::LargeData:
				return (string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_FACTOR_LARGE_DATA');

		}

		return '';
	}

	private function formatDuration(float $seconds): string
	{
		return number_format($seconds, 1, '.', '');
	}
}
