<?php

namespace Bitrix\Mobile\Menu;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Tourist;
use Bitrix\Main\Loader;

class AhaMoment
{
	private const RELOCATION_EVENT = 'mobile_relocation_ava_menu_aha_moment';
	private const RELOCATION_THRESHOLD_TS = 1757894400; // 2025-09-15

	// TODO: add THRESHOLD AFTER RELEASE
	private const CALL_LIST_EVENT = 'mobile_menu_call_list_aha_moment';
	private const MAIL_LIST_EVENT = 'mobile_menu_mail_list_aha_moment';

	public function getMenuAhaMoment(): ?array
	{
		$callListAhaMoment = $this->getCallListAhaMoment();
		if ($callListAhaMoment !== null)
		{
			return $callListAhaMoment;
		}

		$mailListAhaMoment = $this->getMailListAhaMoment();
		if ($mailListAhaMoment !== null)
		{
			return $mailListAhaMoment;
		}

		if (!Loader::includeModule('intranetmobile'))
		{
			return null;
		}

		$invitationService = new \Bitrix\IntranetMobile\Invitation\InvitationAhaMoment();
		$invitation = $invitationService->getUnacceptedInvitesAhaMoment();
		if ($invitation)
		{
			return [
				'type' => 'invitation',
				'title' => $invitation->title,
				'description' => $invitation->description,
			];
		}

		return null;
	}

	public function getAvatarAhaMoment(int $userId): ?array
	{
		$relocationAhaMoment = $this->getRelocationAhaMoment($userId);
		if ($relocationAhaMoment !== null)
		{
			return $relocationAhaMoment;
		}

		if (!Loader::includeModule('intranetmobile'))
		{
			return null;
		}

		$invitationService = new \Bitrix\IntranetMobile\Invitation\InvitationAhaMoment();
		$invitation = $invitationService->getFirstInviteAhaMoment();
		if ($invitation !== null)
		{
			return [
				'type' => 'first_invitation',
				'title' => $invitation->title,
				'description' => $invitation->description,
				'eventName' => $invitationService::FIRST_INVITE_TOURIST_EVENT,
			];
		}

		return null;
	}

	private function getRelocationAhaMoment(int $userId): ?array
	{
		if ($this->isRelocationShownForUser())
		{
			return null;
		}

		$user = \Bitrix\Main\UserTable::getById($userId)->fetchObject();
		if (!$user)
		{
			return null;
		}

		$userRegisteredAt = $user->getDateRegister()?->getTimestamp();

		if ($userRegisteredAt === null || $userRegisteredAt >= self::RELOCATION_THRESHOLD_TS)
		{
			return null;
		}

		return [
			'type' => 'relocation',
			'title' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_RELOCATION_TITLE'),
			'description' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_RELOCATION_DESCRIPTION'),
			'eventName' => self::RELOCATION_EVENT,
		];
	}

	private function isRelocationShownForUser(): bool
	{
		$events = Tourist::getEvents();

		return isset($events[self::RELOCATION_EVENT]);
	}

	private function getCallListAhaMoment(): ?array
	{
		if (!Loader::includeModule('callmobile'))
		{
			return null;
		}

		if ($this->isCallListAhaMomentShown())
		{
			return null;
		}

		return [
			'type' => 'call_list',
			'title' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_CALL_LIST_TITLE'),
			'description' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_CALL_LIST_DESCRIPTION'),
			'eventName' => self::CALL_LIST_EVENT,
		];
	}

	private function isCallListAhaMomentShown(): bool
	{
		$events = Tourist::getEvents();

		return isset($events[self::CALL_LIST_EVENT]);
	}

	private function getMailListAhaMoment(): ?array
	{
		if (!Loader::includeModule('mailmobile') || !Loader::includeModule('mail'))
		{
			return null;
		}

		if ($this->isMailListAhaMomentShown() || empty(MailboxTable::getUserMailboxes()))
		{
			return null;
		}

		return [
			'type' => 'mail_list',
			'title' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_MAIL_LIST_TITLE'),
			'description' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_MAIL_LIST_DESCRIPTION'),
			'eventName' => self::MAIL_LIST_EVENT,
		];
	}

	private function isMailListAhaMomentShown(): bool
	{
		$events = Tourist::getEvents();

		return isset($events[self::MAIL_LIST_EVENT]);
	}
}
