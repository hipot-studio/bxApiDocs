<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: true)]
class NotifyCustomMessage extends AbstractNotify
{
	public function __construct(
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly string $message,
	)
	{
	}

	public function toString(): string
	{
		return $this->message;
	}

	public function getAuthorId(): int
	{
		return $this->triggeredBy?->id ?? 0;
	}

	public function getMessageCode(): string
	{
		return '';
	}
}
