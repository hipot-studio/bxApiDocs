<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog;

use Bitrix\Main\Web\Uri;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;

final class CatalogItemDtoMapper
{
	public function __construct(
		private readonly IconStorageService $iconStorage = new IconStorageService(),
	) {}

	public function toDto(CatalogItem $item): CatalogItemDto
	{
		$dto = new CatalogItemDto();
		$dto->catalogItemId = (int)$item->getId();
		$dto->title = $item->getTitle();
		$dto->type = $item->getType()->value;
		$dto->accessType = $item->getAccessType()->value;
		$dto->description = $item->getDescription();
		$dto->editUrl = $item->getEditUrl();
		$dto->viewUrl = $item->getViewUrl();
		$dto->iconUrl = $this->resolveIconUrl($item);
		$dto->chatId = $item->getChatId();
		$dto->externalId = $item->getExternalId();
		$dto->ownerId = $item->getOwnerId();
		$dto->color = $item->getColor();
		$dto->createdAt = $item->getCreatedAt()?->format('c');
		$dto->updatedAt = $item->getUpdatedAt()?->format('c');

		return $dto;
	}

	/**
	 * Resolves the icon public URL as an absolute URI. Local storage yields a relative
	 * /upload/... path that must be prefixed with the portal scheme and host, while
	 * cloud storage already returns an absolute URL that Uri::toAbsolute() keeps as is.
	 */
	private function resolveIconUrl(CatalogItem $item): ?string
	{
		$fileId = $item->getIconFileId();
		if ($fileId === null)
		{
			return null;
		}

		$url = $this->iconStorage->getPublicUrl($fileId);

		return $url !== null ? (new Uri($url))->toAbsolute()->getUri() : null;
	}
}
