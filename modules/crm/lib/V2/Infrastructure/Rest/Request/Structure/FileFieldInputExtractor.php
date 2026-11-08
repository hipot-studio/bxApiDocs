<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AbstractItemRequest;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\FileUploadGateway;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\File\SystemFileFieldHandlerRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Structure\Structure;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use ReflectionClass;

final class FileFieldInputExtractor
{
	private const SYSTEM_FIELD_NAMES = [
		'logo' => 'LOGO',
		'photo' => 'PHOTO',
	];
	public const OPERATION_ADD = 'add';
	public const OPERATION_UPDATE = 'update';

	public function __construct(
		private readonly CustomFieldRegistry $customFieldRegistry,
		private readonly FileUploadGateway $fileUploadGateway,
		private readonly SystemFileFieldHandlerRegistry $systemFileFieldHandlerRegistry =
			new SystemFileFieldHandlerRegistry(),
	)
	{
	}

	public function extract(
		AbstractItemRequest $request,
		EntityType $entityType,
		string $operation,
	): FileFieldInputBag
	{
		$descriptors = $this->customFieldRegistry->getEntityDescriptorsMap($entityType);
		$rawFields = $request->getRawCustomFields();
		$jsonShapes = $request->getRawCustomFieldJsonShapes();
		$remainingFields = $request->fields->getUserFields();
		$bag = new FileFieldInputBag();

		foreach ($rawFields as $fieldName => $rawValue)
		{
			$descriptor = $descriptors[$fieldName] ?? null;
			if ($descriptor?->type !== 'file')
			{
				continue;
			}
			$this->ensureFieldIsAllowedByScope($request, $fieldName);

			unset($remainingFields[$fieldName]);
			$value = $descriptor->isMultiple
				? $this->extractMultiple($rawValue, $fieldName, $jsonShapes[$fieldName] ?? null)
				: $this->extractSingle($rawValue, $fieldName, $jsonShapes[$fieldName]['shape'] ?? null)
			;
			$bag->add(
				$fieldName,
				$descriptor->isMultiple,
				$rawValue,
				new FileFieldWriteRequest($fieldName, $descriptor->isMultiple, $value),
			);
		}
		if ($bag->getWriteRequests() === [])
		{
			return $this->extractSystemFields($request, $entityType, $bag);
		}

		$request->fields = FieldsStructure::create(
			array_merge($request->fields->getItems(), $remainingFields),
			$request->getDtoClass(),
			$request,
		);
		foreach ($request->fields->getUserFields() as $fieldName => $_)
		{
			if (($descriptors[$fieldName] ?? null)?->type === 'file')
			{
				throw new SystemException('Unable to extract file field input.');
			}
		}

		return $this->extractSystemFields($request, $entityType, $bag);
	}

	public function extractExistingFileReference(mixed $value, string $path): int
	{
		if (!is_array($value) || array_is_list($value))
		{
			throw new InvalidRequestFieldTypeException($path, 'FileDto');
		}

		$fileId = $this->extractExistingFileId($value, $path);
		if ($fileId === null)
		{
			throw new InvalidRequestFieldTypeException($path, 'FileDto');
		}

		return $fileId;
	}

	private function extractSystemFields(
		AbstractItemRequest $request,
		EntityType $entityType,
		FileFieldInputBag $bag,
	): FileFieldInputBag
	{
		$fields = $request->fields->getItems();
		$changed = false;
		foreach ($fields as $restFieldName => $rawValue)
		{
			$handler = is_string($restFieldName)
				? $this->systemFileFieldHandlerRegistry->get(
					$entityType,
					self::SYSTEM_FIELD_NAMES[$restFieldName] ?? '',
				)
				: null;
			if ($handler === null)
			{
				continue;
			}

			$this->ensureFieldIsAllowedByScope($request, $restFieldName);
			$value = $rawValue === null
				? null
				: $this->extractSingle($rawValue, 'fields.' . $restFieldName, null, false);
			$bag->add(
				$handler->getFieldName(),
				$handler->isMultiple(),
				$rawValue,
				new FileFieldWriteRequest($handler->getFieldName(), $handler->isMultiple(), $value),
				$restFieldName,
			);
			unset($fields[$restFieldName]);
			$changed = true;
		}

		if ($changed)
		{
			$request->fields = FieldsStructure::create(
				array_merge($fields, $request->fields->getUserFields()),
				$request->getDtoClass(),
				$request,
			);
		}

		return $bag;
	}

