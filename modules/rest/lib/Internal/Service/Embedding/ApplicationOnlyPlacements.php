<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Rest\Api\UserFieldType;

final class ApplicationOnlyPlacements
{
	public const CODES = [
		UserFieldType::PLACEMENT_UF_TYPE,
		'PAGE_BACKGROUND_WORKER',
		'BI_ANALYTICS_MENU',
		'CONTACT_CENTER',
	];

	public function contains(string $placement): bool
	{
		return in_array(mb_strtoupper($placement), self::CODES, true);
	}
}
