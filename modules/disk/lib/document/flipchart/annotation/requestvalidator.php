<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Annotation;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;

/**
 * Strict syntax of one operation of the annotations API: path, query and JSON body exactly as the
 * contract declares them, with no normalization of the values and no field the contract does not name.
 *
 * The query is read from the raw request line, so a repeated `parentId` keeps every occurrence in
 * order and the parameters a router adds to `$_GET` from the path never count as query parameters.
 */
final class RequestValidator
{
	public const OPERATION_LIST = 'listAnnotations';
	public const OPERATION_COUNT = 'countAnnotationsByElement';
	public const OPERATION_GET = 'getAnnotation';
	public const OPERATION_CREATE = 'createAnnotation';
	public const OPERATION_PATCH = 'patchAnnotation';
	public const OPERATION_DELETE = 'deleteAnnotationsBatch';
	public const OPERATION_RESTORE = 'restoreAnnotationsBatch';

	public const FIELD_DOCUMENT_ID = 'documentId';
	public const FIELD_ANNOTATION_ID = 'annotationId';
	public const FIELD_ANNOTATION_IDS = 'annotationIds';
	public const FIELD_PARENT_ID = 'parentId';
	public const FIELD_PARENT_IDS = 'parentIds';
	public const FIELD_LIMIT = 'limit';
	public const FIELD_CURSOR = 'cursor';
	public const FIELD_POSITION = 'position';
	public const FIELD_EDGE = 'edge';
	public const FIELD_T = 't';
	public const FIELD_CONTENT = 'content';

	public const LIMIT_DEFAULT = 200;
	public const LIMIT_MAX = 500;
	public const BATCH_MAX = 500;
	/** The width of DOCUMENT_ID in b_disk_flipchart_annotation: the column leads three indexes. */
	public const DOCUMENT_ID_MAX_BYTES = 255;
	public const CONTENT_MAX_LENGTH = 5000;
	public const EDGES = ['top', 'right', 'bottom', 'left'];

	private const ERROR_CODE = 'invalid_argument';

	private const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';
	private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
	private const CURSOR_PATTERN = '/^[A-Za-z0-9_-]+$/D';
	private const LIMIT_PATTERN = '/^(0|[1-9][0-9]*)$/D';

	/**
	 * @param string $operation One of the OPERATION_* constants.
	 * @param string|null $annotationId The path segment of the addressed operations, untouched.
	 *
	 * @return Result On success `getData()` holds the validated input of the operation.
	 */
	public function validate(string $operation, HttpRequest $request, ?string $annotationId = null): Result
	{
		return match ($operation)
		{
			self::OPERATION_LIST => $this->validateList($request),
			self::OPERATION_COUNT => $this->validateCount($request),
			self::OPERATION_GET => $this->validateGet($request, $annotationId),
			self::OPERATION_CREATE => $this->validateCreate($request, $annotationId),
			self::OPERATION_PATCH => $this->validatePatch($request, $annotationId),
			self::OPERATION_DELETE, self::OPERATION_RESTORE => $this->validateBatch($request),
			default => throw new ArgumentOutOfRangeException('operation'),
		};
	}

	private function validateList(HttpRequest $request): Result
	{
		$query = self::readQuery($request, [
			self::FIELD_DOCUMENT_ID,
			self::FIELD_LIMIT,
			self::FIELD_CURSOR,
			self::FIELD_PARENT_ID,
		]);
		if ($query instanceof Error)
		{
			return self::fail($query);
		}

		$documentId = self::readQueryDocumentId($query);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$limit = self::readLimit($query);
		if ($limit instanceof Error)
		{
			return self::fail($limit);
		}

		$cursor = self::readCursor($query);
		if ($cursor instanceof Error)
		{
			return self::fail($cursor);
		}

		$parentIds = self::checkIdList(
			$query[self::FIELD_PARENT_ID] ?? [],
			self::FIELD_PARENT_ID,
			self::ULID_PATTERN,
			'a ULID',
			0,
		);
		if ($parentIds instanceof Error)
		{
			return self::fail($parentIds);
		}

		return self::succeed([
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_LIMIT => $limit,
			self::FIELD_CURSOR => $cursor,
			self::FIELD_PARENT_IDS => $parentIds,
		]);
	}

