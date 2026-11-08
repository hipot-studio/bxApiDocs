<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Syntax of one operation of the comments API exactly as the contract declares it.
 *
 * Unlike annotations, a field the contract does not name is ignored instead of refused, and the
 * opaque identifiers of the contract (`documentId`, `authorId`, `userId`) carry no upper length
 * limit and are neither trimmed nor normalized: the board owns their shape, this API only stores
 * them.
 *
 * Refusals follow the operation matrix: the body of an operation that declares `400` fails with
 * `invalid_argument`, while an unusable `threadId` of a path is "no such thread" and fails with
 * `thread_not_found`. The operations of a single comment declare only `comment_not_found`, so every
 * unusable segment of their path fails with that code. The state transitions of a thread declare no
 * `400` at all: an initiator that cannot be read from their `userId` is refused as `forbidden`.
 */
final class RequestValidator
{
	public const OPERATION_SET_IMPORTANT = 'setThreadImportant';
	public const OPERATION_SET_PINNED = 'setThreadPinned';
	public const FIELD_VALUE = 'value';

	public const OPERATION_ADD_THREAD = 'addThread';
	public const OPERATION_GET_THREADS = 'getThreadsByFilter';
	public const OPERATION_GET_THREAD_META = 'getThreadMeta';
	public const OPERATION_UPDATE_THREAD_COORDINATES = 'updateThreadCoordinates';
	public const OPERATION_DELETE_THREAD = 'deleteThread';
	public const OPERATION_RESTORE_THREAD = 'restoreThread';
	public const OPERATION_RESOLVE_THREAD = 'resolveThread';
	public const OPERATION_UNRESOLVE_THREAD = 'unresolveThread';
	public const OPERATION_ADD_COMMENT = 'addComment';
	public const OPERATION_GET_THREAD_COMMENTS = 'getThreadComments';
	public const OPERATION_COUNT_COMMENTS = 'countCommentsInThread';
	public const OPERATION_GET_MAX_COMMENT_ID = 'getMaxCommentId';
	public const OPERATION_GET_COMMENT = 'getComment';
	public const OPERATION_UPDATE_COMMENT = 'updateComment';
	public const OPERATION_DELETE_COMMENT = 'deleteComment';
	public const OPERATION_RECOVER_COMMENT = 'recoverComment';
	public const OPERATION_ADD_REACTION = 'addReaction';
	public const OPERATION_REMOVE_REACTION = 'removeReaction';
	public const OPERATION_MARK_READ = 'markCommentsAsRead';
	public const OPERATION_MARK_UNREAD = 'markThreadAsUnread';
	public const OPERATION_MARK_ALL_READ = 'markAllThreadsAsRead';
	public const OPERATION_IS_READ_ALL = 'isFlipReadAll';
	public const OPERATION_GET_READ_STATUSES = 'getReadStatuses';
	public const OPERATION_GET_MENTIONS = 'getMentions';
	public const OPERATION_UPLOAD_ATTACHMENT = 'uploadCommentAttachment';
	public const OPERATION_GET_ATTACHMENT = 'getCommentAttachment';

	public const FIELD_DOCUMENT_ID = 'documentId';
	public const FIELD_AUTHOR_ID = 'authorId';
	public const FIELD_USER_ID = 'userId';
	public const FIELD_THREAD_ID = 'threadId';
	public const FIELD_COMMENT_ID = 'commentId';
	public const FIELD_SHOW_RESOLVED = 'showResolved';
	public const FIELD_SHOW_ONLY_OWNED = 'showOnlyOwned';
	public const FIELD_SHOW_WHERE_USER_MENTIONED = 'showWhereUserMentioned';
	public const FIELD_SHOW_ONLY_IMPORTANT = 'showOnlyImportant';
	public const FIELD_SHOW_ONLY_PINNED = 'showOnlyPinned';
	public const FIELD_SORTING = 'sorting';
	public const FIELD_COORDINATES = 'coordinates';
	public const FIELD_X = 'x';
	public const FIELD_Y = 'y';
	public const FIELD_PARENT_ID = 'parentId';
	public const FIELD_WIDTH = 'width';
	public const FIELD_HEIGHT = 'height';
	public const FIELD_CONTENT = 'content';
	public const FIELD_MENTIONS = 'mentions';
	public const FIELD_ATTACHED = 'attached';
	public const FIELD_UPLOAD_FILE = 'uploadFile';
	public const FIELD_ATTACHMENT_ID = 'attachmentId';
	public const FIELD_EMOJI = 'emoji';
	public const FIELD_IS_ANONYMOUS = 'isAnonymous';
	public const FIELD_LAST_READ_COMMENT_ID = 'lastReadCommentId';
	public const FIELD_READ_FULL_THREAD = 'readFullThread';
	public const FIELD_THREAD_IDS = 'threadIds';
	public const FIELD_ONLY_UNREAD = 'onlyUnread';

	public const ATTACHED_MAX_ITEMS = 5;
	public const EMOJI_MIN_LENGTH = 1;
	public const EMOJI_MAX_LENGTH = 32;
	public const UPLOAD_MAX_FILE_SIZE = 15 * 1024 * 1024;

	public const SORTING_DATE_DESC = 'date_desc';
	public const SORTING_DATE_ASC = 'date_asc';
	public const SORTING_UNREAD = 'unread';

