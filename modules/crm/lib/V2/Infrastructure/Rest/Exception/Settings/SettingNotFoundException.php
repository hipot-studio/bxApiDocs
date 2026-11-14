<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Settings;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

final class SettingNotFoundException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_NOT_FOUND;

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_SETTINGS_SETTING_NOT_FOUND';
	}
}
