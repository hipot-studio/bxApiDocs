<?php

declare(strict_types=1);

namespace Bitrix\Intranet\ActionFilter;

use Bitrix\Intranet\Internal\Access\CollabInvitationLinkPermission;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\EventResult;

class CollabInvitationLinkAccessControl extends ActionFilter\Base
{
	private readonly CollabInvitationLinkPermission $permission;

	public function __construct(?CollabInvitationLinkPermission $permission = null)
	{
		parent::__construct();

		$this->permission = $permission ?? new CollabInvitationLinkPermission();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		if ($this->permission->canManage($this->getCurrentUserId(), $this->getCollabId()))
		{
			return null;
		}

		$this->addError(new Error(
			'Access denided',
		));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	protected function getCollabId(): int
	{
		$arguments = $this->getAction()->getArguments();
		$binderArguments = $this->getAction()->getBinder()?->getMethodParams() ?? [];

		return (int)($arguments['collabId'] ?? $binderArguments['collabId'] ?? 0);
	}

	protected function getCurrentUserId(): int
	{
		return (int)($this->getAction()->getCurrentUser()?->getId() ?? 0);
	}
}