	private const BOOLEAN_FILTER_FIELDS = [
		self::FIELD_SHOW_RESOLVED,
		self::FIELD_SHOW_ONLY_OWNED,
		self::FIELD_SHOW_WHERE_USER_MENTIONED,
		self::FIELD_SHOW_ONLY_IMPORTANT,
		self::FIELD_SHOW_ONLY_PINNED,
	];

	private const SORTING_VALUES = [
		self::SORTING_DATE_DESC,
		self::SORTING_DATE_ASC,
		self::SORTING_UNREAD,
	];

	private const ERROR_INVALID_ARGUMENT = 'invalid_argument';
	private const ERROR_THREAD_NOT_FOUND = 'thread_not_found';
	private const ERROR_COMMENT_NOT_FOUND = 'comment_not_found';
	private const ERROR_FORBIDDEN = 'forbidden';
	private const ERROR_FILE_TOO_LARGE = 'file_size_too_large';

	private const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

	private const COMMENT_ID_PATTERN = '/^[1-9][0-9]{0,9}$/D';
	private const COMMENT_ID_MAX = 2147483647;

	/**
	 * The contract fixes the shape of a UUID but not the case of its hexadecimal digits, so input in
	 * any case is accepted; the portal issues thread identifiers in the lower case of
	 * {@see \Bitrix\Main\UuidGenerator::generateV4()} and the lookup is done in that form.
	 */
	private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
	private const ATTACHMENT_ID_PATTERN = '/^[0-9a-f]{32}$/D';

	/**
	 * @param string $operation One of the OPERATION_* constants.
	 * @param string|null $threadId The path segment of the addressed operations, untouched. The list
	 *     operation has no path segment and reads its `threadId` from the query itself.
	 * @param string|null $commentId The path segment of the single comment operations, untouched.
	 *
	 * @return Result On success `getData()` holds the validated input of the operation.
	 */
	public function validate(
		string $operation,
		HttpRequest $request,
		?string $threadId = null,
		?string $commentId = null,
		?string $attachmentId = null,
	): Result
	{
		return match ($operation)
		{
			self::OPERATION_SET_IMPORTANT,
			self::OPERATION_SET_PINNED => $this->validateFlag($request, $threadId),
			self::OPERATION_ADD_THREAD => $this->validateAddThread($request),
			self::OPERATION_GET_THREADS => $this->validateGetThreads($request),
			self::OPERATION_UPDATE_THREAD_COORDINATES => $this->validateUpdateThreadCoordinates($request, $threadId),
			self::OPERATION_DELETE_THREAD => $this->validateDeleteThread($request, $threadId),
			self::OPERATION_RESTORE_THREAD,
			self::OPERATION_RESOLVE_THREAD,
			self::OPERATION_UNRESOLVE_THREAD => $this->validateActorBody($request, $threadId),
			self::OPERATION_ADD_COMMENT => $this->validateAddComment($request, $threadId),
			self::OPERATION_GET_THREAD_META,
			self::OPERATION_GET_THREAD_COMMENTS,
			self::OPERATION_COUNT_COMMENTS,
			self::OPERATION_GET_MAX_COMMENT_ID => $this->validateThreadOnly($threadId),
			self::OPERATION_GET_COMMENT,
			self::OPERATION_DELETE_COMMENT,
			self::OPERATION_RECOVER_COMMENT => $this->validateCommentAddress($threadId, $commentId),
			self::OPERATION_UPDATE_COMMENT => $this->validateUpdateComment($request, $threadId, $commentId),
			self::OPERATION_ADD_REACTION => $this->validateAddReaction($request, $threadId, $commentId),
			self::OPERATION_REMOVE_REACTION => $this->validateRemoveReaction($request, $threadId, $commentId),
			self::OPERATION_MARK_READ => $this->validateMarkRead($request, $threadId),
			self::OPERATION_MARK_UNREAD => $this->validateReaderBody($request, $threadId),
			self::OPERATION_MARK_ALL_READ => $this->validateBoardReaderBody($request),
			self::OPERATION_IS_READ_ALL => $this->validateBoardReaderQuery($request),
			self::OPERATION_GET_READ_STATUSES => $this->validateReadStatuses($request),
			self::OPERATION_GET_MENTIONS => $this->validateGetMentions($request),
			self::OPERATION_UPLOAD_ATTACHMENT => $this->validateUploadAttachment($request),
			self::OPERATION_GET_ATTACHMENT => $this->validateAttachmentId($attachmentId),
			default => throw new ArgumentOutOfRangeException('operation'),
		};
	}

	private function validateAttachmentId(?string $attachmentId): Result
	{
		if (!is_string($attachmentId) || preg_match(self::ATTACHMENT_ID_PATTERN, $attachmentId) !== 1)
		{
			return self::fail(new Error(
				'Attachment was not found.',
				'attachment_not_found',
				['status' => 404],
			));
		}

		return self::succeed([self::FIELD_ATTACHMENT_ID => $attachmentId]);
	}

