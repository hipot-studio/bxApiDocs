<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access;

use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityCollectionInterface;

interface CollectionAttributeAccessInterface
{
	public function checkCollection(EntityCollectionInterface $entity, Context $context, array $parameters = []): bool;
}
