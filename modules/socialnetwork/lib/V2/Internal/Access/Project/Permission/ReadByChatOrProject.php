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
		$projectId = $this->resolveProjectId($entity);
		if (!$projectId)
		{
			return false;
		}

		return Container::getInstance()->getProjectAccessService()->canRead($context->getUserId(), $projectId);
	}

	private function resolveProjectId(EntityInterface $entity): int
	{
		$projectId = (int)$entity->getId();
		if ($projectId > 0)
		{
			return $projectId;
		}

		$chatId = property_exists($entity, 'chatId') ? (int)($entity->chatId) : 0;
		if (!$chatId)
		{
			return 0;
		}

		return Container::getInstance()->getProjectChatResolver()->getProjectIdByChatId($chatId) ?? 0;
	}
}
