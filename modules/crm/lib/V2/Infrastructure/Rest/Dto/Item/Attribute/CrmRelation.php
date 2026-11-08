<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CrmRelation
{
	public function __construct(public readonly int $targetEntityTypeId)
	{
	}
}
