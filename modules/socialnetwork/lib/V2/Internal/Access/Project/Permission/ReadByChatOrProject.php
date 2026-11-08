<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ReadByChatOrProject implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$groupId = $this->resolveGroupId($entity);
		if ($groupId <= 0)
		{
			return false;
		}

		// The group type picks the permissions: LegacyGroupAccessService sends collabs to the project ones
		// and any other group type to the group ones, so a chat whose group is not a collab is still read
		return Container::getInstance()->getLegacyGroupAccessService()->canRead($context->getUserId(), $groupId);
	}

	private function resolveGroupId(EntityInterface $entity): int
	{
		$groupId = (int)$entity->getId();
		if ($groupId > 0)
		{
			return $groupId;
		}

		$chatId = property_exists($entity, 'chatId') ? (int)($entity->chatId) : 0;
		if ($chatId <= 0)
		{
			return 0;
		}

		return Container::getInstance()->getProjectChatResolver()->getGroupIdByChatId($chatId) ?? 0;
	}
}
