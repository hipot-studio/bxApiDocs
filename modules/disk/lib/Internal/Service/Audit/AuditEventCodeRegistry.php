<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventGroup;
use InvalidArgumentException;

final class AuditEventCodeRegistry
{
	public const TARGET_FILE = 'file';
	public const TARGET_FOLDER = 'folder';
	public const TARGET_RIGHTS = 'rights';
	public const TARGET_PUBLIC_LINK = 'public_link';
	public const TARGET_UNIFIED_LINK = 'unified_link';
	public const TARGET_TRASHCAN = 'trashcan';
	public const TARGET_AUDIT_LOG = 'audit_log';

	public const SUBJECT_ACCESS_SUBJECT = 'access_subject';
	public const SUBJECT_INHERITANCE = 'inheritance';
	public const SUBJECT_PUBLIC_LINK = 'public_link';
	public const SUBJECT_UNIFIED_LINK = 'unified_link';
	public const SUBJECT_STORAGE = 'storage';

	/**
	 * @return array<string, array{
	 *     code: AuditEventCode,
	 *     group: AuditEventGroup,
	 *     targetTypes: string[],
	 *     subjectType: ?string,
	 *     mass: bool,
	 *     cascade: bool,
	 *     requiredDetailKeys: string[],
	 *     optionalDetailKeys: string[]
	 * }>
	 */
	public static function getMap(): array
	{
		static $map = null;

		if ($map === null)
		{
			$map = self::buildMap();
		}

		return $map;
	}

	public static function get(AuditEventCode|string $code): array
	{
		$codeValue = $code instanceof AuditEventCode ? $code->value : $code;
		$map = self::getMap();

		if (!isset($map[$codeValue]))
		{
			throw new InvalidArgumentException(sprintf('Unknown disk audit event code "%s".', $codeValue));
		}

		return $map[$codeValue];
	}

	/**
	 * @return string[]
	 */
	public static function getAllowedDetailKeys(AuditEventCode|string $code): array
	{
		$metadata = self::get($code);

		return [...$metadata['requiredDetailKeys'], ...$metadata['optionalDetailKeys']];
	}

	public static function has(AuditEventCode|string $code): bool
	{
		$codeValue = $code instanceof AuditEventCode ? $code->value : $code;

		return isset(self::getMap()[$codeValue]);
	}

	/**
	 * @return string[]
	 */
	public static function getFilterCodes(): array
	{
		return [
			...AuditEventCode::values(),
			...AuditSettingsEventFactory::eventCodes(),
		];
	}

	public static function hasFilterCode(string $code): bool
	{
		return in_array($code, self::getFilterCodes(), true);
	}

	/**
	 * @return AuditEventCode[]
	 */
	public static function getCodes(): array
	{
		return array_values(array_map(
			static fn(array $item): AuditEventCode => $item['code'],
			self::getMap(),
		));
	}

