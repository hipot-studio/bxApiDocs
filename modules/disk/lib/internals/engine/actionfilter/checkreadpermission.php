<?php

namespace Bitrix\Disk\Internals\Engine\ActionFilter;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\TrackedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
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

class CheckReadPermission extends ActionFilter\Base
{
	const ERROR_COULD_NOT_READ_OBJECT = 'read_right';

	protected $currentUser;
	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected UnifiedLinkSignature $unifiedLinkSignature;

	public function __construct()
	{
		parent::__construct();
		$this->currentUser = CurrentUser::get();
		$this->unifiedLinkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkSignature = new UnifiedLinkSignature();
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
				if (!$this->checkVersion($argument))
				{
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

	/**
	 * A version carries no rights of its own: the file it belongs to answers for it, an orphaned one
	 * for nothing at all. The `_uls` of the pair is accepted next to the unified link check, exactly as
	 * the file branch accepts the one naming the file — and the signature naming the file alone is not
	 * accepted here, so it opens no revision of the history.
	 */
	protected function checkVersion(Version $version): bool
	{
		$file = $version->getObject();
		if (!$file)
		{
			$this->addReadError();

			return false;
		}

		$uls = $this->action->getController()->getRequest()->getQuery('_uls');

		// Cheapest conjunct first: the access chain of the link is walked only for a request that carries
		// a signature naming this pair. Every conjunct stays obligatory — the signature opens nothing on
		// its own.
		if (
			is_string($uls)
			&& $file->supportsUnifiedLink()
			&& $this->unifiedLinkSignature->validateUlsForVersion((int)$file->getId(), (int)$version->getId(), $uls)
			&& $this->unifiedLinkAccessService->check($file)->canRead()
		)
		{
			return true;
		}

		$securityContext = $file->getStorage()?->getSecurityContext($this->currentUser->getId());
		if (!$securityContext)
		{
			$this->addReadError();

			return false;
		}

		if (!$file->canRead($securityContext))
		{
			$this->addReadError();

			return false;
		}

		return true;
	}

	protected function checkObject(BaseObject $object): bool
	{
		$uls = $this->action->getController()->getRequest()->getQuery('_uls');

		// Cheapest conjunct first: the access chain of the link is walked only for a request that carries
		// a signature naming this object. Every conjunct stays obligatory — the signature opens nothing on
		// its own.
		if (
			$object instanceof File
			&& is_string($uls)
			&& $object->supportsUnifiedLink()
			&& $this->unifiedLinkSignature->validateUlsForObjectId((int)$object->getId(), $uls)
			&& $this->unifiedLinkAccessService->check($object)->canRead()
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