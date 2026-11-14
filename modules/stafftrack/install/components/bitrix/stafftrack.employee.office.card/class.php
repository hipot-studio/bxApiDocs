<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\StaffTrack\Dictionary\EmployeeLocationBlockMode;
use Bitrix\StaffTrack\Item\EmployeeLocationBlock;
use Bitrix\StaffTrack\Public\Provider\EmployeeLocationBlockProvider;
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
		$viewerId = (int)$this->arParams['VIEWER_ID'];

		$block = $this->getBlock($viewerId, $employeeId);
		if ($block === null)
		{
			return $this->getEmptyResult($employeeId);
		}

		if ($block->mode === EmployeeLocationBlockMode::ROUTE)
		{
			return $this->getRouteResult($block, $employeeId);
		}

		return $this->getOfficeResult($block, $employeeId);
	}

	protected function getBlock(int $viewerId, int $employeeId): ?EmployeeLocationBlock
	{
		if (!Loader::includeModule('stafftrack'))
		{
			return null;
		}

		return $this->getEmployeeLocationBlockProvider()->getBlock($viewerId, $employeeId, false);
	}

	protected function getEmployeeLocationBlockProvider(): EmployeeLocationBlockProvider
	{
		return new EmployeeLocationBlockProvider();
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

	private function getRouteResult(EmployeeLocationBlock $block, int $employeeId): array
	{
		return [
			'IS_VISIBLE' => true,
			'MODE' => $block->mode->value,
			'EMPLOYEE_ID' => $employeeId,
			'SNAPSHOT_URL' => $block->snapshotUrl,
			'SHOULD_REQUEST_SNAPSHOT' => $block->snapshotUrl === null,
			'OFFICE_ID' => null,
			'TITLE' => null,
			'ADDRESS' => $block->address,
			'TIME_LABEL' => $this->formatTime($block->dateCreate),
			'PRESENCE' => null,
			'MAP_URL' => $block->mapUrl,
			'AVATAR_URL' => $block->avatarUrl,
		];
	}

	private function getOfficeResult(EmployeeLocationBlock $block, int $employeeId): array
	{
		$officeId = (int)$block->officeId;
		$hasSnapshot = (string)$block->snapshotUrl !== '';

		return [
			'IS_VISIBLE' => $hasSnapshot,
			'MODE' => $block->mode->value,
			'EMPLOYEE_ID' => $employeeId,
			'SNAPSHOT_URL' => $hasSnapshot ? $block->snapshotUrl : null,
			'SHOULD_REQUEST_SNAPSHOT' => !$hasSnapshot && $this->canCaptureSnapshot($officeId),
			'OFFICE_ID' => $officeId,
			'TITLE' => $block->officeTitle,
			'ADDRESS' => $block->address,
			'TIME_LABEL' => null,
			'PRESENCE' => $hasSnapshot ? $block->presence?->value : null,
			'MAP_URL' => $block->mapUrl,
			'AVATAR_URL' => null,
		];
	}

	private function getEmptyResult(int $employeeId): array
	{
		return [
			'IS_VISIBLE' => false,
			'MODE' => null,
			'EMPLOYEE_ID' => $employeeId,
			'SNAPSHOT_URL' => null,
			'SHOULD_REQUEST_SNAPSHOT' => false,
			'OFFICE_ID' => null,
			'TITLE' => null,
			'ADDRESS' => null,
			'TIME_LABEL' => null,
			'PRESENCE' => null,
			'MAP_URL' => $this->getSiteDir() . self::MAP_PAGE_PATH,
			'AVATAR_URL' => null,
		];
	}

	private function formatTime(?string $dateCreate): ?string
	{
		if ($dateCreate === null || $dateCreate === '')
		{
			return null;
		}

		$wallClock = strtotime($dateCreate);
		if ($wallClock === false)
		{
			return null;
		}

		$timeFormat = \Bitrix\Main\Context::getCurrent()->getCulture()->getShortTimeFormat();

		return FormatDate($timeFormat, $wallClock);
	}
}
