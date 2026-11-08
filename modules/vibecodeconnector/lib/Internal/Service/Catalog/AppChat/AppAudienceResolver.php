<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Integration\Intranet\IntranetGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;

final class AppAudienceResolver
{
	public function __construct(
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly IntranetGate $intranetGate = new IntranetGate(),
	) {}

	public function filterUsersWithAccess(
		CatalogItem $item,
		array $userIds,
		?array $itemCodes = null,
	): array {
		return $this->resolveAudience($item, $userIds, $itemCodes)->withAccess;
	}

	public function resolveAudience(
		CatalogItem $item,
		array $userIds,
		?array $itemCodes = null,
	): AudienceDecision {
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		$employeeDecision = $this->intranetGate->resolveIntranetUsers($userIds);

		$granted = array_fill_keys(
			match ($item->getAccessType())
			{
				CatalogItemAccessType::Private => $this->filterOwner($item, $employeeDecision->employees),
				CatalogItemAccessType::Public => $employeeDecision->employees,
				CatalogItemAccessType::ACL => $this->resolveAclGranted(
					$item,
					$employeeDecision->employees,
					$itemCodes,
				),
			},
			true,
		);
		$undecided = array_fill_keys($employeeDecision->undecided, true);

		return new AudienceDecision(
			array_values(array_filter(
				$userIds,
				static fn (int $userId): bool => isset($granted[$userId]),
			)),
			array_values(array_filter(
				$userIds,
				static fn (int $userId): bool => isset($undecided[$userId]),
			)),
		);
	}

	public function hasAccess(CatalogItem $item, int $userId): bool
	{
		return in_array($userId, $this->filterUsersWithAccess($item, [$userId]), true);
	}

	private function filterOwner(CatalogItem $item, array $userIds): array
	{
		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => $userId === $item->getOwnerId(),
		));
	}

	/**
	 * @return int[]
	 */
	private function resolveAclGranted(
		CatalogItem $item,
		array $userIds,
		?array $itemCodes,
	): array
	{
		$itemCodes ??= $this->accessRepository->getCodesForItem((int)$item->getId());
		$ownerId = $item->getOwnerId();
		$granted = array_flip($this->accessCodes->resolveCodeHolders($userIds, $itemCodes)->holders);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => $userId === $ownerId || isset($granted[$userId]),
		));
	}
}
