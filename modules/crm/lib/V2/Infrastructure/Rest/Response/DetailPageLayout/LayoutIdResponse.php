<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Response\DetailPageLayout;

use Bitrix\Rest\V3\Interaction\Response\Response;

final class LayoutIdResponse extends Response
{
	public function __construct(public string $layoutId)
	{
	}
}
