<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity;

enum EntityType: string
{
	/** @see SONET_ENTITY_GROUP */
	case Group = 'G';

	/** @see SONET_ENTITY_USER */
	case User = 'U';
}
