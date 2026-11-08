<?php
declare(strict_types=1);

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
	public const DEFAULT_SECTION_NAME = 'default';
	public const CHAT_SECTION_NAME = 'chat';
	public const CHANNEL_SECTION_NAME = 'channel';

	private array $configByTypes = [];
	/** @var array<string, ComposedSection> */
	private array $composedAliases = [];
	/** @var array<string, string[]> */
	private array $additionalSectionsByLiteral;
	private bool $isLoaded = false;
	private Converter $converterToCamelCase;

	public function __construct(
		private readonly ExternalTypeRegistry $externalTypeRegistry
	)
	{
		$this->converterToCamelCase = new Converter(Converter::TO_CAMEL | Converter::LC_FIRST);
		$this->additionalSectionsByLiteral = $this->buildAdditionalSectionsMap();
	}

	public static function getInstance(): self
	{
		return ServiceLocator::getInstance()->get(RecentConfigManager::class);
	}

	public function getExternalTypesInDefaultSection(): array
	{
		if (!$this->isLoaded)
		{
			$this->load();
		}

		$types = [];
		foreach (array_keys($this->externalTypeRegistry->getConfigs()) as $type)
		{
			if ($this->getByExtendedType($type)->useDefaultRecentSection)
			{
				$types[] = $type;
			}
		}

		return $types;
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
		$type = $chat->getChatType();

		if ($chat->hasParent())
		{
			return $this->getAllRecentSectionsByType($type);
		}

		return $this->getBaseRecentSections($type);
	}

	public function getAllRecentSectionsByType(Chat\Type $type): array
	{
		$baseSections = $this->getBaseRecentSections($type);
		$allSections = $baseSections;

		foreach ($this->composedAliases as $alias => $composedSection)
		{
			if ($composedSection->matchesSections($baseSections))
			{
				$allSections[] = $alias;
			}
		}

		return $allSections;
	}

	public function getExcludedComposedSections(Chat\Type $type): array
	{
		$baseSections = $this->getBaseRecentSections($type);
		$excluded = [];

		foreach ($this->composedAliases as $alias => $composedSection)
		{
			if ($composedSection->excludesSections($baseSections))
			{
				$excluded[] = $alias;
			}
		}

		return $excluded;
	}

	public function getBaseRecentSections(Chat\Type $type): array
	{
		$extendedType = $type->getExtendedType(false);
		$config = $this->getByExtendedType($extendedType);
		$recentSections = [];

		if ($config->useDefaultRecentSection)
		{
			$recentSections[] = self::DEFAULT_SECTION_NAME;
		}

		if ($config->hasOwnRecentSection)
		{
			$recentSections[] = $config->getOwnSectionName() ?? $this->converterToCamelCase->process($extendedType);
		}

		foreach ($this->additionalSectionsByLiteral[$type->literal] ?? [] as $section)
		{
			$recentSections[] = $section;
		}

		return $recentSections;
	}

	/**
	 * Membership of the additive 'chat' and 'channel' sections, decided by the chat letter alone.
	 * Both sit on top of the sections a type declares for itself (double membership) and never
	 * replace 'default'. Membership is a rule, not an enumeration of chat kinds: a letter carries
	 * its section whatever the ENTITY_TYPE of the chat is, including a value no module registered.
	 *
	 * A pair kept out of a section is subtracted apart from this rule, at the single owner of such
	 * exclusions ({@see \Bitrix\Im\V2\Chat\Type\TypeRegistry::isTypeExcludedFromSection()}).
	 *
	 * @return array<string, string[]>
	 */
	private function buildAdditionalSectionsMap(): array
	{
		$map = [];

		// 'chat' - regular conversations: dialogs, group chats and open chats.
		$chatLiterals = [
			Chat::IM_TYPE_PRIVATE,
			Chat::IM_TYPE_CHAT,
			Chat::IM_TYPE_OPEN,
		];
		foreach ($chatLiterals as $literal)
		{
			$map[$literal][] = self::CHAT_SECTION_NAME;
		}

		// 'channel' - channels by membership: closed channels plus subscribed open channels.
		$channelLiterals = [
			Chat::IM_TYPE_CHANNEL,
			Chat::IM_TYPE_OPEN_CHANNEL,
		];
		foreach ($channelLiterals as $literal)
		{
			$map[$literal][] = self::CHANNEL_SECTION_NAME;
		}

		return $map;
	}

	private function load(): void
	{
		$this->isLoaded = true;
		$this->loadInternal();
		$this->loadExternal();
		$this->loadComposedSections();
	}

	private function loadInternal(): void
	{
		$this->configByTypes[ExtendedType::Copilot->value] =
			new RecentConfig(CopilotChat::isHistoryAvailable(), CopilotChat::isActive(), CounterType::Copilot);
		$this->configByTypes[ExtendedType::Collab->value] =
			(new RecentConfig(true, true, CounterType::Collab))
				->addComposedChildSection('collabDefault', new ComposedSection(
					include: [self::DEFAULT_SECTION_NAME, 'tasksTask', 'calendar'],
				))
				->addComposedChildSection('collabChat', new ComposedSection(
					include: [self::DEFAULT_SECTION_NAME],
					exclude: ['tasksTask', 'calendar', 'copilot'],
				))
		;
		$this->configByTypes[ExtendedType::Lines->value] =
			new RecentConfig(false, true, CounterType::Openline);
		$this->configByTypes[ExtendedType::Comment->value] =
			new RecentConfig(false, false, CounterType::Comment);
		$this->configByTypes[ExtendedType::OpenChannel->value] =
			new RecentConfig(true, true);
		$this->configByTypes[ExtendedType::GeneralChannel->value]
			= (new RecentConfig(true, true))->setOwnSectionName('openChannel')
		;
		$this->configByTypes[ExtendedType::Calendar->value]
			= (new RecentConfig(true, true))
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
				$config->useDefaultRecentSection,
				$config->hasOwnRecentSection,
				$config->hasOwnRecentSection ? FormatConverter::toCamelCase($type) : CounterType::Chat
			);

			$this->configByTypes[$type] = $recentConfig;
		}
	}

	private function loadComposedSections(): void
	{
		foreach ($this->configByTypes as $config)
		{
			foreach ($config->getComposedChildSections() as $alias => $composedSection)
			{
				$this->composedAliases[$alias] = $composedSection;
			}
		}
	}
}
