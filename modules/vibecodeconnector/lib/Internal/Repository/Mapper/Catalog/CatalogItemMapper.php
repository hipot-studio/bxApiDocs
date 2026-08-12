<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Mapper\Catalog;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemForUser;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

final class CatalogItemMapper
{
	/**
	 * @param array<string, mixed> $row
	 */
	public function fromRow(array $row): CatalogItem
	{
		$createdAt = $row['CREATED_AT'] instanceof DateTime ? $row['CREATED_AT'] : null;
		$updatedAt = $row['UPDATED_AT'] instanceof DateTime ? $row['UPDATED_AT'] : null;
		$deactivatedAt = ($row['DEACTIVATED_AT'] ?? null) instanceof DateTime ? $row['DEACTIVATED_AT'] : null;

		$item = new CatalogItem(
			title: (string)$row['TITLE'],
			type: CatalogItemType::from((string)$row['TYPE']),
			accessType: CatalogItemAccessType::from((string)$row['ACCESS_TYPE']),
			ownerId: (int)$row['OWNER_ID'],
			color: (string)$row['COLOR'],
			iconFileId: isset($row['ICON_FILE_ID']) ? (int)$row['ICON_FILE_ID'] : null,
			chatId: isset($row['CHAT_ID']) ? (int)$row['CHAT_ID'] : null,
			externalId: $row['EXTERNAL_ID'] ?? null,
			description: $row['DESCRIPTION'] ?? null,
			editUrl: $row['EDIT_URL'] ?? null,
			viewUrl: $row['VIEW_URL'] ?? null,
			pairingIss: $row['PAIRING_ISS'] ?? null,
			deactivatedAt: $deactivatedAt,
			createdAt: $createdAt,
			updatedAt: $updatedAt,
		);
		if (isset($row['ID']))
		{
			$item->setId((int)$row['ID']);
		}

		return $item;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function fromRowForUser(array $row): CatalogItemForUser
	{
		return new CatalogItemForUser(
			item: $this->fromRow($row),
			isPinned: (bool)($row['IS_PINNED'] ?? false),
			isOwnedByUser: (bool)($row['IS_OWNED_BY_USER'] ?? false),
			isHidden: (bool)($row['IS_HIDDEN'] ?? false),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRow(CatalogItem $item): array
	{
		return [
			'TITLE' => $item->getTitle(),
			'TYPE' => $item->getType()->value,
			'ICON_FILE_ID' => $item->getIconFileId(),
			'CHAT_ID' => $item->getChatId(),
			'EXTERNAL_ID' => $item->getExternalId(),
			'DESCRIPTION' => $item->getDescription(),
			'EDIT_URL' => $item->getEditUrl(),
			'VIEW_URL' => $item->getViewUrl(),
			'ACCESS_TYPE' => $item->getAccessType()->value,
			'OWNER_ID' => $item->getOwnerId(),
			'COLOR' => $item->getColor(),
			'PAIRING_ISS' => $item->getPairingIss(),
			'DEACTIVATED_AT' => $item->getDeactivatedAt(),
			'CREATED_AT' => $item->getCreatedAt(),
			'UPDATED_AT' => $item->getUpdatedAt(),
		];
	}
}
