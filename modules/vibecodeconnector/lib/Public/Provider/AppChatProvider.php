<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatOrigin;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppAudienceResolver;

final class AppChatProvider
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AppAudienceResolver $audienceResolver = new AppAudienceResolver(),
	) {}

	public function filterUserIds(int $catalogItemId, int $chatId, array $userIds): array
	{
		$userIds = $this->normalizeIds($userIds);
		$item = $this->getActiveItemForChat($catalogItemId, $chatId);
		if ($item === null)
		{
			$item = $this->getItemForServerInitiatedCreation($catalogItemId, $chatId);
			if ($item === null)
			{
				return ['userIds' => [], 'undecidedUserIds' => []];
			}

			$ownerId = $item->getOwnerId();
			$userIds = array_values(array_filter(
				$userIds,
				static fn (int $userId): bool => $userId === $ownerId,
			));
		}

		$decision = $this->audienceResolver->resolveAudience($item, $userIds);
		$allowed = array_fill_keys($decision->withAccess, true);

		return [
			'userIds' => array_values(array_filter(
				$userIds,
				static fn (int $userId): bool => isset($allowed[$userId]),
			)),
			'undecidedUserIds' => $decision->undecided,
		];
	}

	public function getOwnerId(int $catalogItemId, int $chatId): ?int
	{
		return $this->getActiveItemForChat($catalogItemId, $chatId)?->getOwnerId();
	}

	public function resolveManageCapabilities(array $chatIds, int $userId): array
	{
		$chatIds = $this->normalizeIds($chatIds);
		$items = $this->itemRepository->getByAppChatIds($chatIds);
		$capabilities = [];
		foreach ($chatIds as $chatId)
		{
			$capabilities[$chatId] = isset($items[$chatId])
				&& !$items[$chatId]->isDeactivated()
				&& $items[$chatId]->getType() === CatalogItemType::Application
				&& $items[$chatId]->getOwnerId() === $userId;
		}

		return $capabilities;
	}

	public function createEntityLink(string $entityType, int $catalogItemId, int $chatId): ?array
	{
		if ($entityType !== AppChatType::ENTITY_TYPE || $catalogItemId <= 0 || $chatId <= 0)
		{
			return null;
		}

		return [
			'type' => AppChatType::ENTITY_TYPE,
			'chatId' => $chatId,
			'entityId' => (string)$catalogItemId,
			'url' => '/bitrix/services/main/ajax.php?action=vibecodeconnector.Catalog.openAppPage'
				. '&catalogItemId=' . rawurlencode((string)$catalogItemId),
		];
	}

	public function isCreationAllowed(string $entityType): bool
	{
		return $entityType !== AppChatType::ENTITY_TYPE || AppChatOrigin::isServerInitiated();
	}

	private function getActiveItemForChat(int $catalogItemId, int $chatId): ?CatalogItem
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if (
			$item === null
			|| $item->isDeactivated()
			|| $item->getType() !== CatalogItemType::Application
			|| $chatId <= 0
			|| (int)$item->getAppChatId() !== $chatId
		)
		{
			return null;
		}

		return $item;
	}

	private function getItemForServerInitiatedCreation(int $catalogItemId, int $chatId): ?CatalogItem
	{
		if (!AppChatOrigin::isServerInitiated() || $catalogItemId <= 0 || $chatId <= 0)
		{
			return null;
		}

		$item = $this->itemRepository->getById($catalogItemId);
		if (
			$item === null
			|| $item->isDeactivated()
			|| $item->getType() !== CatalogItemType::Application
			|| (int)$item->getAppChatId() !== 0
			|| $item->getOwnerId() <= 0
		)
		{
			return null;
		}

		return $item;
	}

	private function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn (int $id): bool => $id > 0,
		)));
	}
}
