<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;

final class CatalogItemEmbeddingOwner
{
	public const MODULE_ID = 'vibecodeconnector';
	public const ENTITY_TYPE = 'catalog_item';

	public static function forItem(int $catalogItemId): EmbeddingOwnerDto
	{
		return new EmbeddingOwnerDto(self::MODULE_ID, self::ENTITY_TYPE, (string)$catalogItemId);
	}
}
