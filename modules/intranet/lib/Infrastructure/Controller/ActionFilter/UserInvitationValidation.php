<?php

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Intranet\Public\Type\Collection\InvitationCollection;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\PhoneNumber\Parser;

class UserInvitationValidation extends Engine\ActionFilter\Base
{
	public function __construct(
		private Parser $phoneParser,
	)
	{
		parent::__construct();
	}

	final public function onBeforeAction(Event $event)
	{
		/** @var \Bitrix\Main\Engine\Action $action */
		$action = $event->getParameter('action');
		$arguments = $action->getArguments();
		/** @var \Bitrix\Intranet\Public\Type\Collection\InvitationCollection $invitationCollection */
		$invitationCollection = $this->extractInvitationCollection($arguments);

		if (!$invitationCollection)
		{
			return null;
		}

		$emailInvitation = $invitationCollection->filter(fn ($invitation) => $invitation instanceof EmailInvitation);
		$phoneInvitation = $invitationCollection->filter(fn ($invitation) => $invitation instanceof PhoneInvitation);
		$invalidEmails = $emailInvitation->getInvalidInvitations();
		$invalidPhones = $phoneInvitation->getInvalidInvitations();

		if (!$invalidEmails->empty())
		{
			$emailList = $invalidEmails->map(fn (EmailInvitation $invitation) => $invitation->getEmail());
			$this->addError(new Error(
				Loc::getMessage('INTRANET_INVITATION_INVALID_EMAIL_ERROR', [
					"#EMAIL_LIST#" => implode(', ', $emailList),
				]),
				'EMAIL_INVALID_ERROR',
				['emailList' => $emailList],
			));
		}

		if (!$invalidPhones->empty())
		{
			$phoneList = $invalidPhones->map(fn (PhoneInvitation $invitation) => $invitation->getPhone());
			$this->addError(new Error(
				Loc::getMessage('INTRANET_INVITATION_INVALID_PHONE_ERROR', [
					"#PHONE_LIST#" => implode(', ', $phoneList),
				]),
				'PHONE_INVALID_ERROR',
				['phoneList' => $phoneList],
			));
		}

		if ($this->errorCollection->isEmpty())
		{
			return null;
		}

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function extractInvitationCollection(array $arguments): ?InvitationCollection
	{
		foreach ($arguments as $argument)
		{
			if ($argument instanceof InvitationCollection)
			{
				return $argument;
			}
		}

		return null;
	}
}
