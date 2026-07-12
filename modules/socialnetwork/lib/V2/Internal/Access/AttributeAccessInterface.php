<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access;

use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;

interface AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool;
}
