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
		$dateFormat = [];
		foreach (array_column(Const\Date::cases(), 'value') as $date)
		{
			$dateFormat[] = [
				'title' => Const\DateTimeFormatConverter::phpToIso8601($date),
				'type' => 'value',
				'value' => $date,
			];
		}

		$dateTimeFormat = [];
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
			'value' => Const\DateTime::ISO_8601,
		];

		$this->arResult['templates'] = [
			FieldType::Date->value => [
				$this->makeCustomTemplate(FieldType::Date, $dateFormat),
				...$dateFormat,
			],
			FieldType::DateTime->value => [
				$this->makeCustomTemplate(FieldType::DateTime, $dateTimeFormat),
				...$dateTimeFormat,
			],
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

	/**
	 * Builds the "custom format" option. It carries the current format when the format does not match
	 * any predefined one: such a format is passed to the frontend in ISO 8601 notation and is converted
	 * back to the PHP one by the dataset controller.
	 *
	 * @param FieldType $fieldType Field the option belongs to.
	 * @param array $predefinedTemplates Predefined format templates of the same field.
	 * @return array
	 */
	private function makeCustomTemplate(FieldType $fieldType, array $predefinedTemplates): array
	{
		$current = (string)($this->arResult['current'][$fieldType->value] ?? '');
		$isPredefined = in_array($current, array_column($predefinedTemplates, 'value'), true);

		return [
			'type' => 'custom',
			'value' => $isPredefined ? '' : $current,
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
