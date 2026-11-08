<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Rest\EO_Placement;
use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;

final class EmbeddingOwnerResolver
{
	public const MODULE_ID_REST = 'rest';
	public const ENTITY_TYPE_APP = 'app';

	public const OWNER_SELECT = ['ID', 'APP_ID', 'MODULE_ID', 'ENTITY_TYPE', 'ENTITY_ID'];

	public static function normalize(array $fields): array
	{
		$moduleId = self::stringOrNull($fields['MODULE_ID'] ?? null);
		$entityType = self::stringOrNull($fields['ENTITY_TYPE'] ?? null);
		$entityId = self::stringOrNull($fields['ENTITY_ID'] ?? null);

		if ($moduleId !== null && $entityType !== null && $entityId !== null)
		{
			return self::normalizeExplicitOwner($fields, $moduleId, $entityType, $entityId);
		}

		return self::normalizeLegacyAppOwner($fields);
	}

	public static function canonicalizeOwnerPart(string $value): string
	{
		return mb_strtolower($value);
	}

	/**
	 * @return array{MODULE_ID: string, ENTITY_TYPE: string, ENTITY_ID: string}|null
	 */
	public static function resolveForEmbedding(Embedding $embedding): ?array
	{
		$owner = self::normalize([
			'APP_ID' => $embedding->getAppId(),
			'MODULE_ID' => $embedding->getModuleId(),
			'ENTITY_TYPE' => $embedding->getEntityType(),
			'ENTITY_ID' => $embedding->getEntityId(),
		]);
		if (empty($owner))
		{
			return null;
		}

		return [
			'MODULE_ID' => $owner['MODULE_ID'],
			'ENTITY_TYPE' => $owner['ENTITY_TYPE'],
			'ENTITY_ID' => $owner['ENTITY_ID'],
		];
	}

	public static function isNonAppOwner(array $row): bool
	{
		$owner = self::normalize($row);

		return $owner !== [] && $owner['APP_ID'] === null;
	}

	public static function isNonAppOwnerPlacement(EO_Placement $placement): bool
	{
		return self::isNonAppOwner([
			'APP_ID' => $placement->getAppId(),
			'MODULE_ID' => $placement->getModuleId(),
			'ENTITY_TYPE' => $placement->getEntityType(),
			'ENTITY_ID' => $placement->getEntityId(),
		]);
	}

	public static function isNonAppOwnerEmbedding(Embedding $embedding): bool
	{
		return self::isNonAppOwner([
			'APP_ID' => $embedding->getAppId(),
			'MODULE_ID' => $embedding->getModuleId(),
			'ENTITY_TYPE' => $embedding->getEntityType(),
			'ENTITY_ID' => $embedding->getEntityId(),
		]);
	}

	public static function isAppOwnerType(string $moduleId, string $entityType): bool
	{
		return self::canonicalizeOwnerPart($moduleId) === self::MODULE_ID_REST
			&& self::canonicalizeOwnerPart($entityType) === self::ENTITY_TYPE_APP;
	}

	public static function resolveAppId(array $row): ?int
	{
		if (
			!array_key_exists('MODULE_ID', $row)
			|| !array_key_exists('ENTITY_TYPE', $row)
			|| !array_key_exists('ENTITY_ID', $row)
		)
		{
			return null;
		}

		if (!self::isAppOwnerType((string)$row['MODULE_ID'], (string)$row['ENTITY_TYPE']))
		{
			return null;
		}

		$entityId = (string)$row['ENTITY_ID'];
		if (empty($entityId))
		{
			if (!array_key_exists('APP_ID', $row))
			{
				return null;
			}

			$appId = (int)$row['APP_ID'];

			return $appId > 0 ? $appId : null;
		}

		return self::parseAppId($entityId);
	}

	public static function parseAppId(string $value): ?int
	{
		$appId = (int)$value;

		return $appId > 0 && (string)$appId === $value ? $appId : null;
	}

	private static function normalizeExplicitOwner(
		array $fields,
		string $moduleId,
		string $entityType,
		string $entityId,
	): array
	{
		$moduleId = self::canonicalizeOwnerPart($moduleId);
		$entityType = self::canonicalizeOwnerPart($entityType);
		$appId = null;

		if ($moduleId === self::MODULE_ID_REST && $entityType === self::ENTITY_TYPE_APP)
		{
			$appId = self::parseAppId($entityId);
			if ($appId === null)
			{
				return [];
			}
		}

		$sourceAppId = (int)($fields['APP_ID'] ?? 0);
		if ($sourceAppId > 0 && $sourceAppId !== $appId)
		{
			return [];
		}

		return [
			'APP_ID' => $appId,
			'MODULE_ID' => $moduleId,
			'ENTITY_TYPE' => $entityType,
			'ENTITY_ID' => $entityId,
		];
	}

	private static function normalizeLegacyAppOwner(array $fields): array
	{
		$appId = (int)($fields['APP_ID'] ?? 0);
		if ($appId > 0)
		{
			return [
				'APP_ID' => $appId,
				'MODULE_ID' => self::MODULE_ID_REST,
				'ENTITY_TYPE' => self::ENTITY_TYPE_APP,
				'ENTITY_ID' => (string)$appId,
			];
		}

		return [];
	}

	private static function stringOrNull(mixed $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$value = (string)$value;

		return empty($value) ? null : $value;
	}
}
