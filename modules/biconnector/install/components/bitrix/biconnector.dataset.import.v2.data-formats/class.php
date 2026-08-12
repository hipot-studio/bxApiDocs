<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

\Bitrix\Main\Loader::includeModule('biconnector');

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\ExternalSource\Const;
use Bitrix\BIConnector\ExternalSource\FieldType;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

class DatasetImportV2DataFormatsComponent extends CBitrixComponent
{
	public function onPrepareComponentParams($arParams)
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_CSV_IMPORT_ACCESS_ERROR');
			$this->includeComponentTemplate();
			Application::getInstance()->terminate();
		}

		if (!is_array($arParams['dataFormats'] ?? null))
		{
			$arParams['dataFormats'] = [];
		}

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		$this->fillCurrent();
		$this->fillTemplates();

		$this->includeComponentTemplate();
	}

	private function fillCurrent(): void
	{
		$incoming = $this->arParams['dataFormats'];
		$this->arResult['current'] = [
			FieldType::Money->value => (string)($incoming[FieldType::Money->value] ?? ''),
			FieldType::Date->value => (string)($incoming[FieldType::Date->value] ?? Const\Date::Ymd_dash->value),
			FieldType::DateTime->value => (string)($incoming[FieldType::DateTime->value] ?? Const\DateTime::Ymd_dash_His_colon->value),
			FieldType::Double->value => (string)($incoming[FieldType::Double->value] ?? Const\DoubleDelimiter::DOT->value),
			FieldType::Timezone->value => (string)($incoming[FieldType::Timezone->value] ?? DataTimezone::getTimezone()),
		];
	}

	private function fillTemplates(): void
	{
		$dateFormat = [[
			'type' => 'custom',
			'value' => '',
		]];
		foreach (array_column(Const\Date::cases(), 'value') as $date)
		{
			$dateFormat[] = [
				'title' => Const\DateTimeFormatConverter::phpToIso8601($date),
				'type' => 'value',
				'value' => $date,
			];
		}

		$dateTimeFormat = [[
			'type' => 'custom',
			'value' => '',
		]];
		foreach (array_column(Const\DateTime::cases(), 'value') as $dateTime)
		{
			$dateTimeFormat[] = [
				'title' => Const\DateTimeFormatConverter::phpToIso8601($dateTime),
				'type' => 'value',
				'value' => $dateTime,
			];
		}
		$dateTimeFormat[] = [
			'title' => 'YYYY-MM-DDThh:mm:ss (ISO 8601)',
			'type' => 'value',
			'value' => 'Y-m-d\TH:i:s',
		];

		$this->arResult['templates'] = [
			FieldType::Date->value => $dateFormat,
			FieldType::DateTime->value => $dateTimeFormat,
			FieldType::Double->value => [
				['title' => '1,23', 'type' => 'value', 'value' => Const\DoubleDelimiter::COMMA->value],
				['title' => '1.23', 'type' => 'value', 'value' => Const\DoubleDelimiter::DOT->value],
			],
			FieldType::Money->value => [
				['title' => '12345,67', 'type' => 'value', 'value' => Const\MoneyDelimiter::COMMA->value],
				['title' => '12345.67', 'type' => 'value', 'value' => Const\MoneyDelimiter::DOT->value],
			],
			FieldType::Timezone->value => $this->getTimezones(),
		];
	}

	private function getTimezones(): array
	{
		$timezones = [];
		foreach (\CTimeZone::GetZones() as $code => $name)
		{
			$timezones[] = [
				'value' => $code,
				'title' => $name,
				'type' => 'value',
			];
		}

		return $timezones;
	}
}
