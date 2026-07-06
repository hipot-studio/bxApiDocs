<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\IncomingWebhook;

use Bitrix\Main;

class IncomingWebhookAttributeCollection extends Main\Entity\EntityCollection
{
	protected static function getEntityClass(): string
	{
		return IncomingWebhookAttribute::class;
	}

	public function getByCode(string $code): ?IncomingWebhookAttribute
	{
		return $this->find(fn(IncomingWebhookAttribute $attribute) => $attribute->getCode() === $code);
	}
}