	private static function buildMap(): array
	{
		$map = [
			self::item(
				AuditEventCode::ObjectCreated,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				false,
				false,
				['objectType'],
			),
			self::item(
				AuditEventCode::ObjectContentChanged,
				AuditEventGroup::Object,
				[self::TARGET_FILE],
				null,
				false,
				false,
				['oldSize', 'newSize'],
			),
			self::item(
				AuditEventCode::ObjectCopied,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				true,
				false,
				['sourceObjectId', 'targetParentId'],
			),
			self::item(
				AuditEventCode::ObjectRenamed,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				false,
				false,
				['oldName', 'newName'],
			),
			self::item(
				AuditEventCode::ObjectMoved,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				true,
				false,
				['oldParentId', 'newParentId', 'oldStorageId', 'newStorageId'],
			),
			self::item(
				AuditEventCode::ObjectMarkedDeleted,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				true,
				false,
				['deletedType'],
			),
			self::item(
				AuditEventCode::ObjectRestored,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				true,
				false,
				['restoredParentId'],
			),
			self::item(
				AuditEventCode::ObjectPermanentlyDeleted,
				AuditEventGroup::Object,
				[self::TARGET_FILE, self::TARGET_FOLDER],
				null,
				true,
				false,
				['deleteScope'],
			),
			self::item(
				AuditEventCode::TrashcanEmptied,
				AuditEventGroup::Trashcan,
				[self::TARGET_TRASHCAN],
				self::SUBJECT_STORAGE,
				false,
				true,
				['storageId'],
			),

			self::item(
				AuditEventCode::RightsSubjectAdded,
				AuditEventGroup::Rights,
				[self::TARGET_RIGHTS],
				self::SUBJECT_ACCESS_SUBJECT,
				false,
				false,
				['accessCode', 'newTaskName'],
			),
			self::item(
				AuditEventCode::RightsSubjectChanged,
				AuditEventGroup::Rights,
				[self::TARGET_RIGHTS],
				self::SUBJECT_ACCESS_SUBJECT,
				false,
				false,
				['accessCode', 'oldTaskName', 'newTaskName'],
			),
			self::item(
				AuditEventCode::RightsSubjectRemoved,
				AuditEventGroup::Rights,
				[self::TARGET_RIGHTS],
				self::SUBJECT_ACCESS_SUBJECT,
				false,
				false,
				['accessCode', 'oldTaskName'],
			),
			self::item(
				AuditEventCode::RightsInheritanceEnabled,
				AuditEventGroup::Rights,
				[self::TARGET_RIGHTS],
				self::SUBJECT_INHERITANCE,
				false,
				false,
				['oldMode', 'newMode'],
			),
			self::item(
				AuditEventCode::RightsInheritanceDisabled,
				AuditEventGroup::Rights,
				[self::TARGET_RIGHTS],
				self::SUBJECT_INHERITANCE,
				false,
				false,
				['oldMode', 'newMode'],
			),
			self::item(
				AuditEventCode::RightsCascadeChanged,
				AuditEventGroup::Rights,
				[self::TARGET_RIGHTS],
				null,
				false,
				true,
				['cascadeRootObjectId', 'addedCount', 'changedCount', 'removedCount'],
			),

			self::item(
				AuditEventCode::PublicLinkCreated,
				AuditEventGroup::Link,
				[self::TARGET_PUBLIC_LINK],
				self::SUBJECT_PUBLIC_LINK,
				false,
				false,
				['accessRight', 'ttlSet', 'passwordEnabled', 'canEditSettings'],
			),
			self::item(
				AuditEventCode::PublicLinkTtlChanged,
				AuditEventGroup::Link,
				[self::TARGET_PUBLIC_LINK],
				self::SUBJECT_PUBLIC_LINK,
				false,
				false,
				['oldTtl', 'newTtl'],
			),
			self::item(
				AuditEventCode::PublicLinkPasswordEnabled,
				AuditEventGroup::Link,
				[self::TARGET_PUBLIC_LINK],
				self::SUBJECT_PUBLIC_LINK,
				false,
				false,
				['passwordEnabled'],
			),
			self::item(
				AuditEventCode::PublicLinkPasswordDisabled,
				AuditEventGroup::Link,
				[self::TARGET_PUBLIC_LINK],
				self::SUBJECT_PUBLIC_LINK,
				false,
				false,
				['passwordEnabled'],
			),
			self::item(
				AuditEventCode::PublicLinkAccessChanged,
				AuditEventGroup::Link,
				[self::TARGET_PUBLIC_LINK],
				self::SUBJECT_PUBLIC_LINK,
				false,
				false,
				['oldAccessRight', 'newAccessRight', 'canDownloadWithReadAccess'],
			),
			self::item(
				AuditEventCode::PublicLinkDisabled,
				AuditEventGroup::Link,
				[self::TARGET_PUBLIC_LINK],
				self::SUBJECT_PUBLIC_LINK,
				false,
				false,
				['disabled'],
			),
			self::item(
				AuditEventCode::UnifiedLinkAccessChanged,
				AuditEventGroup::Link,
				[self::TARGET_UNIFIED_LINK],
				self::SUBJECT_UNIFIED_LINK,
				false,
				false,
				['oldAccessLevel', 'newAccessLevel'],
			),
			self::item(
				AuditEventCode::AuditLogOpened,
				AuditEventGroup::Object,
				[self::TARGET_AUDIT_LOG],
				null,
				false,
				false,
				[],
			),
		];

		$indexedMap = [];
		foreach ($map as $item)
		{
			$codeValue = $item['codeValue'];
			if (AuditRightsDetails::isSubjectEvent($codeValue))
			{
				$item['optionalDetailKeys'] = [
					...array_diff($item['requiredDetailKeys'], ['accessCode']),
					'oldDomain',
					'newDomain',
					'oldNegative',
					'newNegative',
					'oldRights',
					'newRights',
					'sourceGroupId',
					'targetGroupId',
				];
				$item['requiredDetailKeys'] = ['accessCode'];
			}
			unset($item['codeValue']);
			$indexedMap[$codeValue] = $item;
		}

		return $indexedMap;
	}

	private static function item(
		AuditEventCode $code,
		AuditEventGroup $group,
		array $targetTypes,
		?string $subjectType,
		bool $mass,
		bool $cascade,
		array $requiredDetailKeys,
	): array
	{
		return [
			'codeValue' => $code->value,
			'code' => $code,
			'group' => $group,
			'targetTypes' => $targetTypes,
			'subjectType' => $subjectType,
			'mass' => $mass,
			'cascade' => $cascade,
			'requiredDetailKeys' => $requiredDetailKeys,
			'optionalDetailKeys' => [],
		];
	}
}