	private function validateCount(HttpRequest $request): Result
	{
		$query = self::readQuery($request, [self::FIELD_DOCUMENT_ID, self::FIELD_PARENT_ID]);
		if ($query instanceof Error)
		{
			return self::fail($query);
		}

		$documentId = self::readQueryDocumentId($query);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$parentId = self::readSingle($query, self::FIELD_PARENT_ID);
		if ($parentId instanceof Error)
		{
			return self::fail($parentId);
		}
		if ($parentId === null)
		{
			return self::fail(self::error("Query parameter '" . self::FIELD_PARENT_ID . "' is required."));
		}
		if (!self::matches($parentId, self::ULID_PATTERN))
		{
			return self::fail(self::error("Query parameter '" . self::FIELD_PARENT_ID . "' must be a ULID."));
		}

		return self::succeed([
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_PARENT_ID => $parentId,
		]);
	}

	private function validateGet(HttpRequest $request, ?string $annotationId): Result
	{
		$query = self::readQuery($request, []);
		if ($query instanceof Error)
		{
			return self::fail($query);
		}

		$annotationId = self::checkAnnotationId($annotationId);
		if ($annotationId instanceof Error)
		{
			return self::fail($annotationId);
		}

		return self::succeed([self::FIELD_ANNOTATION_ID => $annotationId]);
	}

	private function validateCreate(HttpRequest $request, ?string $annotationId): Result
	{
		$query = self::readQuery($request, []);
		if ($query instanceof Error)
		{
			return self::fail($query);
		}

		$annotationId = self::checkAnnotationId($annotationId);
		if ($annotationId instanceof Error)
		{
			return self::fail($annotationId);
		}

		$body = self::readBody($request, [
			self::FIELD_DOCUMENT_ID,
			self::FIELD_PARENT_ID,
			self::FIELD_POSITION,
			self::FIELD_CONTENT,
		]);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		$documentId = self::checkDocumentId($body[self::FIELD_DOCUMENT_ID] ?? null);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$parentId = $body[self::FIELD_PARENT_ID] ?? null;
		if (!is_string($parentId) || !self::matches($parentId, self::ULID_PATTERN))
		{
			return self::fail(self::error("Field '" . self::FIELD_PARENT_ID . "' must be a ULID."));
		}

		if (!array_key_exists(self::FIELD_POSITION, $body))
		{
			return self::fail(self::error("Field '" . self::FIELD_POSITION . "' is required."));
		}
		$position = self::checkPosition($body[self::FIELD_POSITION]);
		if ($position instanceof Error)
		{
			return self::fail($position);
		}

		if (!array_key_exists(self::FIELD_CONTENT, $body))
		{
			return self::fail(self::error("Field '" . self::FIELD_CONTENT . "' is required."));
		}
		$content = self::checkContent($body[self::FIELD_CONTENT]);
		if ($content instanceof Error)
		{
			return self::fail($content);
		}

		return self::succeed([
			self::FIELD_ANNOTATION_ID => $annotationId,
			self::FIELD_DOCUMENT_ID => $documentId,
			self::FIELD_PARENT_ID => $parentId,
			self::FIELD_POSITION => $position,
			self::FIELD_CONTENT => $content,
		]);
	}

	private function validatePatch(HttpRequest $request, ?string $annotationId): Result
	{
		$query = self::readQuery($request, []);
		if ($query instanceof Error)
		{
			return self::fail($query);
		}

		$annotationId = self::checkAnnotationId($annotationId);
		if ($annotationId instanceof Error)
		{
			return self::fail($annotationId);
		}

		$body = self::readBody($request, [self::FIELD_CONTENT, self::FIELD_POSITION]);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		$hasContent = array_key_exists(self::FIELD_CONTENT, $body);
		$hasPosition = array_key_exists(self::FIELD_POSITION, $body);
		if (!$hasContent && !$hasPosition)
		{
			return self::fail(self::error(
				"At least one of '" . self::FIELD_CONTENT . "' or '" . self::FIELD_POSITION . "' is required.",
			));
		}

		$data = [self::FIELD_ANNOTATION_ID => $annotationId];
		if ($hasContent)
		{
			$content = self::checkContent($body[self::FIELD_CONTENT]);
			if ($content instanceof Error)
			{
				return self::fail($content);
			}
			$data[self::FIELD_CONTENT] = $content;
		}
		if ($hasPosition)
		{
			$position = self::checkPosition($body[self::FIELD_POSITION]);
			if ($position instanceof Error)
			{
				return self::fail($position);
			}
			$data[self::FIELD_POSITION] = $position;
		}

		return self::succeed($data);
	}

