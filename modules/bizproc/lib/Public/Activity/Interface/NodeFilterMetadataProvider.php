<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

interface NodeFilterMetadataProvider
{
	/**
	 * @return array{
	 *     entityTypeOptions: array<int|string, string>,
	 *     filterFieldsMap: array<int|string, array>,
	 *     documentTypeMap: array<int|string, array>,
	 * }
	 */
	public static function getNodeFilterMetadata(
		array $contextDocumentType,
		bool $onlyDynamicEntities = false,
	): array;
}
