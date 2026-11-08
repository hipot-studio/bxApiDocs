<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;

final class AttachmentService
{
	public const AGENT_NAME = 'Bitrix\\Disk\\Document\\Flipchart\\Comment\\AttachmentService::cleanupExpiredAgent();';

	/** A file that never reached a comment lives a day; the value is a decision of this task. */
	private const TEMPORARY_TTL = 86400;

	private const SAVE_DIRECTORY = 'disk/flipchart/comments';
	private const API_PATH = '/api/disk/flipchart/comments/v1/';
	private const ATTACHMENT_PATH_PREFIX = 'attachments/';
	private const ATTACHMENT_PATH_PATTERN = '#^' . self::ATTACHMENT_PATH_PREFIX . '[0-9a-f]{32}$#D';
	private const NAME_BYTES = 16;

	private const QUERY_CHUNK_SIZE = 500;
	private const CLEANUP_MAX_CHUNKS = 10;

	private const ERROR_FORBIDDEN = 'forbidden';
	private const ERROR_NOT_FOUND = 'attachment_not_found';
	private const ERROR_INTERNAL = 'internal_error';

	private const CONTENT_TYPES = [
		'bmp' => 'image/bmp',
		'gif' => 'image/gif',
		'heic' => 'image/heic',
		'heif' => 'image/heif',
		'jpeg' => 'image/jpeg',
		'jpg' => 'image/jpeg',
		'png' => 'image/png',
		'svg' => 'image/svg+xml',
		'webp' => 'image/webp',
	];

	public function __construct(
		private readonly AttachmentValidator $validator = new AttachmentValidator(),
	)
	{
	}

	/**
	 * Stores one uploaded image of the board of the token and answers with the path a comment may
	 * name and the address it is shown by.
	 *
	 * @param array{tmpName: string, name: string, size: int} $input
	 */
	public function upload(AccessContext $context, array $input): Result
	{
		if (!$context->canWrite())
		{
			return self::fail('Board access is not sufficient to upload an attachment.', self::ERROR_FORBIDDEN, 403);
		}

		$checked = $this->validator->validate($input['tmpName'], $input['name']);
		if (!$checked->isSuccess())
		{
			return $checked;
		}

		$fileId = null;
		try
		{
			$attachmentId = bin2hex(random_bytes(self::NAME_BYTES));
			$path = self::ATTACHMENT_PATH_PREFIX . $attachmentId;
			$fileId = $this->store($input['tmpName'], $checked->getData());
			$stored = CommentAttachmentTable::add([
				'OBJECT_ID' => $context->getObjectId(),
				'FILE_ID' => $fileId,
				'PATH' => $path,
				'OWNER_ID' => $context->getUserId(),
				'COMMENT_ROW_ID' => null,
				'CREATED_AT' => new DateTime(),
			]);
			if (!$stored->isSuccess())
			{
				throw new SystemException('Could not record an uploaded attachment.');
			}
		}
		catch (\Throwable $exception)
		{
			if ($fileId !== null)
			{
				self::removeFile($fileId);
			}

			self::logException($exception);

			return self::fail('Internal attachment storage error.', self::ERROR_INTERNAL, 500);
		}

		return self::succeed([
			'path' => $path,
			'url' => self::publicUrl($path),
		]);
	}

	public function getFile(string $attachmentId): Result
	{
		$row = CommentAttachmentTable::query()
			->setSelect(['FILE_ID'])
			->where('PATH', self::ATTACHMENT_PATH_PREFIX . $attachmentId)
			->fetch()
		;
		if (!is_array($row))
		{
			return self::fail('Attachment was not found.', self::ERROR_NOT_FOUND, 404);
		}

		$fileId = (int)$row['FILE_ID'];
		if ($fileId <= 0 || !is_array(\CFile::GetFileArray($fileId)))
		{
			return self::fail('Attachment was not found.', self::ERROR_NOT_FOUND, 404);
		}

		$result = new Result();
		$result->setData(['fileId' => $fileId]);

		return $result;
	}

	/**
	 * @param array{extension: string, content: ?string} $checked
	 * @return int The identifier of the stored b_file row.
	 */
	private function store(string $tmpName, array $checked): int
	{
		$extension = $checked['extension'];
		$file = [
			'name' => bin2hex(random_bytes(self::NAME_BYTES)) . '.' . $extension,
			'type' => self::CONTENT_TYPES[$extension],
			'tmp_name' => $tmpName,
			'MODULE_ID' => 'disk',
		];
		if (is_string($checked['content']))
		{
			$file['content'] = $checked['content'];
			$file['size'] = strlen($checked['content']);
		}

		$fileId = \CFile::SaveFile($file, self::SAVE_DIRECTORY, true);
		if (!is_numeric($fileId) || (int)$fileId <= 0)
		{
			throw new SystemException('Uploaded attachment could not be saved.');
		}

		return (int)$fileId;
	}

	/**
	 * Drops the uploads of a board that no comment ever named, a day after they were made. The run is
	 * repeatable: a CFile that could not be removed keeps its row for the next one, and metadata is
	 * dropped only once its b_file row is gone. A bound file is never looked at.
	 *
	 * @return string The name of the agent, so that it stays registered.
	 */
	public static function cleanupExpiredAgent(): string
	{
		try
		{
			(new self())->cleanupExpired();
		}
		catch (\Throwable $exception)
		{
			self::logException($exception);
		}

		return self::AGENT_NAME;
	}

