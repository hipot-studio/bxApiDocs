<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

/** @internal */
final class RequisiteContext
{
	public function __construct(
		public readonly int $requisiteId,
		public readonly int $ownerTypeId,
		public readonly int $ownerId,
		public readonly int $presetId,
		public readonly int $countryId,
	)
	{
	}
}
