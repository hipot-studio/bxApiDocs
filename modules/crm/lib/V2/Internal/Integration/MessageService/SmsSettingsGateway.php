<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\MessageService;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\MessageService\Context\User;
use Bitrix\MessageService\Sender\Limitation;

class SmsSettingsGateway
{
	public function isMessageServiceAvailable(): bool
	{
		return Loader::includeModule('messageservice');
	}

	public function hasConfigurableSenders(): bool
	{
		return SmsManager::getConfigurableSenderInfoList() !== [];
	}

	public function hasLimitationClass(): bool
	{
		return class_exists(Limitation::class);
	}

	public function isAdmin(): bool
	{
		return User::isAdmin();
	}

	public function getManageUrl(): Uri
	{
		return new Uri(SmsManager::getManageUrl());
	}

	public function isSmsAvailable(): bool
	{
		return SmsManager::isAvailable();
	}
}
