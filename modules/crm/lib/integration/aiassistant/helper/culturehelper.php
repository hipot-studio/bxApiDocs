<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

use Bitrix\Main\Application;

final class CultureHelper
{
	public const DEFAULT_DATE_FORMAT = 'Y-m-d';

	public function getDateFormat(): string
	{
		$culture = Application::getInstance()->getContext()->getCulture();

		return $culture === null
			? self::DEFAULT_DATE_FORMAT
			: $culture->getShortDateFormat()
		;
	}
}
