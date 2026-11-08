<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

interface CacheKeyProviderInterface
{
	public static function getCacheKey(Context $context): ?array;
}
