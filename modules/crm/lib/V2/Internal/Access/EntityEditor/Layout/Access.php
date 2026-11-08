<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\EntityEditor\Layout;

use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\EntityForm\ScopeAccess;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Throwable;

/**
 * @internal
 */
final class Access
{
	public function canCreateCustom(int $actorUserId, string $configId): bool
	{
		if ($actorUserId <= 0 || $configId === '')
		{
			return false;
		}

		try
		{
			return (new ScopeAccess(Context::UI_MODULE_CATEGORY, $actorUserId))
				->canAddByEntityTypeId($configId)
			;
		}
		catch (Throwable)
		{
			return false;
		}
	}

	public function canReadCustom(int $actorUserId, int $scopeId): bool
	{
		return $this->canAccessCustom($actorUserId, $scopeId, 'read');
	}

	public function canUpdateCustom(int $actorUserId, int $scopeId): bool
	{
		return $this->canAccessCustom($actorUserId, $scopeId, 'update');
	}

	public function canDeleteCustom(int $actorUserId, int $scopeId): bool
	{
		return $this->canAccessCustom($actorUserId, $scopeId, 'delete');
	}

	public function canActForSubject(int $actorUserId, int $subjectUserId): bool
	{
		if ($actorUserId <= 0 || $subjectUserId <= 0)
		{
			return false;
		}

		if ($actorUserId === $subjectUserId)
		{
			return true;
		}

		return Container::getInstance()
			->getUserPermissions($actorUserId)
			->isCrmAdmin()
		;
	}

	public function canRead(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
	): bool
	{
		return $this->canAccess($actorUserId, $targetUserId, $context, $scope, false);
	}

	public function canWrite(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
	): bool
	{
		return $this->canAccess($actorUserId, $targetUserId, $context, $scope, true);
	}

	public function canReset(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
	): bool
	{
		return $this->canAccess($actorUserId, $targetUserId, $context, $scope, true);
	}

	public function canSwitchToPersonalView(int $subjectUserId, Context $context): bool
	{
		if ($subjectUserId <= 0 || $context->entityTypeId <= 0)
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions($subjectUserId)
			->entityEditor()
			->canSwitchToPersonalView($context->entityTypeId, $context->categoryId ?? 0)
		;
	}

	private function canAccess(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
		bool $isWrite,
	): bool
	{
		if ($actorUserId <= 0 || $context->entityTypeId <= 0)
		{
			return false;
		}

		if ($scope === EntityEditorConfigScope::PERSONAL)
		{
			if ($targetUserId === null || $targetUserId <= 0)
			{
				return false;
			}

			return Container::getInstance()
				->getUserPermissions($actorUserId)
				->entityEditor()
				->canEditPersonalViewForUser($targetUserId)
			;
		}

		if ($scope !== EntityEditorConfigScope::COMMON || $targetUserId !== null)
		{
			return false;
		}

		if (!$isWrite)
		{
			return true;
		}

		return Container::getInstance()
			->getUserPermissions($actorUserId)
			->entityEditor()
			->canEditCommonView($context->entityTypeId, $context->categoryId)
		;
	}

	private function canAccessCustom(int $actorUserId, int $scopeId, string $operation): bool
	{
		if ($actorUserId <= 0 || $scopeId <= 0)
		{
			return false;
		}

		try
		{
			$access = new ScopeAccess(Context::UI_MODULE_CATEGORY, $actorUserId);

			return match ($operation) {
				'read' => $access->canRead($scopeId),
				'update' => $access->canUpdate($scopeId),
				'delete' => $access->canDelete($scopeId),
				default => false,
			};
		}
		catch (Throwable)
		{
			return false;
		}
	}
}
