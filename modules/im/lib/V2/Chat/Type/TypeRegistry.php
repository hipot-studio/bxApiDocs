<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Type;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalTypeRegistry;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Common\FormatConverter;
use Bitrix\Im\V2\Import\ImportService;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\SystemException;

class TypeRegistry
{
	private const OPEN_COLLAB_REGISTRY_KEY = 'OPEN_COLLAB';

	/**
	 * @var array<string, Type>
	 */
	private array $registry = [];
	/**
	 * @var array<string, array<string, Type>>
	 */
	private array $byLiteralAndEntity = [];
	/** @var array<string, array<string, Type>> */
	private array $byRecentSection = [];
	/**
	 * Keyed by the source of the entry: {@see Type::getFilterKey()} for the pair exclusions of
	 * {@see self::registerChatSectionExclusions()}, {@see Type::getRegistryKey()} for the composed
	 * sections of {@see self::fillByRecentSection()}. The two write under disjoint section names, so
	 * the schemes cannot meet in one bucket.
	 *
	 * @var array<string, array<string, Type>>
	 */
	private array $byRecentSectionExclude = [];

	/**
	 * @var array<string, Type>
	 */
	private array $fallbackByLiteral = [];

	public function __construct(
		private readonly ExternalTypeRegistry $externalTypeRegistry,
		private readonly Logger $logger,
		private readonly RecentConfigManager $recentConfigManager,
	)
	{
		$this->load();
	}

	public function getConditionByRecentSection(string $recentSection): TypeCondition
	{
		return new TypeCondition(
			include: array_values($this->byRecentSection[$recentSection] ?? []),
			exclude: array_values($this->byRecentSectionExclude[$recentSection] ?? []),
		);
	}

