<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\IncomingWebhook;

class IncomingWebhookExternalAttribute extends IncomingWebhookAttribute
{
	public function __construct(
		?int $passwordId,
		string $code,
		?string $value = null,
	)
	{
		parent::__construct(
			$passwordId,
			$code,
			$value,
			self::TYPE_EXTERNAL,
		);
	}
}
