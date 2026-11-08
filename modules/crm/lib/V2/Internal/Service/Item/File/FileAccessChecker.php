<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileUploadInput;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class FileAccessChecker
{
	public const ACTION_ADD = 'add';
	public const ACTION_UPDATE = 'update';

	private readonly CustomFieldRegistry $customFieldRegistry;
	private readonly \Closure $fieldResolver;

	public function __construct(
		?CustomFieldRegistry $customFieldRegistry = null,
		?\Closure $fieldResolver = null,
	)
	{
		$this->customFieldRegistry = $customFieldRegistry ?? CustomFieldRegistry::getInstance();
		$this->fieldResolver = $fieldResolver ?? static function (
			EntityType $entityType,
			string $fieldName,
		): ?Field {
			return
				Container::getInstance()
					->getFactory($entityType->getId())
					?->getFieldsCollection()
					->getField($fieldName)
			;
		};
	}

	/**
	 * @return Result<array{field: Field, currentFileIds: list<int>, skipped: bool}>
	 */
	public function check(
		EntityType $entityType,
		?int $userId,
		Item $legacyItem,
		string $action,
		FileFieldWriteRequest $request,
	): Result
	{
		$fieldName = $request->fieldName;

		if ($legacyItem->getEntityTypeId() !== $entityType->getId())
		{
			return $this->error('The item entity type does not match the request.', $fieldName);
		}

		$descriptor = $this->customFieldRegistry->getEntityDescriptorsMap($entityType)[$fieldName] ?? null;
		if ($descriptor === null)
		{
			return $this->error('The custom field does not exist.', $fieldName);
		}
		if ($descriptor->type !== Field::TYPE_FILE)
		{
			return $this->error('The custom field is not a file field.', $fieldName);
		}
		if (in_array($fieldName, $this->customFieldRegistry->getHiddenFieldNames($entityType, $userId), true))
		{
			return $this->error('The custom field is not accessible.', $fieldName);
		}

		$field = ($this->fieldResolver)($entityType, $fieldName);
		if ($field === null)
		{
			return $this->error('The CRM field does not exist.', $fieldName);
		}
		if (!$field->isUserField() || $field->getType() !== Field::TYPE_FILE)
		{
			return $this->error('The CRM field is not a file custom field.', $fieldName);
		}
		if (
			$descriptor->isMultiple !== $request->multiple
			|| $descriptor->isMultiple !== $field->isMultiple()
		)
		{
			return $this->error('The file field multiplicity does not match.', $fieldName);
		}
		if (!in_array($action, [self::ACTION_ADD, self::ACTION_UPDATE], true))
		{
			return $this->error('The file write action is not supported.', $fieldName);
		}

		if ($legacyItem->isFieldDisabled($fieldName))
		{
			return (new Result())->addError(new Error(
				CategoryError::FIELD_NOT_WRITABLE->getMessage(),
				CategoryError::FIELD_NOT_WRITABLE->value,
				['field' => $fieldName],
			));
		}

		$shapeError = $this->validateShape($request);
		if ($shapeError !== null)
		{
			return $shapeError;
		}

		if ($action === self::ACTION_ADD)
		{
			if ($this->containsExistingFileId($request->value))
			{
				$index = $request->multiple
					? array_key_first(array_filter($request->value, static fn (mixed $value): bool => is_int($value)))
					: null;
				return $this->error(
					'Existing file IDs are not allowed when adding an item.',
					$index === null ? $fieldName : "{$fieldName}.{$index}",
				);
			}

			return $this->success($field, [], false);
		}

		$currentFileIds = $this->normalizeCurrentFileIds($legacyItem->get($fieldName));
		if (!$request->multiple && is_int($request->value))
		{
			if ($request->value <= 0 || $currentFileIds !== [$request->value])
			{
				return $this->error('The existing file ID is not valid for this field.', $fieldName);
			}

			return $this->success($field, $currentFileIds, true);
		}
		elseif ($request->multiple)
		{
			$currentFileIdMap = array_fill_keys($currentFileIds, true);
			$seenFileIds = [];
			foreach ($request->value as $index => $value)
			{
				if (!is_int($value))
				{
					continue;
				}
				if (
					$value <= 0
					|| isset($seenFileIds[$value])
					|| !isset($currentFileIdMap[$value])
				)
				{
					return $this->error('The existing file ID is not valid for this field.', "{$fieldName}.{$index}");
				}

				$seenFileIds[$value] = true;
			}
		}

		return $this->success($field, $currentFileIds, false);
	}

	private function validateShape(FileFieldWriteRequest $request): ?Result
	{
		if (!$request->multiple)
		{
			if (
				$request->value !== null
				&& !is_int($request->value)
				&& !$request->value instanceof FileUploadInput
			)
			{
				return $this->error('A single file field accepts null, an existing ID, or one upload.', $request->fieldName);
			}

			return null;
		}

		if (!is_array($request->value) || !array_is_list($request->value))
		{
			return $this->error('A multiple file field accepts a list.', $request->fieldName);
		}

		foreach ($request->value as $index => $value)
		{
			if (!is_int($value) && !$value instanceof FileUploadInput)
			{
				return $this->error('A file list item must be an ID or an upload.', "{$request->fieldName}.{$index}");
			}
		}

		return null;
	}

	private function containsExistingFileId(mixed $value): bool
	{
		if (is_int($value))
		{
			return true;
		}

		return is_array($value) && array_filter($value, static fn (mixed $item): bool => is_int($item)) !== [];
	}

	/**
	 * @return list<int>
	 */
	private function normalizeCurrentFileIds(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$fileIds = [];
		$seenFileIds = [];

		foreach ($values as $candidate)
		{
			if (is_int($candidate))
			{
				$fileId = $candidate;
			}
			elseif (is_string($candidate) && preg_match('/\\A[0-9]+\\z/', $candidate) === 1)
			{
				$fileId = (int)$candidate;
			}
			else
			{
				continue;
			}

			if ($fileId <= 0 || isset($seenFileIds[$fileId]))
			{
				continue;
			}

			$fileIds[] = $fileId;
			$seenFileIds[$fileId] = true;
		}

		return $fileIds;
	}

	/**
	 * @param list<int> $currentFileIds
	 */
	private function success(Field $field, array $currentFileIds, bool $skipped): Result
	{
		$result = new Result();
		$result->setData([
			'field' => $field,
			'currentFileIds' => $currentFileIds,
			'skipped' => $skipped,
		]);

		return $result;
	}

	private function error(string $message, string $path): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $path));

		return $result;
	}
}
