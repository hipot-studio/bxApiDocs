<?php

namespace Bitrix\Disk\Internals\Engine\ActionFilter;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\TrackedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Type;
use Bitrix\Disk\Version;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Sign\Signer;

class CheckReadPermission extends ActionFilter\Base
{
	const ERROR_COULD_NOT_READ_OBJECT = 'read_right';

	protected $currentUser;
	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected Signer $signer;

	public function __construct()
	{
		parent::__construct();
		$this->currentUser = CurrentUser::get();
		$this->unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
		$this->signer = new Signer();
	}

	public function onBeforeAction(Event $event)
	{
		foreach ($this->action->getArguments() as $argument)
		{
			if ($argument instanceof BaseObject)
			{
				if (!$this->checkObject($argument))
				{
					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			elseif ($argument instanceof Storage)
			{
				if (!$argument->canRead($argument->getSecurityContext($this->currentUser->getId())))
				{
					$this->addReadError();

					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			elseif ($argument instanceof AttachedObject)
			{
				if (!$argument->canRead($this->currentUser->getId()))
				{
					$this->addReadError();

					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			elseif ($argument instanceof TrackedObject)
			{
				if (!$this->checkTrackedObject($argument))
				{
					$this->addReadError();

					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			elseif ($argument instanceof Version)
			{
				$file = $argument->getObject();
				if (!$file)
				{
					continue;
				}

				$securityContext = $file->getStorage()->getSecurityContext($this->currentUser->getId());
				if (!$file->canRead($securityContext))
				{
					$this->addReadError();

					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			elseif ($argument instanceof Type\ObjectCollection)
			{
				foreach ($argument as $item)
				{
					if (!$this->checkObject($item))
					{
						return new EventResult(EventResult::ERROR, null, null, $this);
					}
				}
			}
			elseif ($argument instanceof Type\TrackedObjectCollection)
			{
				foreach ($argument as $item)
				{
					if (!$this->checkTrackedObject($item))
					{
						return new EventResult(EventResult::ERROR, null, null, $this);
					}
				}
			}
		}

		return null;
	}

	protected function checkTrackedObject(TrackedObject $trackedObject): bool
	{
		if (!$this->currentUser->getId() || !$trackedObject->canRead($this->currentUser->getId()))
		{
			$this->addReadError();

			return false;
		}

		return true;
	}

	protected function checkObject(BaseObject $object): bool
	{
		$uls = $this->action->getController()->getRequest()->getQuery('_uls');

		if (
			$object instanceof File
			&& $object->supportsUnifiedLink()
			&& $this->unifiedLinkAccessService->check($object)->canRead()
			&& is_string($uls)
			&& $this->signer->validate((string)$object->getId(), $uls)
		)
		{
			return true;
		}

		$securityContext = $object->getStorage()?->getSecurityContext($this->currentUser->getId());
		if (!$securityContext)
		{
			return false;
		}

		if (!$object->canRead($securityContext))
		{
			$this->addReadError();

			return false;
		}

		return true;
	}

	protected function addReadError(): void
	{
		$this->errorCollection[] = new Error(
			Loc::getMessage('DISK_CHECK_READ_PERMISSION_ERROR_MESSAGE'), self::ERROR_COULD_NOT_READ_OBJECT
		);
	}

}