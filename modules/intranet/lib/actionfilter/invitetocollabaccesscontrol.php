<?php

namespace Bitrix\Intranet\ActionFilter;

use Bitrix\Extranet;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Intranet\Integration\Socialnetwork\Collab\CollabProviderData;

class InviteToCollabAccessControl extends ActionFilter\Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$collabId = $this->getAction()->getArguments()['collabId']
			?? $this->getAction()->getBinder()->getMethodParams()['collabId']
			?? null;

		if (!$collabId)
		{
			$this->addError(new Error('Collab ID is not specified.'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (
			!Loader::includeModule('socialnetwork')
			|| !Collab\Requirement::checkRequiredModules()->isSuccess()
			|| !Loader::includeModule('extranet')
		)
		{
			return $this->denyAccess(
				'INTRANET_COLLAB_ACCESS_CONTROL_PORTAL_ACCESS_DENIED',
				'COLLAB_INVITE_MODULES_UNAVAILABLE',
			);
		}

		if (!Extranet\PortalSettings::getInstance()->isEnabledCollabersInvitation())
		{
			return $this->denyAccess(
				'INTRANET_COLLAB_ACCESS_CONTROL_PORTAL_INVITATION_COLLABERS_DISABLED',
				'COLLAB_INVITE_PORTAL_DISABLED',
			);
		}

		$group = GroupRegistry::getInstance()->get($collabId);
		if (!$group instanceof Collab\Collab)
		{
			$this->addError(new Error(
				$this->getErrorMessage('INTRANET_COLLAB_ACCESS_CONTROL_COLLAB_NOT_FOUND'),
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$canInviteCurrentUser = Collab\Access\CollabAccessController::can(
			$this->getAction()->getCurrentUser()?->getId(),
			Collab\Access\CollabDictionary::INVITE,
			$collabId,
		);

		if (!$canInviteCurrentUser)
		{
			return $this->denyAccess(
				'INTRANET_COLLAB_ACCESS_CONTROL_USER_ACCESS_DENIED',
				'COLLAB_INVITE_ACCESS_DENIED',
			);
		}

		if (!Collab\CollabFeature::isOn())
		{
			return $this->denyAccess(
				'INTRANET_COLLAB_ACCESS_CONTROL_PORTAL_ACCESS_DENIED',
				'COLLAB_INVITE_TECHNICAL_DISABLED',
			);
		}

		if (!Collab\CollabFeature::isFeatureEnabled())
		{
			return $this->denyAccess(
				'INTRANET_COLLAB_ACCESS_CONTROL_PORTAL_ACCESS_DENIED',
				'COLLAB_INVITE_FEATURE_UNAVAILABLE',
			);
		}

		$requirementResult = Collab\Requirement::check();
		if (!$requirementResult->isSuccess())
		{
			$this->addError(new Error(
				$requirementResult->getErrors()[0]->getMessage(),
				'COLLAB_INVITE_EXTRANET_NOT_CONFIGURED',
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (!(new CollabProviderData())->isAllowedGuestsInvitation($collabId))
		{
			return $this->denyAccess(
				'INTRANET_COLLAB_ACCESS_CONTROL_GUEST_INVITATION_FORBIDDEN',
				'COLLAB_INVITE_GROUP_DISABLED',
			);
		}

		return null;
	}

	private function denyAccess(string $messageCode, string $errorCode): EventResult
	{
		$this->addError(new Error($this->getErrorMessage($messageCode), $errorCode));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function getErrorMessage(string $code): ?string
	{
		if (
			Loader::includeModule('socialnetwork')
			&& class_exists(Feature::class)
			&& Feature::isNewProjectsOn()
		)
		{
			return Loc::getMessage($code . '_V2') ?? Loc::getMessage($code);
		}

		return Loc::getMessage($code);
	}
}
