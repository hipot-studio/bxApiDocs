<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Access;

enum EntityType: string
{
	case IncomingWebhook = 'incoming_webhook';
	case LocalApp = 'local_app';
	case MarketplaceApp = 'marketplace_app';
}
