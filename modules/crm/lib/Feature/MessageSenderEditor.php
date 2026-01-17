<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Localization\Loc;

final class MessageSenderEditor extends BaseFeature
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return Loc::getMessage('CRM_MESSAGE_SENDER_EDITOR_FEATURE_NAME');
	}

	public function isEnabled(): bool
	{
		return true;
	}

	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}
}
