<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler;

use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Enum\DepartmentAccessCode;
use Bitrix\Tasks\V2\Internal\LoggerInterface;
use Bitrix\Tasks\V2\Internal\Service\Access\TemplatePermissionCleanupService;
use Throwable;

final class TemplatePermission
{
	private const LOG_MARKER = 'TASKS_TEMPLATE_PERMISSION_CLEANUP';

	public static function onAfterUserUpdate(array $fields): EventResult
	{
		return self::runSafely(static function () use ($fields): void {
			self::cleanupDismissedUserPermissions($fields);
		});
	}

	public static function onAfterUserDelete(int $userId): EventResult
	{
		return self::runSafely(static function () use ($userId): void {
			self::cleanupUserPermissions($userId);
		});
	}

	public static function onNodeDeleted(Event $event): EventResult
	{
		return self::runSafely(static function () use ($event): void {
			self::cleanupDeletedNodePermissions($event);
		});
	}

	public static function onSocNetGroupDelete(int $groupId): EventResult
	{
		return self::runSafely(static function () use ($groupId): void {
			self::cleanupGroupPermissions($groupId);
		});
	}

	private static function runSafely(callable $cleanup): EventResult
	{
		try
		{
			$cleanup();
		}
		catch (Throwable $throwable)
		{
			self::getLogger()->logWarning($throwable, self::LOG_MARKER);
		}

		return new EventResult(EventResult::SUCCESS);
	}

	private static function cleanupDismissedUserPermissions(array $fields): void
	{
		$isDismissed = isset($fields['ACTIVE']) && $fields['ACTIVE'] === 'N';
		$isUpdated = (bool)($fields['RESULT'] ?? false);

		if (!$isDismissed || !$isUpdated)
		{
			return;
		}

		self::cleanupUserPermissions((int)($fields['ID'] ?? 0));
	}

	private static function cleanupUserPermissions(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		self::getCleanupService()->deleteByAccessCodes(['U' . $userId]);
	}

	private static function cleanupDeletedNodePermissions(Event $event): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		$node = $event->getParameter('node');
		if (!$node instanceof Node || $node->type !== NodeEntityType::DEPARTMENT)
		{
			return;
		}

		// The all employees grant is stored as the recursive code of the root department, so the codes
		// of the root are not its own. An unparented node is the root, same check as in HumanResources.
		if (!$node->parentId)
		{
			return;
		}

		$accessCodes = self::getDepartmentAccessCodes((int)$node->id, $node->accessCode);
		if (empty($accessCodes))
		{
			return;
		}

		self::getCleanupService()->deleteByAccessCodes($accessCodes);
	}

	/**
	 * @return string[]
	 */
	private static function getDepartmentAccessCodes(int $nodeId, ?string $accessCode): array
	{
		$accessCodes = [];
		if ($nodeId > 0)
		{
			$accessCodes[] = DepartmentAccessCode::Structure->value . $nodeId;
			$accessCodes[] = DepartmentAccessCode::StructureRecursive->value . $nodeId;
		}

		$legacyDepartmentId = DepartmentAccessCode::getId($accessCode);
		if ($legacyDepartmentId !== null && str_starts_with((string)$accessCode, DepartmentAccessCode::Legacy->value))
		{
			$accessCodes[] = DepartmentAccessCode::Legacy->value . $legacyDepartmentId;
			$accessCodes[] = DepartmentAccessCode::LegacyRecursive->value . $legacyDepartmentId;
		}

		return $accessCodes;
	}

	private static function cleanupGroupPermissions(int $groupId): void
	{
		if ($groupId <= 0)
		{
			return;
		}

		self::getCleanupService()->deleteGroupPermissions($groupId);
	}

	private static function getCleanupService(): TemplatePermissionCleanupService
	{
		return Container::getInstance()->get(TemplatePermissionCleanupService::class);
	}

	private static function getLogger(): LoggerInterface
	{
		return Container::getInstance()->get(LoggerInterface::class);
	}
}