	/**
	 * Exclusion check against the very map the section query is built from
	 * ({@see self::getConditionByRecentSection()}). Matching mirrors the exclude half of
	 * {@see Query\TypeFilter::toConditionTree()} - an exclusion carrying no entityType covers every
	 * chat of its literal - so the PHP answer about the sections of a chat and the query agree.
	 */
	public function isTypeExcludedFromSection(Type $type, string $section): bool
	{
		foreach ($this->byRecentSectionExclude[$section] ?? [] as $excluded)
		{
			if (
				$excluded->literal === $type->literal
				&& ($excluded->entityType === null || $excluded->entityType === $type->entityType)
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The sections of a type as the recent selection returns them: the sections the type claims, minus
	 * the sub-type exclusions of each. Single place where the subtraction happens: consumers keyed by
	 * type - anchors and the counter collector - and {@see \Bitrix\Im\V2\Chat::getRecentSections()},
	 * which asks the same question by chat and delegates here.
	 *
	 * $hasParent chooses the same branch {@see RecentConfigManager::getRecentSectionsByChat()} does:
	 * a chat under a parent also claims the composed sections its base sections compose into.
	 *
	 * @return string[]
	 */
	public function getRecentSectionsMinusExclusions(Type $type, bool $hasParent): array
	{
		$sections = $hasParent
			? $this->recentConfigManager->getAllRecentSectionsByType($type)
			: $this->recentConfigManager->getBaseRecentSections($type)
		;

		return array_values(array_filter(
			$sections,
			fn(string $section): bool => !$this->isTypeExcludedFromSection($type, $section),
		));
	}

	public function getOpenTypeCondition(): TypeCondition
	{
		$openTypes = array_filter($this->registry, static fn(Type $type) => $type->isOpen);

		return new TypeCondition(include: array_values($openTypes));
	}

	public function getParentMembershipNotRequiredCondition(): ?TypeCondition
	{
		$types = array_filter($this->registry, static fn(Type $type) => !$type->requiresParentMembership);

		if (empty($types))
		{
			return null;
		}

		return new TypeCondition(include: array_values($types));
	}

	/**
	 * @return Type[]
	 */
	public function getAllByExtendedType(string $extendedType): array
	{
		return array_values(array_filter(
			$this->registry,
			static fn(Type $type) => $type->getExtendedType(camelCase: false) === $extendedType,
		));
	}

	/**
	 * @return Type[]
	 */
	public function getTypesByExtendedTypes(array $extendedTypes): array
	{
		$types = [];

		foreach ($extendedTypes as $extendedType)
		{
			foreach ($this->getAllByExtendedType($extendedType) as $type)
			{
				$types[] = $type;
			}
		}

		return $types;
	}

	public function getByLiteralAndEntity(string $literal, ?string $entityType): Type
	{
		return $this->tryGetByLiteralAndEntity($literal, $entityType) ?? $this->getFallback($literal, $entityType);
	}

	public function requiresParentMembership(string $literal, ?string $entityType): bool
	{
		return $this->getByLiteralAndEntity($literal, $entityType)->requiresParentMembership;
	}

	public function tryGetByLiteralAndEntity(string $literal, ?string $entityType): ?Type
	{
		try
		{
			return $this->requireByLiteralAndEntity($literal, $entityType);
		}
		catch (SystemException $exception)
		{
			$this->logger->logThrowable($exception);

			return null;
		}
	}

	public function requireByLiteralAndEntity(string $literal, ?string $entityType): Type
	{
		$matched = $this->byLiteralAndEntity[$literal][$entityType ?? ''] ?? null;
		$base = $this->byLiteralAndEntity[$literal][''] ?? null;

		return match (true)
		{
			$matched !== null => $matched, // exact match: C+VIDEOCONF, B+SONET
			$base?->allowsDynamic() && $entityType => new Type(
				$literal,
				$entityType,
				$entityType,
				$base->isOpen,
				requiresParentMembership: $base->requiresParentMembership,
			), // C+CUSTOM, O+CUSTOM
			$base !== null => new Type(
				$literal,
				$entityType,
				$base->extendedType,
				$base->isOpen,
				requiresParentMembership: $base->requiresParentMembership,
			), // P+ANY=PRIVATE, but save entityType
			default => throw new SystemException("Unknown type: {$literal}, {$entityType}"), // not found
		};
	}

	/**
	 * Last step of the resolve ladder: the pair is answered without a registered type behind it. The literal
	 * is kept as it came - replacing it with the plain chat one made such a chat claim the sections of the C
	 * letter ({@see RecentConfigManager::getBaseRecentSections()}), while the section query keeps filtering
	 * by the literal stored in the row and never returns it.
	 */
	protected function getFallback(string $literal, ?string $entityType): Type
	{
		$fallbackType = $this->fallbackByLiteral[$literal] ?? null;
		if ($fallbackType)
		{
			return new Type(
				$literal,
				$entityType,
				$fallbackType->extendedType,
				$fallbackType->isOpen,
				requiresParentMembership: $fallbackType->requiresParentMembership,
			);
		}

		return new Type($literal, $entityType, $entityType ?? 'CHAT');
	}

	/**
	 * Returns the registered type instance the given type stands for instead of a derived one: T+FOO
	 * resolves to the registered T, while an exact match such as J+GENERAL_CHANNEL resolves to itself.
	 * Input is expected to come from getByLiteralAndEntity(), which keeps the literal of the pair even when
	 * nothing is registered under it. A pair that stands for no registered type is returned as it came, so
	 * that consumers keyed by the type key see the pair itself and not a foreign instance.
	 */
	public function normalizeToRegistered(Type $type): Type
	{
		$exact = $this->byLiteralAndEntity[$type->literal][$type->entityType ?? ''] ?? null;
		if ($exact !== null)
		{
			return $exact;
		}

		$base = $this->byLiteralAndEntity[$type->literal][''] ?? null;
		if ($base !== null)
		{
			return $base;
		}

		return $this->getRegisteredFallback($type);
	}

	/**
	 * Third normalization branch: collab literals have no base entry, because both collabs are registered
	 * with ExtendedType::Sonet only, so their registered instance is reachable through the literal fallback.
	 * An entity type registered under another literal describes another kind of chat, so such a pair is not
	 * normalized to the fallback of its literal and keeps its own key.
	 */
	private function getRegisteredFallback(Type $type): Type
	{
		if ($this->isEntityTypeRegistered($type->entityType))
		{
			return $type;
		}

		$fallbackType = $this->fallbackByLiteral[$type->literal] ?? null;
		$registered = $fallbackType === null ? null : ($this->registry[$fallbackType->getRegistryKey()] ?? null);

		// a literal with neither entry nor fallback stands for no registered type at all
		return $registered ?? $type;
	}

	private function isEntityTypeRegistered(?string $entityType): bool
	{
		if (($entityType ?? '') === '')
		{
			return false;
		}

		foreach ($this->byLiteralAndEntity as $byEntityType)
		{
			if (isset($byEntityType[$entityType]))
			{
				return true;
			}
		}

		return false;
	}

	public function getValidatedEntityType(?string $entityType): ?string
	{
		if (!$entityType)
		{
			return null;
		}

		$entityType = FormatConverter::normalizeToUpperSnakeCase($entityType);
		$type = $this->registry[$entityType] ?? null;
		if (!$type || $type->allowsDynamic())
		{
			return $entityType;
		}

		// TODO: disallow B + CUSTOM?

		return null;
	}

	private function load(): void
	{
		$this->loadWithLiteral();
		$this->loadSystemEntity();
		$this->loadExternal();
		$this->registerFallbacks();
		$this->registerChatSectionExclusions();
	}

	/**
	 * Sub-type exclusions of the 'chat' section: a pair TYPE + ENTITY_TYPE kept out of a section its
	 * extended type belongs to. Every entry here is a chat that carries no conversation of its own, so it
	 * must stay out of the section however wide the membership rule of the letter gets. An entry carries
	 * the letter the value is really created on, so the same value may need more than one entry.
	 *
	 * An entry is not a registered type: the pair stays unregistered and is only keyed by
	 * {@see Type::getFilterKey()}, which reads the literal and the entity type. The chat kind name is
	 * read by nobody, but it repeats the entity type, the way {@see self::requireByLiteralAndEntity()}
	 * resolves such a pair, so that an entry does not lie about itself.
	 *
	 * The registration point stays as the single owner of such exclusions: the section query
	 * ({@see \Bitrix\Im\V2\Chat\Type\Query\TypeFilter}) and the membership predicate
	 * ({@see \Bitrix\Im\V2\Folder\Definition\SectionDefinition}) both read the same map, so a pair
	 * added here keeps the two answers about the sections of a chat in agreement.
	 */
	private function registerChatSectionExclusions(): void
	{
		/** @var Type[] $exclusions */
		$exclusions = [
			// visitor side of an open line: the same conversation is already shown in the paired L chat
			new Type(
				Chat::IM_TYPE_CHAT,
				Chat::ENTITY_TYPE_LIVECHAT,
				Chat::ENTITY_TYPE_LIVECHAT,
			),
			// no conversation: a notice that the transfer of group chats has finished, on a group chat
			new Type(
				Chat::IM_TYPE_CHAT,
				ImportService::IMPORT_GROUP_FINISH_ENTITY_TYPE,
				ImportService::IMPORT_GROUP_FINISH_ENTITY_TYPE,
			),
			// the same notice on an open chat: the notice keeps the letter of the chat being imported
			new Type(
				Chat::IM_TYPE_OPEN,
				ImportService::IMPORT_GROUP_FINISH_ENTITY_TYPE,
				ImportService::IMPORT_GROUP_FINISH_ENTITY_TYPE,
			),
			// no conversation: the same notice for the transfer of one-to-one dialogs, on the dialog itself
			new Type(
				Chat::IM_TYPE_PRIVATE,
				ImportService::IMPORT_PRIVATE_FINISH_ENTITY_TYPE,
				ImportService::IMPORT_PRIVATE_FINISH_ENTITY_TYPE,
			),
			// the same notice on a group chat: the dialog was turned into one before the notice was set
			new Type(
				Chat::IM_TYPE_CHAT,
				ImportService::IMPORT_PRIVATE_FINISH_ENTITY_TYPE,
				ImportService::IMPORT_PRIVATE_FINISH_ENTITY_TYPE,
			),
			// archive mark on the dialog an import replaced: its relations are gone, nobody reads it
			new Type(
				Chat::IM_TYPE_PRIVATE,
				ImportService::IMPORT_ARCHIVE_ENTITY_TYPE,
				ImportService::IMPORT_ARCHIVE_ENTITY_TYPE,
			),
		];

		foreach ($exclusions as $type)
		{
			$this->byRecentSectionExclude[RecentConfigManager::CHAT_SECTION_NAME][$type->getFilterKey()] = $type;
		}
	}

	private function loadWithLiteral(): void
	{
		$withLiteral = [
			ExtendedType::Private->value => [Chat::IM_TYPE_PRIVATE],
			ExtendedType::Chat->value => [Chat::IM_TYPE_CHAT],
			ExtendedType::Lines->value => [Chat::IM_TYPE_OPEN_LINE],
			ExtendedType::Collab->value => [Chat::IM_TYPE_COLLAB, ExtendedType::Sonet->value],
			ExtendedType::Comment->value => [Chat::IM_TYPE_COMMENT],
			ExtendedType::Channel->value => [Chat::IM_TYPE_CHANNEL],
			ExtendedType::OpenChannel->value => [Chat::IM_TYPE_OPEN_CHANNEL],
			ExtendedType::GeneralChannel->value => [Chat::IM_TYPE_OPEN_CHANNEL, ExtendedType::GeneralChannel->value],
			ExtendedType::OpenChat->value => [Chat::IM_TYPE_OPEN],
			ExtendedType::General->value => [Chat::IM_TYPE_OPEN, ExtendedType::General->value],
			ExtendedType::Copilot->value => [Chat::IM_TYPE_COPILOT],
			ExtendedType::System->value => [Chat::IM_TYPE_SYSTEM],
		];

		$openTypes = [ExtendedType::OpenChat->value, ExtendedType::General->value, ExtendedType::OpenChannel->value, ExtendedType::GeneralChannel->value];

		foreach ($withLiteral as $extendedType => $typeInfo)
		{
			$this->registerType(new Type($typeInfo[0], $typeInfo[1] ?? null, $extendedType, in_array($extendedType, $openTypes, true)));
		}

		// Open collab shares display extendedType 'COLLAB' with closed collab
		// (frontend sees single 'collab' type), but uses a distinct registryKey.
		$this->registerType(new Type(
			literal: Chat::IM_TYPE_OPEN_COLLAB,
			entityType: ExtendedType::Sonet->value,
			extendedType: ExtendedType::Collab->value,
			isOpen: true,
			registryKey: self::OPEN_COLLAB_REGISTRY_KEY,
		));
	}

	private function registerFallbacks(): void
	{
		$this->fallbackByLiteral = [
			Chat::IM_TYPE_COLLAB => new Type(Chat::IM_TYPE_COLLAB, null, ExtendedType::Collab->value),
			Chat::IM_TYPE_OPEN_COLLAB => new Type(
				Chat::IM_TYPE_OPEN_COLLAB,
				null,
				ExtendedType::Collab->value,
				true,
				self::OPEN_COLLAB_REGISTRY_KEY,
			),
		];
	}

	private function loadSystemEntity(): void
	{
		$entityOnly = [
			ExtendedType::Announcement->value,
			ExtendedType::Videoconference->value,
			ExtendedType::Support24Notifier->value,
			ExtendedType::Support24Question->value,
			ExtendedType::NetworkDialog->value,
			ExtendedType::Mail->value,
			ExtendedType::Crm->value,
			ExtendedType::Sonet->value,
			ExtendedType::Tasks->value,
			ExtendedType::Call->value,
		];

		foreach ($entityOnly as $extendedType)
		{
			$this->registerType(new Type(
				Chat::IM_TYPE_CHAT,
				$extendedType,
				$extendedType,
			));
		}

		$this->registerType(new Type(
			literal: Chat::IM_TYPE_CHAT,
			entityType: ExtendedType::Calendar->value,
			extendedType: ExtendedType::Calendar->value,
			requiresParentMembership: false,
		));
	}

	private function loadExternal(): void
	{
		foreach ($this->externalTypeRegistry->getTypes() as $type)
		{
			$this->registerType($type);
		}
	}

	private function registerType(Type $type): void
	{
		$this->registry[$type->getRegistryKey()] = $type;
		$this->byLiteralAndEntity[$type->literal][$type->entityType ?? ''] = $type;
		$this->fillByRecentSection($type);
	}

	private function fillByRecentSection(Type $type): void
	{
		$registryKey = $type->getRegistryKey();

		foreach ($this->recentConfigManager->getAllRecentSectionsByType($type) as $section)
		{
			$this->byRecentSection[$section][$registryKey] = $type;
		}

		foreach ($this->recentConfigManager->getExcludedComposedSections($type) as $section)
		{
			$this->byRecentSectionExclude[$section][$registryKey] = $type;
		}
	}
}
