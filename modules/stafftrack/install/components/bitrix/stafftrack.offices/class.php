<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\StaffTrack\Public\Provider\OfficeProvider;
use Bitrix\StaffTrack\Public\Provider\OfficesMapProvider;
use Bitrix\UI\Buttons\CreateButton;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;

Loc::loadMessages(__FILE__);

class StaffTrackOfficesComponent extends \CBitrixComponent
{
	private const CREATE_BUTTON_ID = 'stafftrack-offices-create-button';
	private const CREATE_OFFICE_CLICK_EVENT = 'BX.Stafftrack.Offices:onCreateOfficeClick';
	private const SEARCH_CONTAINER_ID = 'stafftrack-offices-search';

	public function onPrepareComponentParams($arParams): array
	{
		$arParams = parent::onPrepareComponentParams($arParams);
		$arParams['FOCUS_OFFICE_ID'] = $this->preparePositiveId($arParams['FOCUS_OFFICE_ID'] ?? null);
		$arParams['FOCUS_ROUTE_USER_ID'] = $this->preparePositiveId($arParams['FOCUS_ROUTE_USER_ID'] ?? null);

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
			$mapParams['focusRouteUserId'] = $this->arParams['FOCUS_ROUTE_USER_ID'];
			$mapParams['searchContainerId'] = self::SEARCH_CONTAINER_ID;

			if ($this->isOfficeFormAvailable($mapProvider))
			{
				$mapParams['officeForm'] = [
					'isPhotoUploadAvailable' => $this->isPhotoUploadAvailable(),
				];

				if ($this->canCreateOffice())
				{
					$this->addCreateButton();
				}
			}
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

	protected function getOfficeProvider(): OfficeProvider
	{
		return new OfficeProvider();
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function isPhotoUploadAvailable(): bool
	{
		return Loader::includeModule('disk');
	}

	private function isOfficeFormAvailable(OfficesMapProvider $mapProvider): bool
	{
		return $this->getCurrentUserId() > 0
			&& Loader::includeModule('ui')
			&& $mapProvider->isPortalSourceOsm()
		;
	}

	private function canCreateOffice(): bool
	{
		return $this->getOfficeProvider()->canCreateOffice($this->getCurrentUserId());
	}

	private function addCreateButton(): void
	{
		Toolbar::addButton(
			new CreateButton([
				'id' => self::CREATE_BUTTON_ID,
				'text' => Loc::getMessage('STAFFTRACK_OFFICES_CREATE_BUTTON'),
				'dataset' => [
					'testid' => self::CREATE_BUTTON_ID,
				],
				'click' => new JsCode("BX.Event.EventEmitter.emit('" . self::CREATE_OFFICE_CLICK_EVENT . "');"),
			]),
			ButtonLocation::AFTER_TITLE,
		);
	}

	private function preparePositiveId(mixed $value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$id = (int)$value;

		return $id > 0 ? $id : null;
	}
}
