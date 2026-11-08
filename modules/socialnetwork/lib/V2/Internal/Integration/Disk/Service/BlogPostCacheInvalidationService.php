<?php

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Disk\Service;

use Bitrix\Blog\CommentTable;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Uf\BlogPostCommentConnector;
use Bitrix\Disk\Uf\BlogPostConnector;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\ComponentHelper;

class BlogPostCacheInvalidationService
{
	public static function invalidateCacheByDiskFileId(int $diskFileId): void
	{
		if (
			$diskFileId <= 0
			|| !Loader::includeModule('disk')
			|| !Loader::includeModule('blog')
		)
		{
			return;
		}

		$attachedObjects = AttachedObject::getModelList([
			'filter' => [
				'=OBJECT_ID' => $diskFileId,
				'@ENTITY_TYPE' => [
					BlogPostConnector::class,
					BlogPostCommentConnector::class,
				],
				'=MODULE_ID' => 'blog',
			],
		]);

		$postIds = [];
		$commentIds = [];
		foreach ($attachedObjects as $attachedObject)
		{
			$entityType = $attachedObject->getEntityType();
			$entityId = (int)$attachedObject->getEntityId();
			if ($entityType === BlogPostConnector::class)
			{
				$postIds[$entityId] = true;
			}
			elseif ($entityType === BlogPostCommentConnector::class)
			{
				$commentIds[$entityId] = true;
			}
		}

		foreach (array_keys($postIds) as $postId)
		{
			self::invalidatePostCache($postId);
		}

		$commentsPostIds = self::getPostIdsByCommentIds(array_keys($commentIds));
		foreach ($commentsPostIds as $commentsPostId)
		{
			self::invalidatePostCommentsCache($commentsPostId);
		}
	}

	public static function invalidateCacheByAttachedObjectData(array $attachedObjectData): void
	{
		$attachedObjectType = $attachedObjectData['ENTITY_TYPE'] ?? null;
		$attachedObjectModuleId = $attachedObjectData['MODULE_ID'] ?? null;
		$attachedObjectId = (int)($attachedObjectData['ENTITY_ID'] ?? 0);

		if ($attachedObjectModuleId !== 'blog'
			|| $attachedObjectId === 0
			|| !Loader::includeModule('blog')
		)
		{
			return;
		}

		switch ($attachedObjectType)
		{
			case BlogPostConnector::class:
				self::invalidatePostCache($attachedObjectId);
				break;

			case BlogPostCommentConnector::class:
				$comment = CommentTable::getByPrimary(
					$attachedObjectId,
					[
						'select' => ['POST_ID'],
					],
				)->fetch();

				$commentPostId = (int)($comment['POST_ID'] ?? 0);
				if ($commentPostId > 0)
				{
					self::invalidatePostCommentsCache($commentPostId);
				}
				break;

			default:
				break;
		}
	}

	private static function getPostIdsByCommentIds(array $commentIds): array
	{
		if ($commentIds === [])
		{
			return [];
		}

		$commentRows = CommentTable::getList([
			'select' => ['POST_ID'],
			'filter' => ['@ID' => $commentIds],
		])->fetchAll();

		$commentsPostIds = [];
		foreach ($commentRows as $commentRow)
		{
			$commentsPostIds[(int)$commentRow['POST_ID']] = true;
		}

		return array_keys($commentsPostIds);
	}

	private static function invalidatePostCache(int $postId): void
	{
		BXClearCache(
			true,
			ComponentHelper::getBlogPostCacheDir([
				'TYPE' => 'post',
				'POST_ID' => $postId,
			]),
		);
	}

	private static function invalidatePostCommentsCache(int $postId): void
	{
		BXClearCache(
			true,
			ComponentHelper::getBlogPostCacheDir([
				'TYPE' => 'post_comments',
				'POST_ID' => $postId,
			]),
		);
	}
}
