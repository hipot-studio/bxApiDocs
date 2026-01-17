<?php

namespace Bitrix\IntranetMobile\Invitation;

use Bitrix\Bitrix24\Entity\Invitation\PushNotification;
use Bitrix\Bitrix24\Strategy\InvitationPushNotification\UnacceptedInvitationsStrategy;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Bitrix24\Service\InvitationPushNotificationService;
use Bitrix\Mobile\Tourist;

class InvitationAhaMoment
{
	private const CACHE_TTL = 3600; // 1 hour
	private const CACHE_DIR = '/bx/mobile_invitation_aha_moment';
	public const FIRST_INVITE_TOURIST_EVENT = 'mobile_invitation_first_aha_moment';

	public function getUnacceptedInvitesAhaMoment(): ?PushNotification
	{
		if (!Loader::includeModule('bitrix24') || !Loader::includeModule('intranet'))
		{
			return null;
		}

		$userId = $this->getB24UserId();
		if ($userId === null)
		{
			return null;
		}

		$events = Tourist::getEvents();
		if (!array_key_exists(self::FIRST_INVITE_TOURIST_EVENT, $events))
		{
			return null;
		}

		$cacheId = 'mobile_invitation_aha_' . $userId . '_' . SITE_ID . '_' . LANGUAGE_ID;
		$cacheDir = self::CACHE_DIR . '/' . $userId;

		$cache = Cache::createInstance();
		if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$value = $cache->getVars();
			if (!is_a($value, \Bitrix\Bitrix24\Entity\Invitation\PushNotification::class))
			{
				$value = null;
			}

			return $value;
		}

		$result = null;
		try
		{
			$cache->startDataCache();
			if (self::hasUnacceptedInvitation())
			{
				$result = $this->buildNotification($userId);
			}
			$cache->endDataCache($result ?? new \stdClass());
		}
		catch (\Throwable)
		{
			$cache->abortDataCache();

			return null;
		}

		return $result;
	}

	private static function hasUnacceptedInvitation(): bool
	{
		try
		{
			return (new InvitationPushNotificationService())->hasUnacceptedInvitation();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getFirstInviteAhaMoment(): ?PushNotification
	{
		$events = Tourist::getEvents();
		if (array_key_exists(self::FIRST_INVITE_TOURIST_EVENT, $events))
		{
			return null;
		}

		if (!Loader::includeModule('bitrix24') || !Loader::includeModule('intranet'))
		{
			return null;
		}

		$userId = $this->getB24UserId();
		if ($userId === null)
		{
			return null;
		}

		try
		{
			if (self::hasUnacceptedInvitation())
			{
				return $this->buildNotification($userId);
			}
		}
		catch (\Throwable)
		{
			return null;
		}

		return null;
	}

	private function getB24UserId(): ?int
	{
		$userId = \Bitrix\Bitrix24\CurrentUser::get()?->getId();
		$userId = (int)$userId;

		return $userId > 0 ? $userId : null;
	}

	private function buildNotification(int $userId): ?PushNotification
	{
		try
		{
			return (new UnacceptedInvitationsStrategy($userId))->build();
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
