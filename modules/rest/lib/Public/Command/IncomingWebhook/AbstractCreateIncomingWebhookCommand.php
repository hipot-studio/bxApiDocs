<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

abstract class AbstractCreateIncomingWebhookCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly ?string $title,
		public readonly array $scopes,
		public readonly array $attributes = [],
		public readonly ?string $comment = null,
	)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'title' => $this->title,
			'scopes' => $this->scopes,
			'attributes' => $this->attributes,
			'comment' => $this->comment,
		];
	}
}
