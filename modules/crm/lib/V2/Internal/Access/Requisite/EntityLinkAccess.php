<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Requisite;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Requisite\EntityLinkService;

/**
 * Access rules of the link between a CRM entity and the requisites chosen in it.
 *
 * The link has no rights of its own: it follows the entity that owns it. Reading a link follows the
 * read right of that entity, and writing one follows its update right - choosing a requisite is a
 * change of the deal, the invoice or the quote, not of the requisite.
 *
 * A question may name the entity in full, name only its type, or name nothing at all. Naming only a
 * type asks whether the user may read entities of that type; naming nothing asks whether there is at
 * least one type among {@see EntityLinkService::getAvailableEntityTypeIds()} the user may read, which
 * is the question a list without a filter asks. Writing knows no such widening: an update is always
 * about one entity, and an unnamed one is refused by the permission itself.
 *
 * @internal
 */
final class EntityLinkAccess
{
	private readonly EntityLinkService $linkService;

	/**
	 * @param int|null $userId `null` means the current user.
	 */
	public function __construct(
		private readonly ?int $userId = null,
		?EntityLinkService $linkService = null
	)
	{
		$this->linkService = $linkService ?? EntityLinkService::getInstance();
	}

	public function canReadOwnerEntity(int $entityTypeId = 0, int $entityId = 0): bool
	{
		$userPermissions = Container::getInstance()->getUserPermissions($this->userId);

		if ($entityTypeId <= 0 && $entityId <= 0)
		{
			foreach ($this->linkService->getAvailableEntityTypeIds() as $availableEntityTypeId)
			{
				if ($userPermissions->entityType()->canReadItems($availableEntityTypeId))
				{
					return true;
				}
			}

			return false;
		}

		if ($entityId <= 0)
		{
			return $userPermissions->entityType()->canReadItems($entityTypeId);
		}

		return $userPermissions->item()->canRead($entityTypeId, $entityId);
	}

	public function canUpdateOwnerEntity(int $entityTypeId = 0, int $entityId = 0): bool
	{
		return Container::getInstance()
			->getUserPermissions($this->userId)
			->item()
			->canUpdate($entityTypeId, $entityId)
		;
	}
}
