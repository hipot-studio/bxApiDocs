<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview;

use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Preview\CatalogItemPreview;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

class CatalogItemPreviewService
{
	public const PAGE_PATH_PREFIX = '/vibecode/app/';

	private const DESCRIPTION_MAX_LENGTH = 200;
	private const ELLIPSIS = '...';

	private array $previewMemo = [];

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AvailabilityService $availabilityService = new AvailabilityService(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
		private readonly ImGate $imGate = new ImGate(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
	) {
	}

	public function isPreviewAvailableToUser(int $catalogItemId, int $userId): bool
	{
		return $this->getPreview($catalogItemId, $userId) !== null;
	}

	public function getPreview(int $catalogItemId, int $userId): ?CatalogItemPreview
	{
		$key = $catalogItemId . '|' . $userId;
		if (array_key_exists($key, $this->previewMemo))
		{
			return $this->previewMemo[$key];
		}

		return $this->previewMemo[$key] = $this->buildPreview($catalogItemId, $userId);
	}

	private function buildPreview(int $catalogItemId, int $userId): ?CatalogItemPreview
	{
		$item = $this->loadItemForPreview($catalogItemId, $userId);
		if ($item === null)
		{
			return null;
		}

		$domain = $this->imGate->getPublicDomain();
		$ownerId = $item->getOwnerId();

		return new CatalogItemPreview(
			title: $item->getTitle(),
			description: $this->resolveDescription($item),
			color: $item->getColor(),
			pageUrl: $domain . self::PAGE_PATH_PREFIX . (int)$item->getId() . '/',
			iconUrl: $this->resolveIconUrl($item, $domain),
			authorName: $this->imGate->getUserName($ownerId),
			authorAvatarUrl: $this->toAbsoluteUrl($this->imGate->getUserAvatarPath($ownerId), $domain),
			authorProfileUrl: $this->toAbsoluteUrl($this->imGate->getUserProfilePath($ownerId), $domain),
			appChatId: $item->getAppChatId(),
		);
	}

	private function loadItemForPreview(int $catalogItemId, int $userId): ?CatalogItem
	{
		if ($catalogItemId <= 0 || $userId <= 0)
		{
			return null;
		}

		$item = $this->itemRepository->getById($catalogItemId);
		if (
			$item === null
			|| $item->isDeactivated()
			|| $item->getType() !== CatalogItemType::Application
			|| !$this->availabilityService->isAvailableForUser($userId)
			|| !$this->isAccessibleTo($item, $userId)
		)
		{
			return null;
		}

		return $item;
	}

	private function isAccessibleTo(CatalogItem $item, int $userId): bool
	{
		if ($item->getOwnerId() === $userId || $item->getAccessType() === CatalogItemAccessType::Public)
		{
			return true;
		}

		return $item->getAccessType() === CatalogItemAccessType::ACL
			&& $this->itemRepository->isAccessibleToUser(
				(int)$item->getId(),
				$userId,
				$this->accessCodes->getUserCodesForItems($userId, [(int)$item->getId()]),
			);
	}

	private function resolveDescription(CatalogItem $item): string
	{
		$description = $this->flatten((string)$item->getDescription());
		if ($description === '')
		{
			return (string)Loc::getMessage('VIBECODECONNECTOR_CATALOG_ITEM_PREVIEW_DESCRIPTION_DEFAULT');
		}

		if (mb_strlen($description) <= self::DESCRIPTION_MAX_LENGTH)
		{
			return $description;
		}

		return mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH) . self::ELLIPSIS;
	}

	private function flatten(string $text): string
	{
		return trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
	}

	private function resolveIconUrl(CatalogItem $item, string $domain): ?string
	{
		$iconFileId = $item->getIconFileId();
		if ($iconFileId === null)
		{
			return null;
		}

		return $this->toAbsoluteUrl((string)$this->iconStorage->getPublicUrl($iconFileId), $domain);
	}

	private function toAbsoluteUrl(string $path, string $domain): ?string
	{
		if ($path === '')
		{
			return null;
		}

		if (preg_match('#^https?://#i', $path) === 1)
		{
			return $path;
		}

		return $domain . $path;
	}
}
