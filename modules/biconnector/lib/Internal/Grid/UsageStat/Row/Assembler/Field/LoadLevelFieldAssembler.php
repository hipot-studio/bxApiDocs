<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\LoadIndicator;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\LoadLevel;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\TriggeredFactor;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\TriggeredFactorInfo;
use Bitrix\BIConnector\Internal\Services\LoadIndicator\LoadIndicatorCalculator;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\ScreenReaderText;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Public\System\Chip;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class LoadLevelFieldAssembler extends FieldAssembler
{
	/** Numbers the descriptions of the carriers so that their ids are unique on the page. */
	private static int $carrierCounter = 0;

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
		$queryId = (int)($data['ID'] ?? $row['id'] ?? 0);
		$html = $this->renderPill(LoadIndicator::createFromCheckResult($result), $queryId);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}

	private function renderPill(LoadIndicator $indicator, int $queryId): string
	{
		$label = $this->getLevelLabel($indicator->getLevel());
		$color = $this->getLevelColor($indicator->getLevel());
		$hintContent = $this->renderHintContent($indicator);

		switch ($color)
		{
			case 'danger':
				$design = Chip\Design::TINTED_ALERT;
				$icon = Outline::CIRCLE_CROSS->value;
				break;
			case 'orange':
				$design = Chip\Design::TINTED_WARNING;
				$icon = Outline::ALERT->value;
				break;
			default:
				$design = Chip\Design::TINTED_SUCCESS;
				$icon = Outline::CIRCLE_CHECK->value;
				break;
		}

		$chip = Chip\Chip::create([
			'text' => htmlspecialcharsbx($label) ?? '',
			'design' => $design,
			'size' => Chip\Size::XS,
			'icon' => $icon,
			'rounded' => true,
		])->render();
		// The carrier of the hint is the only stop of the cell: a focusable chip inside it would be a second one,
		// and moving between the two hides the hint the carrier has just shown.
		$chip = preg_replace('/\stabindex="0"/', '', $chip, 1);

		$descriptionText = $this->renderDescriptionText($indicator);
		if ($descriptionText === '')
		{
			return sprintf(
				'<span data-hint="%s" data-hint-html data-hint-no-icon data-hint-interactivity>%s</span>',
				htmlspecialcharsbx($hintContent),
				$chip,
			);
		}

		$descriptionId = 'bic-load-factors-' . ++self::$carrierCounter;
		$rowText = '';
		if ($queryId > 0)
		{
			$rowText = ScreenReaderText::render(htmlspecialcharsbx((string)Loc::getMessage(
				'BIC_USAGE_STAT_GRID_LOAD_LEVEL_ROW_ARIA',
				['#ID#' => $queryId],
			)) . ' ');
		}

		return sprintf(
			'<span tabindex="0" data-bic-focus-role="load-factors" data-hint="%s" data-hint-html data-hint-no-icon'
				. ' data-hint-interactivity aria-describedby="%s">%s%s</span>%s',
			htmlspecialcharsbx($hintContent),
			$descriptionId,
			$rowText,
			$chip,
			ScreenReaderText::render($descriptionText, $descriptionId),
		);
	}

	/**
	 * The text of the hint for the screen reader, escaped: the factors are joined into sentences.
	 */
	private function renderDescriptionText(LoadIndicator $indicator): string
	{
		if ($indicator->getLevel() === LoadLevel::Low)
		{
			return htmlspecialcharsbx((string)Loc::getMessage('BIC_USAGE_STAT_GRID_LOAD_LOW_TOOLTIP'));
		}

		$texts = [];
		foreach ($indicator->getFactors() as $factorInfo)
		{
			$text = $this->renderFactorText($factorInfo);
			if ($text !== '')
			{
				$texts[] = htmlspecialcharsbx($text);
			}
		}

		return implode('. ', $texts);
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
