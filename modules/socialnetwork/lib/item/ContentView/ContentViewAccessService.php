<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Item\ContentView;

use Bitrix\Forum\MessageTable;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Livefeed;
use Bitrix\Socialnetwork\LogTable;
use Closure;
use Exception;
use LogicException;

class ContentViewAccessService
{
	private const LIVEFEED_PROVIDER_CLASSES = [
		Livefeed\Provider::DATA_ENTITY_TYPE_FORUM_TOPIC => Livefeed\ForumTopic::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_FORUM_POST => Livefeed\ForumPost::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_LOG_ENTRY => Livefeed\LogEvent::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_LOG_COMMENT => Livefeed\LogComment::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_PHOTOGALLERY_ALBUM => Livefeed\PhotogalleryAlbum::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_PHOTOGALLERY_PHOTO => Livefeed\PhotogalleryPhoto::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_LISTS_ITEM => Livefeed\ListsItem::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_WIKI => Livefeed\Wiki::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_TIMEMAN_ENTRY => Livefeed\TimemanEntry::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_TIMEMAN_REPORT => Livefeed\TimemanReport::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_INTRANET_NEW_USER => Livefeed\IntranetNewUser::class,
		Livefeed\Provider::DATA_ENTITY_TYPE_BITRIX24_NEW_USER => Livefeed\Bitrix24NewUser::class,
	];

	/** @var Closure(string): bool */
	private readonly Closure $moduleLoader;

	public function __construct(?callable $moduleLoader = null)
	{
		$this->moduleLoader = $moduleLoader !== null
			? Closure::fromCallable($moduleLoader)
			: static fn(string $moduleId): bool => Loader::includeModule($moduleId)
		;
	}

	public function canRead(string $contentId, int $actorId): bool
	{
		if (
			$actorId <= 0
			|| preg_match('/\A([A-Z][A-Z0-9_]*)-([1-9][0-9]*)\z/', $contentId, $matches) !== 1
		)
		{
			return false;
		}

		$entityId = filter_var(
			$matches[2],
			FILTER_VALIDATE_INT,
			['options' => ['min_range' => 1]],
		);
		if ($entityId === false)
		{
			return false;
		}

		return match ($matches[1])
		{
			Livefeed\Provider::DATA_ENTITY_TYPE_TASKS_TASK => $this->canReadTask($entityId, $actorId),
			Livefeed\Provider::DATA_ENTITY_TYPE_CALENDAR_EVENT => $this->canReadCalendarEvent($entityId, $actorId),
			Livefeed\Provider::DATA_ENTITY_TYPE_BLOG_POST => $this->canReadBlogPost($entityId, $actorId),
			Livefeed\Provider::DATA_ENTITY_TYPE_BLOG_COMMENT => $this->canReadBlogComment($entityId, $actorId),
			default => $this->canReadLivefeed($matches[1], $entityId, $actorId),
		};
	}

	private function canReadTask(int $taskId, int $actorId): bool
	{
		if (!$this->includeModule('tasks'))
		{
			return false;
		}
		if (
			\Bitrix\Tasks\Internals\TaskTable::query()
				->setSelect(['ID'])
				->where('ID', $taskId)
				->setLimit(1)
				->fetch()
			=== false
		)
		{
			return false;
		}

		return \Bitrix\Tasks\Access\TaskAccessController::can(
			$actorId,
			\Bitrix\Tasks\Access\ActionDictionary::ACTION_TASK_READ,
			$taskId,
		);
	}

	private function canReadCalendarEvent(int $eventId, int $actorId): bool
	{
		if (!$this->includeModule('calendar'))
		{
			return false;
		}
		if (
			\Bitrix\Calendar\Internals\EventTable::query()
				->setSelect(['ID'])
				->where('ID', $eventId)
				->where('DELETED', 'N')
				->setLimit(1)
				->fetch()
			=== false
		)
		{
			return false;
		}

		return \Bitrix\Calendar\Access\EventAccessController::can(
			$actorId,
			\Bitrix\Calendar\Access\ActionDictionary::ACTION_EVENT_VIEW_FULL,
			$eventId,
		);
	}

	private function canReadBlogPost(int $postId, int $actorId): bool
	{
		if (!$this->includeModule('blog') || !$this->isCurrentActor($actorId))
		{
			return false;
		}

		return Livefeed\BlogPost::canRead($postId);
	}

	private function canReadBlogComment(int $commentId, int $actorId): bool
	{
		if (!$this->includeModule('blog') || !$this->isCurrentActor($actorId))
		{
			return false;
		}

		$provider = new Livefeed\BlogComment();
		$provider->setEntityId($commentId);
		$provider->initSourceFields();

		return $provider->getSourceFields() !== [];
	}

	private function canReadLivefeed(string $entityType, int $entityId, int $actorId): bool
	{
		$expectedProviderClass = self::LIVEFEED_PROVIDER_CLASSES[$entityType] ?? null;
		if ($expectedProviderClass === null)
		{
			return false;
		}

		try
		{
			$providerEntityId = $this->resolveProviderEntityId($entityType, $entityId);
			if ($providerEntityId === null)
			{
				return false;
			}

			/** @var Livefeed\Provider $provider */
			$provider = new $expectedProviderClass();
			$provider->setEntityId($providerEntityId);
			$provider->setSiteId(SITE_ID);
			if (
				$entityType === Livefeed\Provider::DATA_ENTITY_TYPE_FORUM_TOPIC
				&& (int)$provider->getEntityId() !== $entityId
			)
			{
				return false;
			}

			$provider->initSourceFields();
			if ($provider->getSourceFields() === [])
			{
				return false;
			}

			$logId = (int)$provider->getLogId();
			$logEntry = LogTable::query()
				->setSelect(['ID', 'INACTIVE'])
				->where('ID', $logId)
				->setLimit(1)
				->fetch()
			;
			if ($logEntry === false || $logEntry['INACTIVE'] === 'Y')
			{
				return false;
			}
		}
		catch (LogicException $exception)
		{
			throw $exception;
		}
		catch (Exception)
		{
			return false;
		}

		return \CSocNetLogRights::CheckForUser($logId, $actorId);
	}

	private function resolveProviderEntityId(string $entityType, int $entityId): ?int
	{
		if ($entityType !== Livefeed\Provider::DATA_ENTITY_TYPE_FORUM_TOPIC)
		{
			return $entityId;
		}
		if (!$this->includeModule('forum'))
		{
			return null;
		}

		$message = MessageTable::query()
			->setSelect(['TOPIC_ID'])
			->where('ID', $entityId)
			->setLimit(1)
			->fetch()
		;
		$topicId = (int)($message['TOPIC_ID'] ?? 0);

		return $topicId > 0 ? $topicId : null;
	}

	private function includeModule(string $moduleId): bool
	{
		return ($this->moduleLoader)($moduleId);
	}

	private function isCurrentActor(int $actorId): bool
	{
		global $USER;

		return is_object($USER) && (int)$USER->getId() === $actorId;
	}
}
