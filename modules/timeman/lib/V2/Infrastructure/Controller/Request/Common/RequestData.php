<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common;

use Bitrix\Main\HttpRequest;

final class RequestData
{
	public static function fromHttpRequest(HttpRequest $request): array
	{
		return array_merge(
			$request->toArray(),
			$request->getJsonList()->toArray(),
		);
	}
}
