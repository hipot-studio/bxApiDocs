<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Response\Tracking;

use Bitrix\Rest\V3\Interaction\Response\Response;

final class AdReportProgressResponse extends Response
{
	public function __construct(public bool $complete, public ?string $label)
	{
	}
}
