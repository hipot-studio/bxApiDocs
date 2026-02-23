<?php

declare(strict_types=1);

namespace Bitrix\Ui\Public\Services\Copilot;

use Bitrix\Main\Application;
use Bitrix\Ui\Public\Enum\Copilot\CopilotName;

class CopilotNameService
{
	private const CIS_ZONES = ['ru', 'by', 'kz', 'uz'];
	private static ?string $cachedZone = null;

	public function getCopilotName(): string
	{
		return $this->isWestZone() ? CopilotName::COPILOT->value : CopilotName::BITRIX_GPT->value;
	}

	private function isWestZone(): bool
	{
		$zone = $this->getPortalZone();

		return !in_array($zone, self::CIS_ZONES, true);
	}

	protected function getPortalZone(): string
	{
		if (self::$cachedZone === null)
		{
			self::$cachedZone = strtolower(Application::getInstance()->getLicense()->getRegion() ?? 'en');
		}

		return self::$cachedZone;
	}
}