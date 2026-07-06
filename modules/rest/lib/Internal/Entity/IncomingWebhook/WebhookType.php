<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\IncomingWebhook;

enum WebhookType
{
	case User;
	case System;
}
