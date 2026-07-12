<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

use Bitrix\Rest\Enum\APAuth\PasswordType;

final readonly class IncomingWebhookFilter
{
	public function __construct(
		public ?int $userId = null,
		public array $scopes = [],
		public array $attributes = [],
		public ?PasswordType $type = null,
	)
	{
	}
}
