<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant;

use Bitrix\AiAssistant\Config\Feature;
use Bitrix\Main\Loader;

class MartaService
{
	public static function shouldShowBitrixGpt(): bool
	{
		return Loader::includeModule('aiassistant') && Feature::getInstance()->isBitrixGptV2Available();
	}
}
