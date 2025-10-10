<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\AccessCheckHandlerFactory;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileResolver;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class UnifiedLinkAccessChecker extends Base
{
	private readonly AccessCheckHandlerFactory $accessCheckHandlerFactory;

	public function __construct(
		private readonly UnifiedLinkAccessLevel $targetAccessLevel,
	)
	{
		parent::__construct();

		$serviceLocator = ServiceLocator::getInstance();
		$this->accessCheckHandlerFactory = $serviceLocator->get(AccessCheckHandlerFactory::class);
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$arguments = $this->getAction()->getArguments();

		$file = $arguments['file'] ?? null;
		if (!($file instanceof File))
		{
			return null;
		}

		$attachedObject = $arguments['attachedObject'] ?? null;
		$version = $arguments['version'] ?? null;

		$accessCheckHandler = $this->accessCheckHandlerFactory->create($attachedObject);

		$accessLevel = $accessCheckHandler->check(FileResolver::resolve($file, $version));

		if ($accessLevel->value < $this->targetAccessLevel->value)
		{
			Context::getCurrent()?->getResponse()?->setStatus(403);
			$this->addError(new Error('Access level does not match the required level.'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
