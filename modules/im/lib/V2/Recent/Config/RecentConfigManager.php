<?php

namespace Bitrix\Im\V2\Recent\Config;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalTypeRegistry;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Common\FormatConverter;
use Bitrix\Im\V2\Message\Counter\CounterType;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Response\Converter;

class RecentConfigManager
{
	public const EXTERNAL_CHAT_USE_DEFAULT_RECENT_SECTION = false;
	public const DEFAULT_SECTION_NAME = 'default';

	private array $configByTypes = [];
	private bool $isLoaded = false;
	private Converter $converterToCamelCase;

	public function __construct(
		private readonly ExternalTypeRegistry $externalTypeRegistry
	)
	{
		$this->converterToCamelCase = new Converter(Converter::TO_CAMEL | Converter::LC_FIRST);
	}

	public static function getInstance(): self
	{
		return ServiceLocator::getInstance()->get(RecentConfigManager::class);
	}

	public function getByExtendedType(string $type): RecentConfig
	{
		if (!$this->isLoaded)
		{
			$this->load();
		}

		return $this->configByTypes[$type] ?? new RecentConfig();
	}

	public function getRecentSectionsByChat(Chat $chat): array
	{
		return $this->getRecentSectionsByChatExtendedType($chat->getExtendedType(false));
	}

	public function getRecentSectionsByChatExtendedType(string $type): array
	{
		$config = $this->getByExtendedType($type);
		$recentSections = [];

		if ($config->useDefaultRecentSection)
		{
			$recentSections[] = self::DEFAULT_SECTION_NAME;
		}

		if ($config->hasOwnRecentSection)
		{
			$recentSections[] = $config->getOwnSectionName() ?? $this->converterToCamelCase->process($type);
		}

		return $recentSections;
	}

	private function load(): void
	{
		$this->isLoaded = true;
		$this->loadInternal();
		$this->loadExternal();
	}

	private function loadInternal(): void
	{
		$this->configByTypes[ExtendedType::Copilot->value] =
			new RecentConfig(CopilotChat::isHistoryAvailable(), CopilotChat::isActive(), CounterType::Copilot);
		$this->configByTypes[ExtendedType::Collab->value] =
			new RecentConfig(true, true, CounterType::Collab);
		$this->configByTypes[ExtendedType::Lines->value] =
			new RecentConfig(false, true, CounterType::Openline);
		$this->configByTypes[ExtendedType::Comment->value] =
			new RecentConfig(false, false, CounterType::Comment);
		$this->configByTypes[ExtendedType::OpenChannel->value] =
			new RecentConfig(true, true);
		$this->configByTypes[ExtendedType::GeneralChannel->value]
			= (new RecentConfig(true, true))->setOwnSectionName('openChannel')
		;
		$this->configByTypes[ExtendedType::System->value] =
			new RecentConfig(false, false)
		;
	}

	private function loadExternal(): void
	{
		$configs = $this->externalTypeRegistry->getConfigs();

		foreach ($configs as $type => $config)
		{
			if (isset($this->configByTypes[$type]))
			{
				continue;
			}

			$recentConfig = new RecentConfig(
				self::EXTERNAL_CHAT_USE_DEFAULT_RECENT_SECTION,
				$config->hasOwnRecentSection,
				$config->hasOwnRecentSection ? FormatConverter::toCamelCase($type) : CounterType::Chat
			);

			$this->configByTypes[$type] = $recentConfig;
		}
	}
}
