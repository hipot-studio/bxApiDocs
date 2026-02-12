<?php declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\AI\Enum\CopilotName;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\Main\Application;

class CopilotNameService
{
	private static ?string $cachedZone = null;

	public function getCopilotName(): string
	{
		return $this->isWestZone() ? CopilotName::COPILOT->value : CopilotName::BITRIX_GPT->value;
	}

	private function isWestZone(): bool
	{
		$zone = $this->getPortalZone();

		return !in_array($zone, Bitrix24::CIS_ZONES, true);
	}

	protected function getPortalZone(): string
	{
		if (self::$cachedZone === null)
		{
			self::$cachedZone = strtolower(Application::getInstance()->getLicense()->getRegion() ?? 'ru');
		}

		return self::$cachedZone;
	}
}
