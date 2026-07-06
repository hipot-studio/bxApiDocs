<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\IncomingWebhook;

use Bitrix\Main;

class IncomingWebhookCollection extends Main\Entity\EntityCollection
{
	protected static function getEntityClass(): string
	{
		return IncomingWebhook::class;
	}
}
