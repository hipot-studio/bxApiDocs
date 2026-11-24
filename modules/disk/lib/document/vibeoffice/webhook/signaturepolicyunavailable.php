<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

final class SignaturePolicyUnavailable extends \RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, $previous);
	}
}
