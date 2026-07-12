<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Provider;

use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\Filter\UserDataProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Socialnetwork\Filter\WorkgroupDataProvider;

abstract class BaseFilterDataProvider extends DataProvider
{
	private string $gridId;
	private string $filterId;

	public function __construct(string $gridId, string $filterId = '')
	{
		$this->gridId = $gridId;
		$this->filterId = ($filterId !== '' ? $filterId : $gridId);
	}

	public function getSettings(): Settings
	{
		return new Settings([
			'ID' => $this->filterId,
			'GRID_ID' => $this->gridId,
		]);
	}

	protected function isClosedFieldAvailable(): bool
	{
		return WorkgroupDataProvider::getClosedAvailability();
	}

	protected function isProjectFieldAvailable(): bool
	{
		return WorkgroupDataProvider::getProjectAvailability();
	}

	protected function isScrumFieldAvailable(): bool
	{
		return WorkgroupDataProvider::getScrumAvailability();
	}

	protected function isExtranetFieldAvailable(): bool
	{
		return UserDataProvider::getExtranetAvailability();
	}

	protected function isLandingFieldAvailable(): bool
	{
		return WorkgroupDataProvider::getLandingAvailability();
	}

	protected function getOwnerFieldName(
		string $messageCode,
		string $intranetMessageCode,
	): string
	{
		if (ModuleManager::isModuleInstalled('intranet'))
		{
			return Loc::getMessage($intranetMessageCode)
				?? Loc::getMessage($messageCode)
				?? '';
		}

		return Loc::getMessage($messageCode) ?? '';
	}

	protected function getSharedMessage(string $code): string
	{
		return Loc::getMessage('SONET_V2_FILTER_SHARED_' . $code) ?? '';
	}

	protected function getSharedFieldName(string $fieldId): string
	{
		return $this->getSharedMessage('FIELD_' . $fieldId);
	}

	protected function getSharedOwnerFieldName(): string
	{
		return $this->getOwnerFieldName(
			messageCode: 'SONET_V2_FILTER_SHARED_FIELD_OWNER',
			intranetMessageCode: 'SONET_V2_FILTER_SHARED_FIELD_OWNER_INTRANET',
		);
	}

	protected function getSharedListItems(): array
	{
		return [
			'' => $this->getSharedMessage('LIST_VALUE_NOT_SPECIFIED'),
			'Y' => $this->getSharedMessage('LIST_VALUE_YES'),
		];
	}
}
