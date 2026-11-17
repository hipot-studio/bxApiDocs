<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

class Activity
{
	public static function getShareableActivityUrl(int $activityId, int $chatId): ?string
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		return Container::getInstance()
			->getRouter()
			->getActivityDetailsShareableUrl($activityId, $chatId)
			?->getUri()
		;
	}

	public static function getActivity(int $activityId): ?array
	{
		if ($activityId <= 0 || !Loader::includeModule('crm'))
		{
			return null;
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);

		return is_array($activity) ? $activity : null;
	}

	public static function getDetailsUrl(int $activityId, int $userId): ?string
	{
		if ($activityId <= 0 || $userId <= 0 || !Loader::includeModule('crm'))
		{
			return null;
		}

		// Both details-url methods arrived with the crm release of the task email source: on an older crm
		// the caller gets no link (the source is then treated as unreadable) instead of a fatal error.
		$router = Container::getInstance()->getRouter();
		if (!method_exists($router, 'getActivityDetailsUrl'))
		{
			return null;
		}

		return $router->getActivityDetailsUrl($activityId, $userId)?->getUri();
	}

	public static function getTaskScopedDetailsUrl(int $activityId, int $taskId): ?string
	{
		if ($activityId <= 0 || $taskId <= 0 || !Loader::includeModule('crm'))
		{
			return null;
		}

		$router = Container::getInstance()->getRouter();
		if (!method_exists($router, 'getActivityDetailsUrlWithTaskAccess'))
		{
			return null;
		}

		return $router->getActivityDetailsUrlWithTaskAccess($activityId, $taskId)?->getUri();
	}

	public static function isEmailActivity(?array $activity): bool
	{
		if ($activity === null || !Loader::includeModule('crm'))
		{
			return false;
		}

		return ($activity['PROVIDER_ID'] ?? null) === Email::getId();
	}

	public static function getBodyHtml(array $activity): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		Email::uncompressActivityDescription($activity);

		return Email::getDescriptionHtmlByActivityFields($activity);
	}

	public static function resolveMailMessageId(int $activityId): int
	{
		if ($activityId <= 0 || !Loader::includeModule('crm'))
		{
			return 0;
		}

		// Read UF_MAIL_MESSAGE explicitly - the activity broker does not select user fields.
		$row = \CCrmActivity::GetList(
			[],
			['ID' => $activityId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'UF_MAIL_MESSAGE'],
			['QUERY_OPTIONS' => ['LIMIT' => 1]],
		)->Fetch();

		return is_array($row) ? (int)($row['UF_MAIL_MESSAGE'] ?? 0) : 0;
	}
}

