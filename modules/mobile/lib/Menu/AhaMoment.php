<?php

namespace Bitrix\Mobile\Menu;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Feature\MyCompanyFeature;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Menu\MyCompany\MyCompanyProvider;
use Bitrix\Mobile\Menu\Promo\EmployeeThreshold;
use Bitrix\Mobile\Tourist;
use Bitrix\Main\Loader;

class AhaMoment
{
	private const RELOCATION_EVENT = 'mobile_relocation_ava_menu_aha_moment';
	private const RELOCATION_THRESHOLD_TS = 1757894400; // 2025-09-15
	private const MY_COMPANY_EVENT = 'mobile_my_company_aha_menu';
	private const USERS_FLOATING_BANNER_SHOWN_EVENT = 'mobile_users_floating_banner_shown';

	// TODO: add THRESHOLD AFTER RELEASE
	private const CALL_LIST_EVENT = 'mobile_menu_call_list_aha_moment';
	private const MAIL_LIST_EVENT = 'mobile_menu_mail_list_aha_moment';

	public function getMenuAhaMoment(MyCompanyProvider $section, bool $canInvite): ?array
	{
		$myCompanyAhaMoment = $this->getMyCompanyAhaMoment($section, $canInvite);
		if ($myCompanyAhaMoment !== null)
		{
			return $myCompanyAhaMoment;
		}

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

		return $this->getInvitationAhaMoment();
	}

	/**
	 * Unaccepted invitations of the portal. The moment carries no tourist event on purpose: it is a
	 * reminder and keeps coming back while the invitations are still unaccepted.
	 */
	private function getInvitationAhaMoment(): ?array
	{
		if (!Loader::includeModule('intranetmobile'))
		{
			return null;
		}

		$invitation = (new \Bitrix\IntranetMobile\Invitation\InvitationAhaMoment())
			->getUnacceptedInvitesAhaMoment()
		;
		if ($invitation === null)
		{
			return null;
		}

		return [
			'type' => 'invitation',
			'title' => $invitation->title,
			'description' => $invitation->description,
		];
	}

	private function getMyCompanyAhaMoment(MyCompanyProvider $section, bool $canInvite): ?array
	{
		if (!$section->isAvailable())
		{
			return null;
		}

		$events = $this->getTouristEvents();
		if (isset($events[self::MY_COMPANY_EVENT]))
		{
			return null;
		}

		$portalCreatedAt = $this->getPortalCreatedAt();
		if ($portalCreatedAt === null)
		{
			return null;
		}

		$isNewPortal = (new MyCompanyFeature())->isPortalCreatedAfterLaunch($portalCreatedAt);
		if ($isNewPortal === null)
		{
			return null;
		}

		if ($isNewPortal && !$this->isInviteAhaMomentAvailable($events, $canInvite))
		{
			return null;
		}

		return [
			'type' => $isNewPortal ? 'my_company_invite' : 'my_company_relocation',
			'title' => Loc::getMessage(
				$isNewPortal
					? 'MOBILE_MENU_AHA_MOMENT_MY_COMPANY_INVITE_TITLE'
					: 'MOBILE_MENU_AHA_MOMENT_MY_COMPANY_RELOCATION_TITLE',
			),
			'description' => Loc::getMessage('MOBILE_MENU_AHA_MOMENT_MY_COMPANY_MENU_DESCRIPTION'),
			'eventName' => self::MY_COMPANY_EVENT,
		];
	}

	private function isInviteAhaMomentAvailable(array $events, bool $canInvite): bool
	{
		return $canInvite
			&& (new EmployeeThreshold())->isReached()
			&& isset($events[self::USERS_FLOATING_BANNER_SHOWN_EVENT]);
	}

	protected function getPortalCreatedAt(): ?DateTime
	{
		$portalAgeChecker = new PortalAgeChecker();
		if (!$portalAgeChecker->isPortalDateKnown())
		{
			return null;
		}

		return $portalAgeChecker->getPortalCreatedAt();
	}

	protected function getTouristEvents(): array
	{
		return Tourist::getEvents();
	}

	public function getAvatarAhaMoment(int $userId): ?array
	{
		return $this->getRelocationAhaMoment($userId);
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
