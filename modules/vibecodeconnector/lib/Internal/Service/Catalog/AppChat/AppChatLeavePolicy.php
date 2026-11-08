<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogUserRepository;

final class AppChatLeavePolicy
{
	public function __construct(
		private readonly CatalogUserRepository $catalogUserRepository = new CatalogUserRepository(),
	) {}

	public function holdsOut(CatalogItem $item, int $userId): bool
	{
		return !self::isExemptFromLeaveMark($userId, $item)
			&& $this->catalogUserRepository->isMarkedLeft((int)$item->getId(), $userId)
		;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterHeldOut(CatalogItem $item, array $userIds): array
	{
		return array_values(array_filter(
			$this->catalogUserRepository->filterLeft((int)$item->getId(), $userIds),
			static fn (int $userId): bool => !self::isExemptFromLeaveMark($userId, $item),
		));
	}

	private static function isExemptFromLeaveMark(int $userId, CatalogItem $item): bool
	{
		return $userId === $item->getOwnerId();
	}
}
