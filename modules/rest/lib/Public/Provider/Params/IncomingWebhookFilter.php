<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

final readonly class IncomingWebhookFilter
{
	public function __construct(
		public ?int $userId = null,
		public array $scopes = [],
		public array $attributes = [],
	)
	{
	}
}
