<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;

final class AccessCheckHandlerFactory
{
	private ServiceLocator $serviceLocator;

	public function __construct()
	{
		$this->serviceLocator = ServiceLocator::getInstance();
	}

	public function create(?AttachedObject $attachedObject = null): AccessCheckHandler
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		if ($currentUserId > 0)
		{
			$unifiedLinkAccessCheckHandler = $this->serviceLocator->get(UnifiedLinkAccessCheckHandler::class);
			$attachedObjectsAccessCheckHandler = new AttachedObjectsAccessCheckHandler($attachedObject);
			$permissionSystemAccessCheckHandler = new PermissionSystemAccessCheckHandler();
			
			return (new ExternalLinkAccessCheckHandler())
				->setNext($unifiedLinkAccessCheckHandler
					->setNext($permissionSystemAccessCheckHandler
						->setNext($attachedObjectsAccessCheckHandler),
					),
				)
			;
		}

		return new ExternalLinkAccessCheckHandler();
	}
}