	private function ensureFieldIsAllowedByScope(AbstractItemRequest $request, string $fieldName): void
	{
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];
		if ($availableFields === [] || in_array($fieldName, $availableFields, true))
		{
			return;
		}

		$dto = Structure::getDto($request->getDtoClass());
		$dtoShortName = $dto?->getShortName()
			?? (new ReflectionClass($request->getDtoClass()))->getShortName();

		throw new UnknownDtoPropertyException($dtoShortName, $fieldName);
	}

	private function extractSingle(
		mixed $value,
		string $path,
		?string $jsonShape,
		bool $allowExistingReference = true,
	): mixed
	{
		if ($value === null)
		{
			return null;
		}
		if ($jsonShape !== null ? $jsonShape !== 'object' : (!is_array($value) || array_is_list($value)))
		{
			throw new InvalidRequestFieldTypeException($path, 'FileDto|null');
		}
		$existingFileId = $allowExistingReference
			? $this->extractExistingFileId($value, $path)
			: null;
		if ($existingFileId !== null)
		{
			return $existingFileId;
		}
		if (!$allowExistingReference && array_key_exists('id', $value))
		{
			throw new RequestValidationException([
				new Error(
					new LocalizableMessage('REST_V3_EXCEPTION_VALIDATION_REQUESTVALIDATIONEXCEPTION'),
					$path,
				),
			]);
		}

		return $this->fileUploadGateway->createInput($value, $path);
	}

	/**
	 * @return array<int, int|\Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileUploadInput>
	 */
	private function extractMultiple(
		mixed $value,
		string $path,
		?array $jsonShape,
	): array
	{
		if (
			($jsonShape !== null && ($jsonShape['shape'] ?? null) !== 'array')
			|| !is_array($value)
			|| !array_is_list($value)
		)
		{
			throw new InvalidRequestFieldTypeException($path, 'FileDto[]');
		}

		$result = [];
		$ids = [];
		foreach ($value as $index => $item)
		{
			$itemPath = $path . '.' . $index;
			$itemJsonShape = $jsonShape['items'][$index] ?? null;
			if (
				($itemJsonShape !== null && $itemJsonShape !== 'object')
				|| !is_array($item)
				|| ($itemJsonShape === null && array_is_list($item))
			)
			{
				throw new InvalidRequestFieldTypeException($itemPath, 'FileDto');
			}
			$existingFileId = $this->extractExistingFileId($item, $itemPath);
			if ($existingFileId !== null)
			{
				if (isset($ids[$existingFileId]))
				{
					throw new RequestValidationException([
						new Error(
							new LocalizableMessage('REST_V3_EXCEPTION_VALIDATION_REQUESTVALIDATIONEXCEPTION'),
							$itemPath,
						),
					]);
				}
				$ids[$existingFileId] = true;
				$result[] = $existingFileId;
				continue;
			}
			$result[] = $this->fileUploadGateway->createInput($item, $itemPath);
		}

		return $result;
	}

	private function extractExistingFileId(array $value, string $path): ?int
	{
		if (!array_key_exists('id', $value))
		{
			return null;
		}
		if (array_key_exists('upload', $value))
		{
			throw new RequestValidationException([
				new Error(
					new LocalizableMessage('REST_V3_EXCEPTION_VALIDATION_REQUESTVALIDATIONEXCEPTION'),
					$path,
				),
			]);
		}

		$id = $value['id'];
		if (!is_string($id) || preg_match('/\\A[1-9][0-9]*\\z/', $id) !== 1)
		{
			throw new RequestValidationException([
				new Error(
					new LocalizableMessage('REST_V3_EXCEPTION_VALIDATION_REQUESTVALIDATIONEXCEPTION'),
					$path . '.id',
				),
			]);
		}

		$fileId = (int)$id;
		if ($fileId <= 0 || (string)$fileId !== ltrim($id, '0'))
		{
			throw new RequestValidationException([
				new Error(
					new LocalizableMessage('REST_V3_EXCEPTION_VALIDATION_REQUESTVALIDATIONEXCEPTION'),
					$path . '.id',
				),
			]);
		}

		return $fileId;
	}
}
