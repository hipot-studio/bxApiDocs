<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\StaffTrack\Dictionary\EmployeeOfficeCardStatus;
use Bitrix\StaffTrack\Item\EmployeeOfficeCard;
use Bitrix\StaffTrack\Public\Provider\EmployeeOfficeProvider;

Loc::loadMessages(__FILE__);

class StaffTrackEmployeeOfficeCardComponent extends \CBitrixComponent
{
	private const MAP_PAGE_PATH = 'stafftrack/offices/';

	public function onPrepareComponentParams($arParams): array
	{
		$arParams = parent::onPrepareComponentParams($arParams);
		$arParams['USER_ID'] = (int)($arParams['USER_ID'] ?? 0);
		$arParams['VIEWER_ID'] = (int)($arParams['VIEWER_ID'] ?? 0);

		return $arParams;
	}

	public function executeComponent(): void
	{
		$this->arResult = $this->prepareResult();

		$this->includeComponentTemplate();
	}

	protected function prepareResult(): array
	{
		$employeeId = (int)$this->arParams['USER_ID'];
		$card = $this->getCard($employeeId);

		$hasOffice = $card !== null
			&& $card->status === EmployeeOfficeCardStatus::AVAILABLE
			&& $card->officeId !== null
		;
		$hasSnapshot = $hasOffice && (string)$card->snapshotUrl !== '';

		return [
			'IS_VISIBLE' => $hasSnapshot,
			'SHOULD_REQUEST_SNAPSHOT' => $hasOffice
				&& !$hasSnapshot
				&& $this->canCaptureSnapshot((int)$card->officeId),
			'EMPLOYEE_ID' => $employeeId,
			'SNAPSHOT_URL' => $hasSnapshot ? $card->snapshotUrl : null,
			'OFFICE_ID' => $hasOffice ? $card->officeId : null,
			'TITLE' => $hasOffice ? $card->title : null,
			'ADDRESS' => $hasOffice ? $card->address : null,
			'PRESENCE' => $hasOffice ? $card->presence?->value : null,
			'MAP_URL' => $this->getMapUrl($hasOffice ? $card->officeId : null),
		];
	}

	protected function getCard(int $employeeId): ?EmployeeOfficeCard
	{
		if (!Loader::includeModule('stafftrack'))
		{
			return null;
		}

		return $this->getEmployeeOfficeProvider()->getProfileCard(
			(int)$this->arParams['VIEWER_ID'],
			$employeeId,
			false,
		);
	}

	protected function canCaptureSnapshot(int $officeId): bool
	{
		return $this->getEmployeeOfficeProvider()->canCaptureSnapshot($officeId);
	}

	protected function getEmployeeOfficeProvider(): EmployeeOfficeProvider
	{
		return new EmployeeOfficeProvider();
	}

	protected function getSiteDir(): string
	{
		return defined('SITE_DIR') ? SITE_DIR : '/';
	}

	private function getMapUrl(?int $officeId): string
	{
		$pageUrl = $this->getSiteDir() . self::MAP_PAGE_PATH;

		return $officeId === null ? $pageUrl : $pageUrl . '?office_id=' . $officeId;
	}
}
