<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Create implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessUserErrorTrait;

	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$this->clearUserError();

		$siteId = (string)($parameters['siteId'] ?? SITE_ID);

		$service = Container::getInstance()->getProjectAccessService();
		$result = $service->canCreate($context->getUserId(), $siteId);
		if (!$result)
		{
			$this->setUserError($service->getUserError());
		}

		return $result;
	}
}