	private function validateAddThread(HttpRequest $request): Result
	{
		$body = self::readBody($request);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		foreach ([self::FIELD_DOCUMENT_ID, self::FIELD_AUTHOR_ID, self::FIELD_COORDINATES] as $field)
		{
			if (!array_key_exists($field, $body))
			{
				return self::fail(self::invalidArgument("Field '{$field}' is required."));
			}
		}

		$documentId = self::checkDocumentId($body[self::FIELD_DOCUMENT_ID]);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$authorId = $body[self::FIELD_AUTHOR_ID];
		if (!is_string($authorId))
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_AUTHOR_ID . "' must be a string."));
		}

		$coordinates = $body[self::FIELD_COORDINATES];
		if (!self::isObject($coordinates))
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_COORDINATES . "' must be an object."));
		}
		$coordinates = self::checkCoordinates($coordinates, self::FIELD_COORDINATES . '.');
		if ($coordinates instanceof Error)
		{
			return self::fail($coordinates);
		}

		return self::succeed([
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_AUTHOR_ID => $authorId,
			self::FIELD_COORDINATES => $coordinates,
		]);
	}

	/**
	 * The body is the `Coordinates` object itself and replaces the stored coordinates as a whole:
	 * an optional field that is absent is `null`, not "keep the old value".
	 *
	 * @return Result On success `getData()` holds `threadId` and the full `coordinates`.
	 */
	private function validateUpdateThreadCoordinates(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}

		$body = self::readBody($request);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		$coordinates = self::checkCoordinates($body, '');
		if ($coordinates instanceof Error)
		{
			return self::fail($coordinates);
		}

		return self::succeed([
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_COORDINATES => $coordinates,
		]);
	}

	/**
	 * The initiator comes in the query. The operation declares no `400`, so a `userId` that is
	 * missing or repeated names nobody who may act and is refused as `forbidden`.
	 */
	private function validateDeleteThread(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}

		$userId = self::readSingle(self::readQuery($request), self::FIELD_USER_ID);
		if ($userId instanceof Error || $userId === null)
		{
			return self::fail(self::forbidden("Query parameter '" . self::FIELD_USER_ID . "' must name the initiator."));
		}

		return self::succeed([
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_USER_ID => $userId,
		]);
	}

	private function validateFlag(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}

		$body = self::readBody($request);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}
		if (!is_string($body[self::FIELD_USER_ID] ?? null) || !is_bool($body[self::FIELD_VALUE] ?? null))
		{
			return self::fail(self::invalidArgument('Fields userId and value must be a string and a boolean.'));
		}

		return self::succeed([
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_USER_ID => $body[self::FIELD_USER_ID],
			self::FIELD_VALUE => $body[self::FIELD_VALUE],
		]);
	}

	/**
	 * `ActorBody` of restore, resolve and unresolve. These operations declare no `400`: a body that
	 * is not a JSON object or carries no string `userId` names nobody who may act and is refused as
	 * `forbidden`.
	 */
	private function validateActorBody(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}

		$body = self::readBody($request);
		$userId = $body instanceof Error ? null : ($body[self::FIELD_USER_ID] ?? null);
		if (!is_string($userId))
		{
			return self::fail(self::forbidden("Field '" . self::FIELD_USER_ID . "' must name the initiator."));
		}

		return self::succeed([
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_USER_ID => $userId,
		]);
	}

	/**
	 * The query is read from the request line, so a repeated parameter is seen as such. A parameter
	 * the contract does not name is ignored. A usable `threadId` addresses one thread and, as the
	 * contract says, makes every optional filter irrelevant: those are then neither read nor checked
	 * and the defaults are returned in their place.
	 *
	 * @return Result On success `getData()` holds `documentId`, `userId`, `threadId` (`?string`),
	 *     the five boolean filters and `sorting`.
	 */
	private function validateGetThreads(HttpRequest $request): Result
	{
		$query = self::readQuery($request);

		$documentId = self::readSingle($query, self::FIELD_DOCUMENT_ID);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}
		if ($documentId === null)
		{
			return self::fail(self::invalidArgument("Query parameter '" . self::FIELD_DOCUMENT_ID . "' is required."));
		}
		$documentId = self::checkDocumentId($documentId);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$userId = self::readSingle($query, self::FIELD_USER_ID);
		if ($userId instanceof Error)
		{
			return self::fail($userId);
		}
		if ($userId === null)
		{
			return self::fail(self::invalidArgument("Query parameter '" . self::FIELD_USER_ID . "' is required."));
		}

		$threadId = self::readSingle($query, self::FIELD_THREAD_ID);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}
		if ($threadId !== null)
		{
			if (preg_match(self::UUID_PATTERN, $threadId) !== 1)
			{
				return self::fail(self::invalidArgument("Query parameter '" . self::FIELD_THREAD_ID . "' must be a UUID."));
			}
			$threadId = strtolower($threadId);
		}

		$input = [
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_USER_ID => $userId,
			self::FIELD_THREAD_ID => $threadId,
		];
		foreach (self::BOOLEAN_FILTER_FIELDS as $field)
		{
			$input[$field] = false;
		}
		$input[self::FIELD_SORTING] = self::SORTING_DATE_DESC;

		if ($threadId !== null)
		{
			return self::succeed($input);
		}

		foreach (self::BOOLEAN_FILTER_FIELDS as $field)
		{
			$value = self::readBoolean($query, $field);
			if ($value instanceof Error)
			{
				return self::fail($value);
			}
			$input[$field] = $value ?? false;
		}

		$sorting = self::readSingle($query, self::FIELD_SORTING);
		if ($sorting instanceof Error)
		{
			return self::fail($sorting);
		}
		if ($sorting !== null)
		{
			if (!in_array($sorting, self::SORTING_VALUES, true))
			{
				return self::fail(self::invalidArgument(
					"Query parameter '" . self::FIELD_SORTING . "' must be one of " . implode(', ', self::SORTING_VALUES) . '.',
				));
			}
			$input[self::FIELD_SORTING] = $sorting;
		}

		return self::succeed($input);
	}

	/**
	 * Every occurrence of every parameter of the raw query, in the order of the request line.
	 *
	 * @return array<string, list<string>>
	 */
	private static function readQuery(HttpRequest $request): array
	{
		$query = [];
		foreach (self::splitQueryString(self::rawQueryString($request)) as [$name, $value])
		{
			$query[$name][] = $value;
		}

		return $query;
	}

	private static function rawQueryString(HttpRequest $request): string
	{
		$uri = $request->getRequestUri();
		if (is_string($uri))
		{
			$position = strpos($uri, '?');

			return $position === false ? '' : substr($uri, $position + 1);
		}

		return (string)$request->getServer()->get('QUERY_STRING');
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private static function splitQueryString(string $raw): array
	{
		$pairs = [];
		foreach (explode('&', $raw) as $segment)
		{
			if ($segment === '')
			{
				continue;
			}

			[$name, $value] = array_pad(explode('=', $segment, 2), 2, '');
			$pairs[] = [urldecode($name), urldecode($value)];
		}

		return $pairs;
	}

	/**
	 * @param array<string, list<string>> $query
	 */
	private static function readSingle(array $query, string $name): string|Error|null
	{
		$values = $query[$name] ?? [];
		if (count($values) > 1)
		{
			return self::invalidArgument("Query parameter '{$name}' must not be repeated.");
		}

		return $values[0] ?? null;
	}

	/**
	 * A boolean of the query is the literal `true` or `false` of the OpenAPI serialization.
	 *
	 * @param array<string, list<string>> $query
	 */
	private static function readBoolean(array $query, string $name): bool|Error|null
	{
		$value = self::readSingle($query, $name);
		if ($value instanceof Error || $value === null)
		{
			return $value;
		}

		return match ($value)
		{
			'true' => true,
			'false' => false,
			default => self::invalidArgument("Query parameter '{$name}' must be true or false."),
		};
	}

	/**
	 * The addressed thread is checked before the body: a request to a thread that cannot exist is
	 * "no such thread" whatever it carries. `content` absent is an empty text, `mentions` and
	 * `attached` absent are empty lists; a field present is held to the type the contract gives it,
	 * so an explicit `null` is refused. A text is required unless something is attached.
	 */
	private function validateAddComment(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}

		$body = self::readBody($request);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		if (!array_key_exists(self::FIELD_AUTHOR_ID, $body))
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_AUTHOR_ID . "' is required."));
		}

		$authorId = $body[self::FIELD_AUTHOR_ID];
		if (!is_string($authorId))
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_AUTHOR_ID . "' must be a string."));
		}

		$fields = self::readCommentFields($body);
		if ($fields instanceof Error)
		{
			return self::fail($fields);
		}

		$content = $fields[self::FIELD_CONTENT] ?? '';
		$attached = $fields[self::FIELD_ATTACHED] ?? [];
		if ($content === '' && $attached === [])
		{
			return self::fail(self::emptyComment());
		}

		return self::succeed([
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_AUTHOR_ID => $authorId,
			self::FIELD_CONTENT => $content,
			self::FIELD_MENTIONS => $fields[self::FIELD_MENTIONS] ?? [],
			self::FIELD_ATTACHED => $attached,
		]);
	}

	/**
	 * `UpdateCommentRequest` is sparse: a field that is absent stays as stored and is returned as
	 * `null` here, while a field that is present, an empty list included, replaces the stored one.
	 * Whether the text and the attachments are empty together is settled against the stored
	 * comment, except when the body alone already empties both.
	 *
	 * @return Result On success `getData()` holds `threadId`, `commentId` and the nullable `content`,
	 *     `mentions` and `attached`.
	 */
	private function validateUpdateComment(HttpRequest $request, ?string $threadId, ?string $commentId): Result
	{
		$address = $this->validateCommentAddress($threadId, $commentId);
		if (!$address->isSuccess())
		{
			return $address;
		}

		$body = self::readBody($request);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		$fields = self::readCommentFields($body);
		if ($fields instanceof Error)
		{
			return self::fail($fields);
		}

		if (($fields[self::FIELD_CONTENT] ?? null) === '' && ($fields[self::FIELD_ATTACHED] ?? null) === [])
		{
			return self::fail(self::emptyComment());
		}

		return self::succeed($address->getData() + [
			self::FIELD_CONTENT => $fields[self::FIELD_CONTENT],
			self::FIELD_MENTIONS => $fields[self::FIELD_MENTIONS],
			self::FIELD_ATTACHED => $fields[self::FIELD_ATTACHED],
		]);
	}

	/**
	 * The three fields a comment is made of, each `null` when the body does not carry it and held to
	 * the type of the contract when it does.
	 *
	 * @param array<string, mixed> $body
	 * @return array{content: ?string, mentions: ?list<string>, attached: ?list<string>}|Error
	 */
	private static function readCommentFields(array $body): array|Error
	{
		$content = null;
		if (array_key_exists(self::FIELD_CONTENT, $body))
		{
			$content = $body[self::FIELD_CONTENT];
			if (!is_string($content))
			{
				return self::invalidArgument("Field '" . self::FIELD_CONTENT . "' must be a string.");
			}
		}

		$mentions = self::readStringList($body, self::FIELD_MENTIONS);
		if ($mentions instanceof Error)
		{
			return $mentions;
		}
		if ($mentions !== null)
		{
			// Unlike attached, an oversized mentions list is never refused: only the first items, in
			// the order they were sent, are kept, and the rest is silently dropped before the write
			// and before anyone is notified.
			$mentions = array_slice($mentions, 0, Configuration::getCommentMentionsMaxItems());
		}

		$attached = self::readStringList($body, self::FIELD_ATTACHED);
		if ($attached instanceof Error)
		{
			return $attached;
		}
		if ($attached !== null)
		{
			if (count($attached) > self::ATTACHED_MAX_ITEMS)
			{
				return self::invalidArgument(
					"Field '" . self::FIELD_ATTACHED . "' must not have more than "
					. self::ATTACHED_MAX_ITEMS . ' items.',
				);
			}

			$attached = self::attachmentPaths($attached);
		}

		return [
			self::FIELD_CONTENT => $content,
			self::FIELD_MENTIONS => $mentions,
			self::FIELD_ATTACHED => $attached,
		];
	}

	/**
	 * The upload answers with a path and with the address that path is shown by, and the board names
	 * back whichever of the two it kept. Both are taken here and carried on as the path alone, so the
	 * proof of the upload, the stored comment and everything read back from it know one form. A value
	 * that is no address of this API is passed on untouched and proved as the path it claims to be.
	 *
	 * @param list<string> $attached
	 * @return list<string>
	 */
	private static function attachmentPaths(array $attached): array
	{
		$paths = [];
		foreach ($attached as $value)
		{
			$paths[] = AttachmentService::pathFromPublicUrl($value) ?? $value;
		}

		return $paths;
	}

	/**
	 * `ReactionRequest` names the user and the emoji, both required and both strings. The addressed
	 * comment is checked first, so a request to a comment that cannot exist is "no such comment"
	 * whatever the body carries; the body itself declares `400`.
	 *
	 * @return Result On success `getData()` holds `threadId`, `commentId`, `userId` and `emoji`.
	 */
	private function validateAddReaction(HttpRequest $request, ?string $threadId, ?string $commentId): Result
	{
		$address = $this->validateCommentAddress($threadId, $commentId);
		if (!$address->isSuccess())
		{
			return $address;
		}

		$body = self::readBody($request);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		$userId = $body[self::FIELD_USER_ID] ?? null;
		if (!is_string($userId) || $userId === '')
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_USER_ID . "' must be a string."));
		}

		$emoji = self::checkEmoji($body[self::FIELD_EMOJI] ?? null);
		if ($emoji instanceof Error)
		{
			return self::fail($emoji);
		}

		return self::succeed($address->getData() + [
			self::FIELD_USER_ID => $userId,
			self::FIELD_EMOJI => $emoji,
		]);
	}

	/**
	 * The same pair as the body of the addition, read from the query the contract declares it in.
	 *
	 * @return Result On success `getData()` holds `threadId`, `commentId`, `userId` and `emoji`.
	 */
	private function validateRemoveReaction(HttpRequest $request, ?string $threadId, ?string $commentId): Result
	{
		$address = $this->validateCommentAddress($threadId, $commentId);
		if (!$address->isSuccess())
		{
			return $address;
		}

		$query = self::readQuery($request);
		$userId = self::readSingle($query, self::FIELD_USER_ID);
		if ($userId instanceof Error)
		{
			return self::fail($userId);
		}
		if (!is_string($userId) || $userId === '')
		{
			return self::fail(
				self::invalidArgument("Query parameter '" . self::FIELD_USER_ID . "' is required."),
			);
		}

		$rawEmoji = self::readSingle($query, self::FIELD_EMOJI);
		if ($rawEmoji instanceof Error)
		{
			return self::fail($rawEmoji);
		}

		$emoji = self::checkEmoji($rawEmoji);
		if ($emoji instanceof Error)
		{
			return self::fail($emoji);
		}

		return self::succeed($address->getData() + [
			self::FIELD_USER_ID => $userId,
			self::FIELD_EMOJI => $emoji,
		]);
	}

	/**
	 * The contract bounds an emoji by characters, not by bytes, and says nothing else about it: the
	 * sequence is neither trimmed nor normalized, so what the client sent is what is stored and what
	 * comes back.
	 */
	private static function checkEmoji(mixed $value): string|Error
	{
		if (!is_string($value))
		{
			return self::invalidArgument("Field '" . self::FIELD_EMOJI . "' must be a string.");
		}

		$length = mb_strlen($value);
		if ($length < self::EMOJI_MIN_LENGTH || $length > self::EMOJI_MAX_LENGTH)
		{
			return self::invalidArgument(
				"Field '" . self::FIELD_EMOJI . "' must hold from " . self::EMOJI_MIN_LENGTH
				. ' to ' . self::EMOJI_MAX_LENGTH . ' characters.',
			);
		}

		return $value;
	}

	/**
	 * `MarkThreadReadRequest`. The operation declares no `400`, so a body it cannot act on is refused
	 * the way the thread transitions refuse theirs: a reader that cannot be read is `forbidden`,
	 * while a boundary that cannot name a comment is the single `404 comment_not_found` of the
	 * operation. `readFullThread` makes `lastReadCommentId` irrelevant, as the contract says.
	 *
	 * @return Result On success `getData()` holds `threadId`, `userId`, `isAnonymous`,
	 *     `readFullThread` and the nullable `lastReadCommentId`.
	 */
	private function validateMarkRead(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail(self::commentNotFound());
		}

		$reader = self::readReaderBody($request);
		if ($reader instanceof Error)
		{
			return self::fail($reader);
		}

		$body = self::readBody($request);
		$body = $body instanceof Error ? [] : $body;

		$readFullThread = $body[self::FIELD_READ_FULL_THREAD] ?? false;
		if (!is_bool($readFullThread))
		{
			return self::fail(self::forbidden("Field '" . self::FIELD_READ_FULL_THREAD . "' must be a boolean."));
		}

		$lastReadCommentId = null;
		if (!$readFullThread)
		{
			$value = $body[self::FIELD_LAST_READ_COMMENT_ID] ?? null;
			if (!is_int($value) || $value < 1 || $value > self::COMMENT_ID_MAX)
			{
				return self::fail(self::commentNotFound());
			}
			$lastReadCommentId = $value;
		}

		return self::succeed($reader + [
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_READ_FULL_THREAD => $readFullThread,
			self::FIELD_LAST_READ_COMMENT_ID => $lastReadCommentId,
		]);
	}

	/**
	 * `UserIdBody` of an addressed thread: the operation declares only `404 thread_not_found`, so an
	 * unusable identifier of the path is no thread and an unusable reader is `forbidden`.
	 *
	 * @return Result On success `getData()` holds `threadId`, `userId` and `isAnonymous`.
	 */
	private function validateReaderBody(HttpRequest $request, ?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail(self::threadNotFound());
		}

		$reader = self::readReaderBody($request);
		if ($reader instanceof Error)
		{
			return self::fail($reader);
		}

		return self::succeed($reader + [self::FIELD_THREAD_ID => $threadId]);
	}

	/**
	 * `UserIdBody` of a whole board, addressed by the document of the path.
	 *
	 * @return Result On success `getData()` holds `documentId`, `userId` and `isAnonymous`.
	 */
	private function validateBoardReaderBody(HttpRequest $request): Result
	{
		$documentId = self::readPathDocumentId($request);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$reader = self::readReaderBody($request);
		if ($reader instanceof Error)
		{
			return self::fail($reader);
		}

		return self::succeed($reader + [self::FIELD_DOCUMENT_ID => $documentId]);
	}

	/**
	 * The same reader, named by the query instead of a body.
	 *
	 * @return Result On success `getData()` holds `documentId`, `userId` and `isAnonymous`.
	 */
	private function validateBoardReaderQuery(HttpRequest $request): Result
	{
		$documentId = self::readPathDocumentId($request);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$query = self::readQuery($request);
		$userId = self::readSingle($query, self::FIELD_USER_ID);
		if (!is_string($userId) || $userId === '')
		{
			return self::fail(self::forbidden("Query parameter '" . self::FIELD_USER_ID . "' must name the reader."));
		}

		$isAnonymous = self::readBoolean($query, self::FIELD_IS_ANONYMOUS);
		if ($isAnonymous instanceof Error)
		{
			return self::fail(self::forbidden("Query parameter '" . self::FIELD_IS_ANONYMOUS . "' must be a boolean."));
		}

		return self::succeed([
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_USER_ID => $userId,
			self::FIELD_IS_ANONYMOUS => $isAnonymous,
		]);
	}

	/**
	 * The mentions of one reader on one board. Both identifiers are required and are matched against
	 * the token by the operation itself, which refuses a mismatch with `403`; `onlyUnread` is absent
	 * as `false`, the whole feed of the board.
	 *
	 * @return Result On success `getData()` holds `documentId`, `userId` and `onlyUnread`.
	 */
	private function validateGetMentions(HttpRequest $request): Result
	{
		$query = self::readQuery($request);

		$documentId = self::readSingle($query, self::FIELD_DOCUMENT_ID);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}
		if ($documentId === null)
		{
			return self::fail(self::invalidArgument("Query parameter '" . self::FIELD_DOCUMENT_ID . "' is required."));
		}
		$documentId = self::checkDocumentId($documentId);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$userId = self::readSingle($query, self::FIELD_USER_ID);
		if ($userId instanceof Error)
		{
			return self::fail($userId);
		}
		if ($userId === null)
		{
			return self::fail(self::invalidArgument("Query parameter '" . self::FIELD_USER_ID . "' is required."));
		}

		$onlyUnread = self::readBoolean($query, self::FIELD_ONLY_UNREAD);
		if ($onlyUnread instanceof Error)
		{
			return self::fail($onlyUnread);
		}

		return self::succeed([
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_USER_ID => $userId,
			self::FIELD_ONLY_UNREAD => $onlyUnread ?? false,
		]);
	}

	/**
	 * The query of read statuses. The operation declares no error of its own beyond the ones of the
	 * whole contract, so a list it cannot read is an empty list rather than a refusal: what remains
	 * of it is matched against the threads of the board and answered with `200`.
	 *
	 * @return Result On success `getData()` holds `userId`, `isAnonymous` and `threadIds`.
	 */
	private function validateReadStatuses(HttpRequest $request): Result
	{
		$reader = self::readReaderBody($request);
		if ($reader instanceof Error)
		{
			return self::fail($reader);
		}

		$body = self::readBody($request);
		$requested = $body instanceof Error ? null : ($body[self::FIELD_THREAD_IDS] ?? null);

		$threadIds = [];
		foreach (is_array($requested) ? $requested : [] as $threadId)
		{
			if (is_string($threadId) && $threadId !== '')
			{
				$threadIds[] = $threadId;
			}
		}

		return self::succeed($reader + [self::FIELD_THREAD_IDS => $threadIds]);
	}

	/**
	 * The reader of an operation of read state: who they are and whether they claim to be a guest.
	 * Neither value is trusted here; the context of the token settles both.
	 *
	 * @return array{userId: string, isAnonymous: ?bool}|Error
	 */
	private static function readReaderBody(HttpRequest $request): array|Error
	{
		$body = self::readBody($request);
		$userId = $body instanceof Error ? null : ($body[self::FIELD_USER_ID] ?? null);
		if (!is_string($userId) || $userId === '')
		{
			return self::forbidden("Field '" . self::FIELD_USER_ID . "' must name the reader.");
		}

		$isAnonymous = $body[self::FIELD_IS_ANONYMOUS] ?? null;
		if ($isAnonymous !== null && !is_bool($isAnonymous))
		{
			return self::forbidden("Field '" . self::FIELD_IS_ANONYMOUS . "' must be a boolean.");
		}

		return [
			self::FIELD_USER_ID => $userId,
			self::FIELD_IS_ANONYMOUS => $isAnonymous,
		];
	}

	/**
	 * The document of the path, which the router has already put among the parameters of the request.
	 */
	private static function readPathDocumentId(HttpRequest $request): string|Error
	{
		$documentId = $request->getQuery(self::FIELD_DOCUMENT_ID);
		if (!is_string($documentId) || $documentId === '')
		{
			return self::forbidden("Path segment '" . self::FIELD_DOCUMENT_ID . "' must name the board.");
		}

		return $documentId;
	}

	/**
	 * The only operation of the API that carries a file instead of a body. The board and the user
	 * come from the token alone, so nothing but the one field the contract names is read here.
	 *
	 * A file too large for PHP itself never arrives as bytes but as an upload error, so that error
	 * and an accepted upload over the limit answer alike with `413`; a missing field and any other
	 * upload failure are `400`. The size is taken from the bytes that did arrive, not from what the
	 * request claims about them.
	 *
	 * @return Result On success `getData()` holds `tmpName`, `name` and `size` of the upload.
	 */
	private function validateUploadAttachment(HttpRequest $request): Result
	{
		$file = $request->getFile(self::FIELD_UPLOAD_FILE);
		if (!is_array($file))
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_UPLOAD_FILE . "' is required."));
		}

		$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE)
		{
			return self::fail(self::fileTooLarge());
		}
		if ($uploadError !== UPLOAD_ERR_OK)
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_UPLOAD_FILE . "' was not uploaded."));
		}

		$tmpName = $file['tmp_name'] ?? null;
		$name = $file['name'] ?? null;
		if (!is_string($tmpName) || $tmpName === '' || !is_string($name) || $name === '')
		{
			return self::fail(self::invalidArgument("Field '" . self::FIELD_UPLOAD_FILE . "' is not a file."));
		}

		$actualSize = @filesize($tmpName);
		$size = is_int($actualSize) ? $actualSize : (int)($file['size'] ?? 0);
		if ($size > self::UPLOAD_MAX_FILE_SIZE)
		{
			return self::fail(self::fileTooLarge());
		}

		return self::succeed([
			'tmpName' => $tmpName,
			'name' => $name,
			'size' => $size,
		]);
	}

	/**
	 * The operation has neither a body nor query parameters: only the addressed thread is checked.
	 */
	private function validateThreadOnly(?string $threadId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail($threadId);
		}

		return self::succeed([self::FIELD_THREAD_ID => $threadId]);
	}

	/**
	 * The thread is checked first, as everywhere, but the operations of one comment declare only
	 * `comment_not_found`, so an unusable thread segment answers with that code too.
	 */
	private function validateCommentAddress(?string $threadId, ?string $commentId): Result
	{
		$threadId = self::checkThreadId($threadId);
		if ($threadId instanceof Error)
		{
			return self::fail(self::commentNotFound());
		}

		$commentId = self::checkCommentId($commentId);
		if ($commentId instanceof Error)
		{
			return self::fail($commentId);
		}

		return self::succeed([
			self::FIELD_THREAD_ID => $threadId,
			self::FIELD_COMMENT_ID => $commentId,
		]);
	}

	/**
	 * The body is decoded from the bytes the client sent and has to be a JSON object: malformed JSON
	 * and a body of another type are refused rather than read as an empty object, which for a sparse
	 * body would be a successful request that changes nothing.
	 *
	 * @return array<string, mixed>|Error
	 */
	private static function readBody(HttpRequest $request): array|Error
	{
		if (!$request->isJson())
		{
			return self::invalidArgument('Request body must be application/json.');
		}

		$raw = $request::getInput();
		try
		{
			$body = Json::decode($raw);
		}
		catch (ArgumentException)
		{
			return self::invalidArgument('Request body must be valid JSON.');
		}

		if (!self::isObject($body) || ($body === [] && !str_starts_with(ltrim($raw), '{')))
		{
			return self::invalidArgument('Request body must be a JSON object.');
		}

		return $body;
	}

	/**
	 * A decoded JSON object: an array that is not a list. `{}` and `[]` both decode to an empty
	 * array, so an empty one passes here and is told apart by the caller when it matters.
	 */
	private static function isObject(mixed $value): bool
	{
		return is_array($value) && ($value === [] || !array_is_list($value));
	}

	private static function checkDocumentId(mixed $value): string|Error
	{
		if (!is_string($value))
		{
			return self::invalidArgument("Field '" . self::FIELD_DOCUMENT_ID . "' must be a string.");
		}
		if ($value === '')
		{
			return self::invalidArgument("Field '" . self::FIELD_DOCUMENT_ID . "' must not be empty.");
		}

		return $value;
	}

	/**
	 * The canonical form of the identifier for a lookup; the value stored with the thread is what
	 * the response carries back.
	 */
	private static function checkThreadId(?string $threadId): string|Error
	{
		if ($threadId === null || preg_match(self::UUID_PATTERN, $threadId) !== 1)
		{
			return self::threadNotFound();
		}

		return strtolower($threadId);
	}

	/**
	 * A number of the contract is `int32` from 1: anything else cannot name a comment.
	 */
	private static function checkCommentId(?string $commentId): int|Error
	{
		if (
			$commentId === null
			|| preg_match(self::COMMENT_ID_PATTERN, $commentId) !== 1
			|| (int)$commentId > self::COMMENT_ID_MAX
		)
		{
			return self::commentNotFound();
		}

		return (int)$commentId;
	}

	/**
	 * `null` when the body does not carry the field; a field present has to be a list of strings.
	 *
	 * @param array<string, mixed> $body
	 * @return list<string>|Error|null
	 */
	private static function readStringList(array $body, string $name): array|Error|null
	{
		if (!array_key_exists($name, $body))
		{
			return null;
		}

		$value = $body[$name];
		if (!is_array($value) || !array_is_list($value))
		{
			return self::invalidArgument("Field '{$name}' must be a list of strings.");
		}

		foreach ($value as $item)
		{
			if (!is_string($item))
			{
				return self::invalidArgument("Field '{$name}' must contain only strings.");
			}
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $coordinates The decoded `Coordinates` object.
	 * @param string $fieldPrefix Path of the object inside the body for the messages, `coordinates.`
	 *     when it is nested and empty when it is the body itself.
	 * @return array{x: float, y: float, parentId: ?string, width: ?float, height: ?float}|Error
	 */
	private static function checkCoordinates(array $coordinates, string $fieldPrefix): array|Error
	{
		$x = self::readNumber($coordinates, self::FIELD_X, $fieldPrefix);
		if ($x instanceof Error)
		{
			return $x;
		}
		if ($x === null)
		{
			return self::invalidArgument("Field '{$fieldPrefix}" . self::FIELD_X . "' is required.");
		}

		$y = self::readNumber($coordinates, self::FIELD_Y, $fieldPrefix);
		if ($y instanceof Error)
		{
			return $y;
		}
		if ($y === null)
		{
			return self::invalidArgument("Field '{$fieldPrefix}" . self::FIELD_Y . "' is required.");
		}

		$width = self::readNumber($coordinates, self::FIELD_WIDTH, $fieldPrefix);
		if ($width instanceof Error)
		{
			return $width;
		}

		$height = self::readNumber($coordinates, self::FIELD_HEIGHT, $fieldPrefix);
		if ($height instanceof Error)
		{
			return $height;
		}

		$parentId = self::checkParentId($coordinates, $fieldPrefix);
		if ($parentId instanceof Error)
		{
			return $parentId;
		}

		return [
			self::FIELD_X => $x,
			self::FIELD_Y => $y,
			self::FIELD_PARENT_ID => $parentId,
			self::FIELD_WIDTH => $width,
			self::FIELD_HEIGHT => $height,
		];
	}

	/**
	 * A missing key and an explicit `null` are the same absence: the contract lets `width`, `height`
	 * and `parentId` be null, and the required pair is checked by the caller.
	 *
	 * @param array<string, mixed> $coordinates
	 */
	private static function readNumber(array $coordinates, string $name, string $fieldPrefix): float|Error|null
	{
		$value = $coordinates[$name] ?? null;
		if ($value === null)
		{
			return null;
		}

		if (!is_int($value) && !is_float($value))
		{
			return self::invalidArgument("Field '{$fieldPrefix}{$name}' must be a number.");
		}

		$value = (float)$value;
		if (!is_finite($value))
		{
			return self::invalidArgument("Field '{$fieldPrefix}{$name}' must be finite.");
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $coordinates
	 */
	private static function checkParentId(array $coordinates, string $fieldPrefix): string|Error|null
	{
		$value = $coordinates[self::FIELD_PARENT_ID] ?? null;
		if ($value === null)
		{
			return null;
		}

		if (!is_string($value) || preg_match(self::ULID_PATTERN, $value) !== 1)
		{
			return self::invalidArgument("Field '{$fieldPrefix}" . self::FIELD_PARENT_ID . "' must be a ULID.");
		}

		return $value;
	}

	private static function invalidArgument(string $message): Error
	{
		return new Error($message, self::ERROR_INVALID_ARGUMENT, ['status' => 400]);
	}

	private static function emptyComment(): Error
	{
		return self::invalidArgument(
			"Field '" . self::FIELD_CONTENT . "' must not be empty without '" . self::FIELD_ATTACHED . "'.",
		);
	}

	private static function fileTooLarge(): Error
	{
		return new Error('File is too large.', self::ERROR_FILE_TOO_LARGE, ['status' => 413]);
	}

	private static function forbidden(string $message): Error
	{
		return new Error($message, self::ERROR_FORBIDDEN, ['status' => 403]);
	}

	private static function threadNotFound(): Error
	{
		return new Error('Thread was not found.', self::ERROR_THREAD_NOT_FOUND, ['status' => 404]);
	}

	private static function commentNotFound(): Error
	{
		return new Error('Comment was not found.', self::ERROR_COMMENT_NOT_FOUND, ['status' => 404]);
	}

	private static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function succeed(array $data): Result
	{
		return (new Result())->setData($data);
	}
}
