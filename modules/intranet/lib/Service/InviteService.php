<?php

namespace Bitrix\Intranet\Service;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\Collection\InvitationCollection;
use Bitrix\Intranet\Entity\Invitation;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Loader;

class InviteService
{
	public function massInvite(array $invite)
	{
		$processedUserIdList = $invitationCollection->getUserIds();
		if (!empty($processedUserIdList))
		{
			$event = new Event(
				'intranet',
				'onUserInvited',
				[
					'originatorId' => $invitation->getOriginatorId(),
					'userId' => $processedUserIdList,
					'type' => $invitation->getType()?->value ?? InvitationType::EMAIL->value
				]
			);
			$event->send();
		}
	}

	public function invite()
	{

	}

	public function inviteUser(User $user, InvitationType $type, string $formType): Invitation
	{
		$invitationRepository = ServiceContainer::getInstance()->invitationRepository();

		return $invitationRepository->save(new Invitation(
			userId: $user->getId(),
			initialized: false,
			isMass: $formType === 'mass',
			isDepartment: $formType === 'group',
			isIntegrator: false,
			isRegister: false,
			id: null,
			originatorId: CurrentUser::get()->getId(),
			type: $type,
		));
	}

	public function sendInvite(User $user, $messageText)
	{
		$bExtranet = $user->isExtranet();
		$isCloud = Loader::includeModule('bitrix24');

		if ($isCloud && $user->getId() !== null)
		{
			$networkEmail = (new \Bitrix\Bitrix24\Integration\Network\Invitation())->getEmailByUserId($user->getId());
			$emailTo = $networkEmail ?? $user->getEmail();
		}
		else
		{
			$emailTo = $user->getEmail();
		}

		$siteIdByDepartmentId = \CIntranetInviteDialog::getUserSiteId([
			"UF_DEPARTMENT" => $user->getDepartmetnsIds(),
			"SITE_ID" => SITE_ID
		]);

		if ($bExtranet)
		{
			$messageId = \CIntranetInviteDialog::getMessageId("EXTRANET_INVITATION", $siteIdByDepartmentId, LANGUAGE_ID);
			$eventName = 'EXTRANET_INVITATION';
			$params = [
				"USER_ID" => $user->getId(),
				"USER_ID_FROM" => CurrentUser::get()->getId(),
				"CHECKWORD" => $user->getConfirmCode(),
				"EMAIL" => $emailTo,
				"USER_TEXT" => $messageText
			];
		}
		elseif ($isCloud)
		{
			$messageId = \CIntranetInviteDialog::getMessageId("BITRIX24_USER_INVITATION", $siteIdByDepartmentId, LANGUAGE_ID);
			$eventName = 'BITRIX24_USER_INVITATION';
			$params = [
				"EMAIL_FROM" => CurrentUser::get()->getEmail(),//$USER->GetEmail(),
				"USER_ID_FROM" => CurrentUser::get()->getId(),
				"EMAIL_TO" => $emailTo,
				"LINK" => \CIntranetInviteDialog::getInviteLink(['ID' => $user->getId(), 'CONFIRM_CODE' => $user->getConfirmCode()], $siteIdByDepartmentId),
				"USER_TEXT" => $messageText,
			];
		}
		else
		{
			$messageId = \CIntranetInviteDialog::getMessageId("INTRANET_USER_INVITATION", $siteIdByDepartmentId, LANGUAGE_ID);
			$eventName = 'INTRANET_USER_INVITATION';
			$params = [
				"EMAIL_TO" => $emailTo,
				"USER_ID_FROM" => CurrentUser::get()->getId(),
				"LINK" => \CIntranetInviteDialog::getInviteLink(['ID' => $user->getId(), 'CONFIRM_CODE' => $user->getConfirmCode()], $siteIdByDepartmentId),
				"USER_TEXT" => $messageText,
			];
		}

		\CEvent::SendImmediate(
			$eventName,
			$siteIdByDepartmentId,
			$params,
			null,
			$messageId
		);
	}
}