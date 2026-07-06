<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\IncomingWebhook;

final class WebhookPager
{
	public function __construct(
		private readonly int $offset = 0,
		private readonly int $limit = 50,
	)
	{
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}
}
