<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

final class ClientRecentBySection extends BaseFeature
{
	private const DEFAULT_VALUE = 'Y';

	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_CLIENT_RECENT_BY_SECTION_NAME');
	}

	public function isEnabled(): bool
	{
		return Option::get('crm', $this->getOptionName(), self::DEFAULT_VALUE) !== 'N';
	}

	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}

	protected function getDisabledValue(): mixed
	{
		return 'N';
	}
}
