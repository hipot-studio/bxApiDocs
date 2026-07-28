<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Internal\Grid\UsageStat\Settings\UsageStatSettings;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Grid\Settings;

final class KeyIdFieldAssembler extends FieldAssembler
{
	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);
	}

	protected function prepareColumn($value): string
	{
		$keyId = $value['KEY_ID'] ?? null;
		if ($keyId === null || $keyId === '')
		{
			return '';
		}

		$accessKey = $value['ACCESS_KEY'] ?? null;
		$urlTemplate = (string)($value['URL_TEMPLATE'] ?? '');

		if ($accessKey !== null && $urlTemplate !== '')
		{
			$url = str_replace('#ID#', urlencode((string)$keyId), $urlTemplate);

			return '<a href="javascript:BX.SidePanel.Instance.open(\''
				. \CUtil::JSEscape($url)
				. '\', {width: 600})">'
				. htmlspecialcharsbx((string)$keyId)
				. '</a>'
			;
		}

		return htmlspecialcharsbx((string)$keyId);
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$data = $row['data'] ?? [];

		/** @var UsageStatSettings|null $settings */
		$settings = $this->getSettings();
		$urlTemplate = $settings instanceof UsageStatSettings ? $settings->getKeyEditUrl() : '';

		$value = [
			'KEY_ID' => $data['KEY_ID'] ?? null,
			'ACCESS_KEY' => $data['ACCESS_KEY'] ?? null,
			'URL_TEMPLATE' => $urlTemplate,
		];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['data'][$columnId] = $value;
		}

		return parent::prepareRow($row);
	}
}
