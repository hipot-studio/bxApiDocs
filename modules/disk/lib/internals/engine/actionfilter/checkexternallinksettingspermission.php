<?php

namespace Bitrix\Disk\Internals\Engine\ActionFilter;

use Bitrix\Disk;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkAccessService;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CheckExternalLinkSettingsPermission extends ActionFilter\Base
{
	private const EDIT_RIGHT_ACTIONS = [
		'allowEditDocument',
		'disallowEditDocument',
	];

	private readonly ExternalLinkAccessService $externalLinkAccessService;

	public function __construct()
	{
		parent::__construct();

		$this->externalLinkAccessService = ServiceLocator::getInstance()->get(ExternalLinkAccessService::class);
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		foreach ($this->action->getArguments() as $argument)
		{
			if (!$argument instanceof Disk\ExternalLink)
			{
				continue;
			}

			$object = $argument->getObject()?->getRealObject();
			if (!$object)
			{
				$this->errorCollection[] = new Error('object not found');

				return new EventResult(EventResult::ERROR, null, null, $this);
			}

			if (!$this->canManageSettings($object))
			{
				$this->errorCollection[] = new Error('invalid rights');

				return new EventResult(EventResult::ERROR, null, null, $this);
			}

			if (!$argument->canEditSettings())
			{
				$this->errorCollection[] = new Error(
					'External link settings editing is denied',
					Disk\ExternalLink::ERROR_SETTINGS_EDIT_DENIED,
				);

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		}

		return null;
	}

	/**
	 * Serves the EDIT_RIGHT_ACTIONS branch only: the external link branch resolves the context inside the service.
	 */
	protected function getSecurityContext(Disk\BaseObject $object): ?Disk\Security\SecurityContext
	{
		return $object->getStorage()?->getCurrentUserSecurityContext();
	}

	private function canManageSettings(Disk\BaseObject $object): bool
	{
		if (in_array($this->action->getName(), self::EDIT_RIGHT_ACTIONS, true))
		{
			$securityContext = $this->getSecurityContext($object);

			return $securityContext && $object->canUpdate($securityContext);
		}

		return $this->externalLinkAccessService->canManageExternalLink($object);
	}
}
