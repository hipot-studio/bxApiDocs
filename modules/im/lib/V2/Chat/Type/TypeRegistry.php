<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Type;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalTypeRegistry;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Common\FormatConverter;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\SystemException;

class TypeRegistry
{
	/**
	 * @var array<string, Type>
	 */
	private array $registry = [];
	/**
	 * @var array<string, array<string, Type>>
	 */
	private array $byLiteralAndEntity = [];

	/**
	 * @var array<string, Type>
	 */
	private array $fallbackByLiteral = [];
	private ExternalTypeRegistry $externalTypeRegistry;
	private Logger $logger;

	public function __construct(ExternalTypeRegistry $externalTypeRegistry, Logger $logger)
	{
		$this->logger = $logger;
		$this->externalTypeRegistry = $externalTypeRegistry;
		$this->load();
	}

	public function getByExtendedType(string $type): Type
	{
		return $this->registry[$type] ?? new Type(Chat::IM_TYPE_CHAT, $type, $type);
	}

	public function getByLiteralAndEntity(string $literal, ?string $entityType): Type
	{
		return $this->tryGetByLiteralAndEntity($literal, $entityType) ?? $this->getFallback($literal, $entityType);
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
			$base?->allowsDynamic() && $entityType => new Type($literal, $entityType, $entityType), // C+CUSTOM, O+CUSTOM
			$base !== null => new Type($literal, $entityType, $base->extendedType), // P+ANY=PRIVATE, but save entityType
			default => throw new SystemException("Unknown type: {$literal}, {$entityType}"), // not found
		};
	}

	protected function getFallback(string $literal, ?string $entityType): Type
	{
		$fallbackType = $this->fallbackByLiteral[$literal] ?? null;
		if ($fallbackType)
		{
			return new Type($literal, $entityType, $fallbackType->extendedType);
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
		];

		foreach ($withLiteral as $extendedType => $typeInfo)
		{
			$this->registerType(new Type($typeInfo[0], $typeInfo[1] ?? null, $extendedType));
		}
	}

	private function registerFallbacks(): void
	{
		$this->fallbackByLiteral = [
			Chat::IM_TYPE_COLLAB => new Type(Chat::IM_TYPE_COLLAB, null, ExtendedType::Collab->value),
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
			ExtendedType::Calendar->value,
			ExtendedType::Mail->value,
			ExtendedType::Crm->value,
			ExtendedType::Sonet->value,
			ExtendedType::Tasks->value,
			ExtendedType::Call->value,
		];

		foreach ($entityOnly as $extendedType)
		{
			$this->registerType(new Type(Chat::IM_TYPE_CHAT, $extendedType, $extendedType));
		}
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
		$this->registry[$type->extendedType] = $type;
		$this->byLiteralAndEntity[$type->literal][$type->entityType ?? ''] = $type;
	}
}
