<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\Collection\BaseCollection;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\DepartmentInvitationAccessService;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\InvitationAvailabilityService;
use Bitrix\Intranet\User;

abstract class BaseTool extends ToolContract
{
	private ?InvitationAvailabilityService $invitationAvailabilityService = null;
	private ?DepartmentInvitationAccessService $departmentInvitationAccessService = null;

	public function canList(int $userId): bool
	{
		return
			$userId > 0
			&& (new User($userId))->isIntranet()
		;
	}

	public function canRun(int $userId): bool
	{
		try
		{
			return (new PermissionInvitation($userId))->canInvite();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function isRegisterByLinkAllowed(): bool
	{
		return $this->getInvitationAvailabilityService()->isRegisterByLinkAllowed();
	}

	protected function isPhoneInviteAllowed(): bool
	{
		return $this->getInvitationAvailabilityService()->isPhoneInviteAllowed();
	}

	protected function isLocalEmailProgram(): bool
	{
		return $this->getInvitationAvailabilityService()->isLocalEmailProgram();
	}

	protected function findSingle(
		BaseCollection $collection,
		string $identifier,
		string $valueName,
		callable $outputName,
	): mixed
	{
		return match ($collection->count())
		{
			0 => throw new McpException("{$valueName} with {$identifier} was not found."),
			1 => $collection->first(),
			default => throw new McpException(
				"Found {$collection->count()} {$valueName} entities with {$identifier}: " .
				implode(', ', $collection->map(
					fn($entity) => sprintf('%s (ID %d)', $outputName($entity), $entity->getId())
				))
			),
		};
	}

	protected function resolveDepartmentCollection(int $userId, ?int $departmentId): DepartmentCollection
	{
		return $this->getDepartmentInvitationAccessService()->resolveDepartmentCollection($userId, $departmentId);
	}

	protected function resolveDepartmentCollectionByIds(int $userId, array $departmentIds): DepartmentCollection
	{
		return $this->getDepartmentInvitationAccessService()->resolveDepartmentCollectionByIds($userId, $departmentIds);
	}

	private function getInvitationAvailabilityService(): InvitationAvailabilityService
	{
		return $this->invitationAvailabilityService ??= new InvitationAvailabilityService();
	}

	private function getDepartmentInvitationAccessService(): DepartmentInvitationAccessService
	{
		return $this->departmentInvitationAccessService ??= new DepartmentInvitationAccessService();
	}
}
