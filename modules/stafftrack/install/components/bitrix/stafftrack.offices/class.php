<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;
use Bitrix\StaffTrack\Public\Provider\OfficesMapProvider;

Loc::loadMessages(__FILE__);

class StaffTrackOfficesComponent extends \CBitrixComponent
{
	public function onPrepareComponentParams($arParams): array
	{
		$arParams = parent::onPrepareComponentParams($arParams);
		$arParams['FOCUS_OFFICE_ID'] = $this->prepareFocusOfficeId($arParams['FOCUS_OFFICE_ID'] ?? null);

		return $arParams;
	}

	public function executeComponent(): void
	{
		$this->arResult = $this->prepareResult();

		$this->includeComponentTemplate();
	}

	protected function prepareResult(): array
	{
		$mapProvider = $this->getMapProvider();

		$isMapAvailable = $mapProvider->isMapAvailable();
		$mapParams = [];

		if ($isMapAvailable)
		{
			$mapParams = $mapProvider->getMapParams();
			$mapParams['focusOfficeId'] = $this->arParams['FOCUS_OFFICE_ID'];
		}

		return [
			'PAGE_TITLE' => Loc::getMessage('STAFFTRACK_OFFICES_PAGE_TITLE'),
			'IS_MAP_AVAILABLE' => $isMapAvailable,
			'MAP_PARAMS' => $mapParams,
		];
	}

	protected function getMapProvider(): OfficesMapProvider
	{
		return new OfficesMapProvider();
	}

	private function prepareFocusOfficeId(mixed $value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$officeId = (int)$value;

		return $officeId > 0 ? $officeId : null;
	}
}
