<?php

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Entity\User\Field\NameResolverInterface;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class CopilotNameResolver implements NameResolverInterface
{
	private const CIS_ZONES = ['ru', 'by', 'kz', 'uz']; /** @see \Bitrix\AI\Facade\Bitrix24::CIS_ZONES */

	private static ?self $instance;
	private string $zone;

	private function __construct()
	{}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function resolveName(int $userId): ?string
	{
		if (AIHelper::getCopilotBotId() !== $userId)
		{
			return null;
		}

		return $this->getName();
	}

	public function resolveFirstName(int $userId): ?string
	{
		if (AIHelper::getCopilotBotId() !== $userId)
		{
			return null;
		}

		return $this->getName();
	}

	public function getName(): string
	{
		if (Loader::includeModule('ui'))
		{
			return (new CopilotNameService())->getCopilotName();
		}

		/** @see \Bitrix\AI\Enum\CopilotName */
		return $this->isWestZone()
			? 'CoPilot'
			: 'BitrixGPT'
		;
	}

	private function isWestZone(): bool
	{
		$zone = $this->getPortalZone();

		return !in_array($zone, self::CIS_ZONES, true);
	}

	private function getPortalZone(): string
	{
		$this->zone ??= strtolower(Application::getInstance()->getLicense()->getRegion() ?? 'ru');

		return $this->zone;
	}
}
