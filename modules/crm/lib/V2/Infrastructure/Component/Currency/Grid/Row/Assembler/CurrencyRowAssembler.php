<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Assembler;

use Bitrix\Main\Grid\Row\RowAssembler;

final class CurrencyRowAssembler extends RowAssembler
{
	public function __construct(
		array $visibleColumnIds,
		private readonly string $editUrlTemplate,
		private readonly string $accountingCurrencyId,
	)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [];
	}

	public function prepareRows(array $rowsList): array
	{
		foreach ($rowsList as &$row)
		{
			$data = is_array($row['data'] ?? null) ? $row['data'] : [];
			$currencyId = $this->getScalarString($data['CURRENCY'] ?? null);
			$fullName = $this->getScalarString($data['FULL_NAME'] ?? null);

			$row['id'] = $currencyId;
			$row['data'] = [
				'ID' => $currencyId,
				'CURRENCY' => $currencyId,
				'NAME' => $fullName,
				'SORT' => (int)($data['SORT'] ?? 0),
				'EXCH_RATE' => (float)($data['AMOUNT'] ?? 0.0),
				'AMOUNT_CNT' => (int)($data['AMOUNT_CNT'] ?? 0),
				'BASE' => $this->isYes($data['BASE'] ?? false) ? 'Y' : 'N',
				'ACCOUNTING' => $currencyId === $this->accountingCurrencyId ? 'Y' : 'N',
			];
			$row['columns'] ??= [];
			$row['columns']['NAME'] = $this->prepareName($currencyId, $fullName);
		}
		unset($row);

		return $rowsList;
	}

	private function prepareName(string $currencyId, string $fullName): string
	{
		$title = htmlspecialcharsbx($fullName !== '' ? $fullName : $currencyId);
		$editUrl = $this->getEditUrl($currencyId);
		if ($editUrl === '')
		{
			return $title;
		}

		return '<a data-slider-ignore-autobinding="true" href="' . htmlspecialcharsbx($editUrl) . '">' . $title . '</a>';
	}

	private function getEditUrl(string $currencyId): string
	{
		$url = str_replace('#currency_id#', rawurlencode($currencyId), $this->editUrlTemplate);
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if ($scheme === false)
		{
			return '';
		}

		if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true))
		{
			return '';
		}

		return $url;
	}

	private function getScalarString(mixed $value): string
	{
		return is_scalar($value) ? (string)$value : '';
	}

	private function isYes(mixed $value): bool
	{
		return $value === true || $value === 'Y';
	}
}
