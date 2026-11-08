<?php

namespace Bitrix\Rest\V3\Access;

/**
 * Request context of a field method passed to FieldAccessProvider.
 *
 * $module is the module that owns the resource, $resourceDtoFqcn is the DTO of the resource
 * itself: the field methods are served by the system controllers of rest, so $dtoFqcn names
 * the DTO of the response (DtoFieldDto, CustomDto, EnumDto) and never the resource.
 *
 * $entityId is the user field entity id of the resource. It is absent for the DTO field
 * schema methods (field.list, field.get), which have no user field entity behind them.
 */
final readonly class FieldAccessContext
{
	public function __construct(
		public int $userId,
		public string $module,
		public string $actionUri,
		public string $action,
		public ?string $dtoFqcn,
		public ?string $resourceDtoFqcn = null,
		public ?string $entityId = null,
		public array $arguments = [],
	)
	{
	}
}