	private function cleanupExpired(): void
	{
		$expiredBefore = (new DateTime())->add('-' . self::TEMPORARY_TTL . ' seconds');

		for ($chunk = 0; $chunk < self::CLEANUP_MAX_CHUNKS; $chunk++)
		{
			$rows = CommentAttachmentTable::query()
				->setSelect(['ID', 'FILE_ID'])
				->where('COMMENT_ROW_ID', null)
				->where('CREATED_AT', '<', $expiredBefore)
				->setLimit(self::QUERY_CHUNK_SIZE)
				->fetchAll()
			;
			if ($rows === [])
			{
				return;
			}

			$removedIds = [];
			foreach ($rows as $row)
			{
				if (self::removeFile((int)$row['FILE_ID']))
				{
					$removedIds[] = (int)$row['ID'];
				}
			}
			if ($removedIds !== [])
			{
				CommentAttachmentTable::deleteByFilter(['=ID' => $removedIds]);
			}

			if (count($rows) < self::QUERY_CHUNK_SIZE || $removedIds === [])
			{
				return;
			}
		}
	}

	/**
	 * Drops every attachment row of a board, bound or not, once the board itself is finally deleted.
	 * The caller already holds {@see BoardLock}. A b_file row a CFile duplicate elsewhere keeps alive
	 * is not this module's concern any more: this row was its only route, so the row goes regardless
	 * of what became of the shared physical file.
	 */
	public static function deleteBoardAttachments(int $objectId): void
	{
		do
		{
			$rows = CommentAttachmentTable::query()
				->setSelect(['ID', 'FILE_ID'])
				->where('OBJECT_ID', $objectId)
				->setLimit(self::QUERY_CHUNK_SIZE)
				->fetchAll()
			;
			if ($rows === [])
			{
				return;
			}

			$ids = [];
			foreach ($rows as $row)
			{
				self::detachFile((int)$row['FILE_ID']);
				$ids[] = (int)$row['ID'];
			}
			CommentAttachmentTable::deleteByFilter(['=ID' => $ids]);

			if (count($rows) < self::QUERY_CHUNK_SIZE)
			{
				return;
			}
		}
		while (true);
	}

	/**
	 * @return bool Whether the bytes are gone: a file that was never there counts as removed.
	 */
	private static function removeFile(int $fileId): bool
	{
		try
		{
			if ($fileId <= 0 || !FileTable::getById($fileId)->fetch())
			{
				return true;
			}

			\CFile::Delete($fileId);

			if (FileTable::getById($fileId)->fetch())
			{
				self::logException(new SystemException('Comment attachment file could not be deleted: ' . $fileId));

				return false;
			}

			return true;
		}
		catch (\Throwable $exception)
		{
			self::logException($exception);

			return false;
		}
	}

	/**
	 * Best-effort physical cleanup for {@see deleteBoardAttachments()}: a CFile duplicate still alive
	 * on another board's attachment keeps the b_file row and bytes, and that is expected, not an
	 * error worth logging.
	 */
	private static function detachFile(int $fileId): void
	{
		try
		{
			if ($fileId > 0 && FileTable::getById($fileId)->fetch())
			{
				\CFile::Delete($fileId);
			}
		}
		catch (\Throwable $exception)
		{
			self::logException($exception);
		}
	}

	public static function publicUrl(string $path): string
	{
		return UrlManager::getInstance()->getHostUrl() . self::API_PATH . $path;
	}

	/**
	 * The upload answers with a path and with the address that path is shown by, and the board names
	 * back whichever of the two it kept. An address of this very route means the path inside it, so
	 * both forms reach the same upload; anything else is no address of this API and is left to be
	 * proved as the path it claims to be.
	 *
	 * The address is only read, never followed, and the host it names decides nothing: the path it
	 * carries goes through the same proof of board, owner and binding as a path sent on its own.
	 *
	 * @return string|null The path the address names, `null` when it names no attachment of this API.
	 */
	public static function pathFromPublicUrl(string $value): ?string
	{
		$uri = new Uri($value);
		if (
			!in_array($uri->getScheme(), ['http', 'https'], true)
			|| $uri->getHost() === ''
			|| $uri->getUser() !== ''
			|| $uri->getPass() !== ''
			|| $uri->getQuery() !== ''
			|| $uri->getFragment() !== ''
		)
		{
			return null;
		}

		$path = $uri->getPath();
		if (!str_starts_with($path, self::API_PATH))
		{
			return null;
		}

		$attachment = substr($path, strlen(self::API_PATH));

		return preg_match(self::ATTACHMENT_PATH_PATTERN, $attachment) === 1 ? $attachment : null;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private static function succeed(array $response): Result
	{
		$result = new Result();
		$result->setData([
			'response' => $response,
			'status' => 200,
		]);

		return $result;
	}

	private static function fail(string $message, string $code, int $status): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code, ['status' => $status]));

		return $result;
	}

	private static function logException(\Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (\Throwable)
		{
		}
	}
}
