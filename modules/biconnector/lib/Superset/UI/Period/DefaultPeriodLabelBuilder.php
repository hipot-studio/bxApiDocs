<?php

namespace Bitrix\BIConnector\Superset\UI\Period;

use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Localization\Loc;

final class DefaultPeriodLabelBuilder
{
	public function build(): array
	{
		$valueText = $this->getValueText();
		[$prefixText, $suffixText] = $this->getLabelParts();

		return [
			'fullText' => $this->getFullText($valueText),
			'prefixText' => $prefixText,
			'valueText' => $valueText,
			'suffixText' => $suffixText,
		];
	}

	private function getValueText(): string
	{
		$defaultPeriod = EmbeddedFilter\DateTime::getDefaultPeriod();
		if ($defaultPeriod !== EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			return EmbeddedFilter\DateTime::getPeriodName($defaultPeriod);
		}

		return Loc::getMessage(
			'BICONNECTOR_SUPERSET_UI_DEFAULT_PERIOD_RANGE_INCLUDE_LAST_FILTER_DATE',
			[
				'#DATE_FROM#' => EmbeddedFilter\DateTime::getDefaultDateStart()->toString(),
				'#DATE_TO#' => EmbeddedFilter\DateTime::getDefaultDateEnd()->toString(),
			]
		);
	}

	private function getFullText(string $valueText): string
	{
		$defaultPrefix = Loc::getMessage('BICONNECTOR_SUPERSET_UI_DEFAULT_PERIOD_PREFIX');
		$fullText = Loc::getMessage(
			'BICONNECTOR_SUPERSET_UI_DEFAULT_PERIOD_LABEL',
			[
				'#DEFAULT_PREFIX#' => $defaultPrefix,
				'#PERIOD_NAME#' => $valueText,
			]
		);

		return is_string($fullText) && $fullText !== ''
			? $fullText
			: $valueText
		;
	}

	private function getLabelParts(): array
	{
		$defaultPrefix = Loc::getMessage('BICONNECTOR_SUPERSET_UI_DEFAULT_PERIOD_PREFIX');
		$template = Loc::getMessage(
			'BICONNECTOR_SUPERSET_UI_DEFAULT_PERIOD_LABEL',
			[
				'#DEFAULT_PREFIX#' => $defaultPrefix,
				'#PERIOD_NAME#' => '#PERIOD_NAME#',
			]
		);

		if (!is_string($template) || $template === '' || !str_contains($template, '#PERIOD_NAME#'))
		{
			return ['', ''];
		}

		[$prefixText, $suffixText] = explode('#PERIOD_NAME#', $template, 2);

		return [
			$prefixText ?? '',
			$suffixText ?? '',
		];
	}
}