	private function validateBatch(HttpRequest $request): Result
	{
		$query = self::readQuery($request, []);
		if ($query instanceof Error)
		{
			return self::fail($query);
		}

		$body = self::readBody($request, [
			self::FIELD_DOCUMENT_ID,
			self::FIELD_ANNOTATION_IDS,
			self::FIELD_PARENT_IDS,
		]);
		if ($body instanceof Error)
		{
			return self::fail($body);
		}

		$documentId = self::checkDocumentId($body[self::FIELD_DOCUMENT_ID] ?? null);
		if ($documentId instanceof Error)
		{
			return self::fail($documentId);
		}

		$hasAnnotationIds = array_key_exists(self::FIELD_ANNOTATION_IDS, $body);
		$hasParentIds = array_key_exists(self::FIELD_PARENT_IDS, $body);
		if ($hasAnnotationIds === $hasParentIds)
		{
			return self::fail(self::error(
				"Exactly one of '" . self::FIELD_ANNOTATION_IDS . "' or '" . self::FIELD_PARENT_IDS . "' is required.",
			));
		}

		$field = $hasAnnotationIds ? self::FIELD_ANNOTATION_IDS : self::FIELD_PARENT_IDS;
		$list = $body[$field];
		if (!is_array($list) || !array_is_list($list))
		{
			return self::fail(self::error("Field '{$field}' must be an array."));
		}

		$ids = $hasAnnotationIds
			? self::checkIdList($list, $field, self::UUID_V4_PATTERN, 'a canonical UUID v4', 1)
			: self::checkIdList($list, $field, self::ULID_PATTERN, 'a ULID', 1)
		;
		if ($ids instanceof Error)
		{
			return self::fail($ids);
		}

		return self::succeed([
			self::FIELD_DOCUMENT_ID => $documentId,
			$field => $ids,
		]);
	}

	/**
	 * Every occurrence of every parameter of the raw query, in the order of the request line.
	 *
	 * @param list<string> $allowedNames
	 *
	 * @return array<string, list<string>>|Error
	 */
	private static function readQuery(HttpRequest $request, array $allowedNames): array|Error
	{
		$query = [];
		foreach (self::splitQueryString(self::rawQueryString($request)) as [$name, $value])
		{
			if (!in_array($name, $allowedNames, true))
			{
				return self::error('Unknown query parameter ' . self::describeName($name) . '.');
			}
			if (!mb_check_encoding($value, 'UTF-8'))
			{
				return self::error("Query parameter '{$name}' is not valid UTF-8.");
			}

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
			return self::error("Query parameter '{$name}' must not be repeated.");
		}

		return $values[0] ?? null;
	}

	/**
	 * @param array<string, list<string>> $query
	 */
	private static function readQueryDocumentId(array $query): string|Error
	{
		$documentId = self::readSingle($query, self::FIELD_DOCUMENT_ID);
		if ($documentId instanceof Error)
		{
			return $documentId;
		}
		if ($documentId === null)
		{
			return self::error("Query parameter '" . self::FIELD_DOCUMENT_ID . "' is required.");
		}

		return self::checkDocumentId($documentId);
	}

	/**
	 * @param array<string, list<string>> $query
	 */
	private static function readLimit(array $query): int|Error
	{
		$limit = self::readSingle($query, self::FIELD_LIMIT);
		if ($limit instanceof Error)
		{
			return $limit;
		}
		if ($limit === null)
		{
			return self::LIMIT_DEFAULT;
		}
		if (!self::matches($limit, self::LIMIT_PATTERN) || (int)$limit < 1 || (int)$limit > self::LIMIT_MAX)
		{
			return self::error(
				"Query parameter '" . self::FIELD_LIMIT . "' must be an integer from 1 to " . self::LIMIT_MAX . '.',
			);
		}

		return (int)$limit;
	}

	/**
	 * The cursor stays opaque here: only its transport form is checked, its content is read by the
	 * service that issued it.
	 *
	 * @param array<string, list<string>> $query
	 */
	private static function readCursor(array $query): string|Error|null
	{
		$cursor = self::readSingle($query, self::FIELD_CURSOR);
		if ($cursor instanceof Error || $cursor === null)
		{
			return $cursor;
		}
		if (!self::matches($cursor, self::CURSOR_PATTERN))
		{
			return self::error("Query parameter '" . self::FIELD_CURSOR . "' is not a valid cursor.");
		}

		return $cursor;
	}

