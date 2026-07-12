<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\LegacyGroup\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DeleteOutgoingRequest implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$entityId = (int)$entity->getId();
		$userId = $context->getUserId();

		if ($entityId <= 0 || $userId <= 0)
		{
			return false;
		}

		$incomingInviteFlags = Container::getInstance()
			->getProjectMemberRepository()
			->getIncomingInviteFlags([$entityId], $userId)
		;

		return isset($incomingInviteFlags[$entityId]);
	}
}
