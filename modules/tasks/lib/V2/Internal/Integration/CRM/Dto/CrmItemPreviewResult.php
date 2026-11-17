<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Dto;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;

final readonly class CrmItemPreviewResult
{
	public function __construct(
		public CrmItemCollection $items,
		public int $total,
	)
	{
	}
}
