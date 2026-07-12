<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Type;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalTypeRegistry;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Common\FormatConverter;
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
	/** @var array<string, array<string, Type>> */
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

		return new Type(Chat::IM_TYPE_CHAT, $entityType, $entityType ?? 'CHAT');
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
		$displayKey = $type->getExtendedType(camelCase: false);
		$registryKey = $type->getRegistryKey();

		foreach ($this->recentConfigManager->getAllRecentSectionsByType($displayKey) as $section)
		{
			$this->byRecentSection[$section][$registryKey] = $type;
		}

		foreach ($this->recentConfigManager->getExcludedComposedSections($displayKey) as $section)
		{
			$this->byRecentSectionExclude[$section][$registryKey] = $type;
		}
	}
}
