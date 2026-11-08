<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\Integration\Intranet\Settings;

final class ToolEnabledFilter extends Base
{
	public const ERROR_CODE = 'TIMEMAN_TOOL_DISABLED';

	public static function getErrorMessage(): string
	{
		return (string)Loc::getMessage('TIMEMAN_CONTROLLER_ACTION_FILTER_TOOL_ENABLED_ERROR');
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!(new Settings())->isToolAvailable(Settings::TOOLS['worktime']))
		{
			$this->addError(new Error(self::getErrorMessage(), self::ERROR_CODE));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
