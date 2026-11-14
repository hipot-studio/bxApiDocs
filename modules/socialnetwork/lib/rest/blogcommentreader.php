<?php

namespace Bitrix\Socialnetwork\Rest;

use Bitrix\Blog\CommentTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\Livefeed\BlogPost;

final class BlogCommentReader
{
	public const DEFAULT_LIMIT = 100;
	public const MAX_LIMIT = 1000;

	private \Closure $postLoader;
	private \Closure $postAccessChecker;
	private \Closure $commentLoader;
	private \Closure $attachedObjectLoader;
	private \Closure $fileLoader;

	public function __construct(
		?callable $postLoader = null,
		?callable $postAccessChecker = null,
		?callable $commentLoader = null,
		?callable $attachedObjectLoader = null,
		?callable $fileLoader = null,
	)
	{
		$this->postLoader = $postLoader !== null
			? \Closure::fromCallable($postLoader)
			: static fn(int $postId): ?array => \CBlogPost::getById($postId) ?: null;
		$this->postAccessChecker = $postAccessChecker !== null
			? \Closure::fromCallable($postAccessChecker)
			: static fn(array $post): bool => BlogPost::canRead(['POST' => $post]);
		$this->commentLoader = $commentLoader !== null
			? \Closure::fromCallable($commentLoader)
			: static function(array $query): array
			{
				$result = [];
				$comments = CommentTable::getList($query);
				while ($comment = $comments->fetch())
				{
					$result[] = $comment;
				}

				return $result;
			};
		$this->attachedObjectLoader = $attachedObjectLoader !== null
			? \Closure::fromCallable($attachedObjectLoader)
			: static function(array $attachedIds): array
			{
				return \Bitrix\Disk\AttachedObject::getList([
					'filter' => ['@ID' => $attachedIds],
					'select' => ['ID', 'OBJECT_ID'],
				])->fetchAll();
			};
		$this->fileLoader = $fileLoader !== null
			? \Closure::fromCallable($fileLoader)
			: static fn(array $diskObjectIds): array => \Bitrix\Disk\File::getModelList([
				'filter' => ['@ID' => $diskObjectIds],
				'with' => ['CREATE_USER', 'PREVIEW'],
			]);
	}

	public function read(array $params): array
	{
		if (!Loader::includeModule('blog'))
		{
			throw new SystemException('Blog module is not available');
		}

		$params = array_change_key_case($params, CASE_UPPER);
		$postId = (int)($params['POST_ID'] ?? 0);
		if ($postId <= 0)
		{
			throw new \InvalidArgumentException('POST_ID must be a positive integer');
		}

		if (isset($params['FIRST_ID'], $params['LAST_ID']))
		{
			throw new \InvalidArgumentException('FIRST_ID and LAST_ID cannot be used together');
		}

		$post = ($this->postLoader)($postId);
		if (!is_array($post) || !($this->postAccessChecker)($post))
		{
			throw new ObjectNotFoundException('Post not found');
		}

		$filter = [
			'=POST_ID' => $postId,
			'=PUBLISH_STATUS' => BLOG_PUBLISH_STATUS_PUBLISH,
		];
		$order = ['ID' => 'DESC'];

		if (isset($params['FIRST_ID']))
		{
			$firstId = (int)$params['FIRST_ID'];
			if ($firstId > 0)
			{
				$filter['>ID'] = $firstId;
			}
			$order = ['ID' => 'ASC'];
		}
		elseif ((int)($params['LAST_ID'] ?? 0) > 0)
		{
			$filter['<ID'] = (int)$params['LAST_ID'];
		}

		$limit = (int)($params['LIMIT'] ?? self::DEFAULT_LIMIT);
		if ($limit <= 0)
		{
			$limit = self::DEFAULT_LIMIT;
		}
		$limit = min($limit, self::MAX_LIMIT);

		return ($this->commentLoader)([
			'filter' => $filter,
			'select' => [
				'ID',
				'POST_ID',
				'PARENT_ID',
				'AUTHOR_ID',
				'DATE_CREATE',
				'POST_TEXT',
				'SHARE_DEST',
				'UF_BLOG_COMMENT_FILE',
			],
			'order' => $order,
			'limit' => $limit,
		]);
	}

	public function readAttachedFiles(array $attachedIds): array
	{
		$attachedIds = array_values(array_unique(array_filter(array_map('intval', $attachedIds))));
		if (empty($attachedIds))
		{
			return [
				'ATTACHED_OBJECTS' => [],
				'FILES' => [],
			];
		}

		$attachedObjectList = [];
		$diskObjectIds = [];
		foreach (($this->attachedObjectLoader)($attachedIds) as $attachedObjectFields)
		{
			$attachedId = (int)($attachedObjectFields['ID'] ?? 0);
			$diskObjectId = (int)($attachedObjectFields['OBJECT_ID'] ?? 0);
			if ($attachedId <= 0 || $diskObjectId <= 0)
			{
				continue;
			}

			$attachedObjectList[$attachedId] = $diskObjectId;
			$diskObjectIds[$diskObjectId] = $diskObjectId;
		}

		$fileList = [];
		if (!empty($diskObjectIds))
		{
			foreach (($this->fileLoader)(array_values($diskObjectIds)) as $fileModel)
			{
				$fileList[(int)$fileModel->getId()] = $fileModel;
			}
		}

		$attachedObjectList = array_filter(
			$attachedObjectList,
			static fn(int $diskObjectId): bool => isset($fileList[$diskObjectId]),
		);

		return [
			'ATTACHED_OBJECTS' => $attachedObjectList,
			'FILES' => $fileList,
		];
	}
}
