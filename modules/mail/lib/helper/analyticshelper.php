<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Main\Web\Uri;
use Bitrix\Mail\Internals\MessageAccessTable;

class AnalyticsHelper
{
	public const SOURCE_TYPE_CHAT = 'chat';
	public const SOURCE_TYPE_EVENT = 'event';
	public const SOURCE_TYPE_TASKS = 'tasks';
	public const SOURCE_TYPE_POST = 'post';
	public const SOURCE_TYPE_MAIL = 'mail';
	public const SOURCE_TYPE_NOTIFICATION = 'notification';

	public const ENTITY_TYPE_IM_CHAT = MessageAccessTable::ENTITY_TYPE_IM_CHAT;
	public const ENTITY_TYPE_CALENDAR_EVENT = MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT;
	public const ENTITY_TYPE_TASKS_TASK = MessageAccessTable::ENTITY_TYPE_TASKS_TASK;
	public const ENTITY_TYPE_BLOG_POST = MessageAccessTable::ENTITY_TYPE_BLOG_POST;
	public const ENTITY_TYPE_NOTIFICATION = 'notification';
	public const ENTITY_TYPE_MAIL = 'mail';

	public static function addSourceAnalyticsToMessage(string $messageHref, string $entityType): string
	{
		$source = self::getAnalyticsSourceByType($entityType);

		if ($source)
		{
			return self::addAnalyticsToMessage($messageHref, ['source' => $source]);
		}

		return $messageHref;
	}

	public static function addAnalyticsToMessage(string $messageHref, array $analyticsData): string
	{
		$uri = new Uri($messageHref);
		$uri->addParams($analyticsData);

		return $uri->getUri();
	}

	public static function getAnalyticsSourceByType(string $entityType): ?string
	{
		return match ($entityType)
		{
			self::ENTITY_TYPE_TASKS_TASK => self::SOURCE_TYPE_TASKS,
			self::ENTITY_TYPE_BLOG_POST => self::SOURCE_TYPE_POST,
			self::ENTITY_TYPE_IM_CHAT => self::SOURCE_TYPE_CHAT,
			self::ENTITY_TYPE_CALENDAR_EVENT => self::SOURCE_TYPE_EVENT,
			self::ENTITY_TYPE_NOTIFICATION => self::SOURCE_TYPE_NOTIFICATION,
			self::ENTITY_TYPE_MAIL => self::SOURCE_TYPE_MAIL,
			default => null,
		};
	}

	public static function getValidatedSource(string $context, ?string $source): ?string
	{
		if (empty($source))
		{
			return null;
		}

		$allowedSources = match ($context)
		{
			'home' => [
				'left_menu',
				'horizontal_menu',
				self::SOURCE_TYPE_NOTIFICATION,
			],
			'msg_view', 'msg_new' => [
				self::SOURCE_TYPE_MAIL,
				self::SOURCE_TYPE_TASKS,
				self::SOURCE_TYPE_CHAT,
				self::SOURCE_TYPE_EVENT,
				self::SOURCE_TYPE_POST,
				self::SOURCE_TYPE_NOTIFICATION,
			],
			default => [],
		};

		$mappedSource = self::getAnalyticsSourceByType($source) ?? $source;

		return in_array($mappedSource, $allowedSources, true) ? $mappedSource : null;
	}

	public static function getAvailableDirsForAnalytics(): array
	{
		return [
			'inbox',
			'sent',
			'drafts',
			'spam',
			'trash',
		];
	}
}

