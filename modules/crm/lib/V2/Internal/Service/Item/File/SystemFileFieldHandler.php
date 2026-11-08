<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

interface SystemFileFieldHandler
{
	public function getFieldName(): string;

	public function isMultiple(): bool;

	public function supports(EntityType $entityType, string $fieldName): bool;

	public function checkAccess(
		EntityType $entityType,
		?int $userId,
		LegacyItem $legacyItem,
		string $action,
		FileFieldWriteRequest $request,
	): Result;

	public function process(
		Field $field,
		LegacyItem $legacyItem,
		FileFieldWriteRequest $request,
		UserPermissions $userPermissions,
		object $context,
	): Result;

	/** @param array<string, array<int, int>> $uploadedFileIds */
	public function applyFinalState(Item $item, FileFieldWriteRequest $request, array $uploadedFileIds): void;
}
