<?php

namespace Bitrix\Disk\Internals\Engine\ActionFilter;

use Bitrix\Disk;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CheckExternalLinkSettingsPermission extends ActionFilter\Base
{
	private const EDIT_RIGHT_ACTIONS = [
		'allowEditDocument',
		'disallowEditDocument',
	];

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

			$securityContext = $this->getSecurityContext($object);
			if (!$securityContext || !$this->canManageSettings($object, $securityContext))
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

	protected function getSecurityContext(Disk\BaseObject $object): ?Disk\Security\SecurityContext
	{
		return $object->getStorage()?->getCurrentUserSecurityContext();
	}

	private function canManageSettings(
		Disk\BaseObject $object,
		Disk\Security\SecurityContext $securityContext,
	): bool
	{
		if (in_array($this->action->getName(), self::EDIT_RIGHT_ACTIONS, true))
		{
			return $object->canUpdate($securityContext);
		}

		return $object->canManageExternalLink($securityContext);
	}
}
