<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant;

use Bitrix\AiAssistant\Config\Feature;
use Bitrix\AiAssistant\Core\Service\Bot\BotManager;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class AiAssistantService
{
	private const BITRIX_GPT_AGENT_PROMO_CUTOFF_DATE_OPTION = 'launch_banner_cutoff_date';

	private ?BotManager $botManager = null;

	public function __construct()
	{
		if (Loader::includeModule('aiassistant') && Loader::includeModule('imbot'))
		{
			$this->botManager = ServiceLocator::getInstance()->get(BotManager::class);
		}
	}

	public function isAiAssistantAvailable(): bool
	{
		return $this->botManager?->isAvailable() ?? false;
	}

	public function getBotId(): int
	{
		return $this->botManager?->getBotId() ?? 0;
	}

	public function getPersonalAiAssistantChatId(int $userId): int
	{
		// TODO: add cache!!!
		$botId = $this->getBotId();
		if ($botId === 0)
		{
			return 0;
		}

		return \CIMMessage::GetChatId($botId, $userId, false);
	}

	public function isAiAssistantChat(Chat $chat): bool
	{
		if ($this->getBotId() === 0)
		{
			return false;
		}

		if (!($chat instanceof Chat\PrivateChat))
		{
			return false;
		}

		return $chat->getRelationByUserId($this->getBotId()) !== null;
	}

	public function isBitrixGptV2Available(string $option): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		return Feature::getInstance()->isBitrixGptV2Available($option, 'im');
	}

	public function isBitrixGptAgentPromoAllowedByPortalAge(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$portalCreateTime = (int)\CBitrix24::getCreateTime();
		if ($portalCreateTime <= 0)
		{
			return false;
		}

		$promoLaunchDate = trim((string)Option::get('aiassistant', self::BITRIX_GPT_AGENT_PROMO_CUTOFF_DATE_OPTION));
		$promoLaunchDateTime = \DateTimeImmutable::createFromFormat('!Y-m-d', $promoLaunchDate);
		if ($promoLaunchDateTime === false || $promoLaunchDateTime->format('Y-m-d') !== $promoLaunchDate)
		{
			return false;
		}

		return $portalCreateTime < $promoLaunchDateTime->getTimestamp();
	}
}