	/**
	 * @param list<string> $allowedFields
	 *
	 * @return array<string, mixed>|Error
	 */
	private static function readBody(HttpRequest $request, array $allowedFields): array|Error
	{
		if (!$request->isJson())
		{
			return self::error('Request body must be application/json.');
		}

		$request->decodeJson();
		$body = $request->getJsonList()->getValues();
		if ($body !== [] && array_is_list($body))
		{
			return self::error('Request body must be a JSON object.');
		}

		foreach (array_keys($body) as $name)
		{
			if (!in_array((string)$name, $allowedFields, true))
			{
				return self::error('Unknown field ' . self::describeName((string)$name) . '.');
			}
		}

		return $body;
	}

	private static function checkAnnotationId(?string $annotationId): string|Error
	{
		if ($annotationId === null || !self::matches($annotationId, self::UUID_V4_PATTERN))
		{
			return self::error(
				"Path parameter '" . self::FIELD_ANNOTATION_ID . "' must be a canonical UUID v4.",
			);
		}

		return $annotationId;
	}

	private static function checkDocumentId(mixed $value): string|Error
	{
		if (!is_string($value))
		{
			return self::error("Field '" . self::FIELD_DOCUMENT_ID . "' must be a string.");
		}

		$length = strlen($value);
		if ($length < 1 || $length > self::DOCUMENT_ID_MAX_BYTES)
		{
			return self::error(
				"Field '" . self::FIELD_DOCUMENT_ID . "' must be 1 to " . self::DOCUMENT_ID_MAX_BYTES . ' bytes.',
			);
		}

		return $value;
	}

	private static function checkContent(mixed $value): string|Error
	{
		if (!is_string($value))
		{
			return self::error("Field '" . self::FIELD_CONTENT . "' must be a string.");
		}

		$length = mb_strlen($value, 'UTF-8');
		if ($length < 1 || $length > self::CONTENT_MAX_LENGTH)
		{
			return self::error(
				"Field '" . self::FIELD_CONTENT . "' must be 1 to " . self::CONTENT_MAX_LENGTH . ' characters.',
			);
		}

		return $value;
	}

	/**
	 * @return array{edge: string, t: float}|Error
	 */
	private static function checkPosition(mixed $value): array|Error
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			return self::error("Field '" . self::FIELD_POSITION . "' must be an object.");
		}

		foreach (array_keys($value) as $name)
		{
			if (!in_array((string)$name, [self::FIELD_EDGE, self::FIELD_T], true))
			{
				return self::error(
					"Unknown field " . self::describeName((string)$name) . " in '" . self::FIELD_POSITION . "'.",
				);
			}
		}

		$edge = $value[self::FIELD_EDGE] ?? null;
		if (!is_string($edge) || !in_array($edge, self::EDGES, true))
		{
			return self::error(
				"Field '" . self::FIELD_POSITION . '.' . self::FIELD_EDGE . "' must be one of: "
				. implode(', ', self::EDGES) . '.',
			);
		}

		$t = array_key_exists(self::FIELD_T, $value) ? $value[self::FIELD_T] : null;
		if (!(is_int($t) || is_float($t)) || !is_finite((float)$t) || $t < 0 || $t > 1)
		{
			return self::error(
				"Field '" . self::FIELD_POSITION . '.' . self::FIELD_T . "' must be a number from 0 to 1.",
			);
		}

		return [
			self::FIELD_EDGE => $edge,
			self::FIELD_T => (float)$t,
		];
	}

	/**
	 * @param list<mixed> $values
	 *
	 * @return list<string>|Error
	 */
	private static function checkIdList(
		array $values,
		string $field,
		string $pattern,
		string $expected,
		int $minCount,
	): array|Error
	{
		$count = count($values);
		if ($count < $minCount || $count > self::BATCH_MAX)
		{
			return self::error("Field '{$field}' must contain from {$minCount} to " . self::BATCH_MAX . ' values.');
		}

		$seen = [];
		foreach ($values as $value)
		{
			if (!is_string($value) || !self::matches($value, $pattern))
			{
				return self::error("Every value of '{$field}' must be {$expected}.");
			}
			if (isset($seen[$value]))
			{
				return self::error("Values of '{$field}' must be unique.");
			}

			$seen[$value] = true;
		}

		return array_values($values);
	}

	private static function matches(string $value, string $pattern): bool
	{
		return preg_match($pattern, $value) === 1;
	}

	/**
	 * Client input in a diagnostic message: short and safe to serialize, or a placeholder.
	 */
	private static function describeName(string $name): string
	{
		if (!mb_check_encoding($name, 'UTF-8'))
		{
			return '(non-UTF-8 name)';
		}

		return "'" . mb_substr($name, 0, 64, 'UTF-8') . "'";
	}

	private static function error(string $message): Error
	{
		return new Error($message, self::ERROR_CODE, ['status' => 400]);
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
