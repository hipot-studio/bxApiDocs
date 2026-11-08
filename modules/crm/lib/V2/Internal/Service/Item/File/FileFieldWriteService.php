<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\FileUploader;
use Bitrix\Crm\Service\Operation;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\FileUploadGateway;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileUploadInput;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\CategoryDependentFieldException;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationObserver;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\PreparedItemOperation;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

/**
 * @internal
 */
final class FileFieldWriteService
{
	private const STATE_PENDING = 0;
	private const STATE_CRM_REGISTER_INTENT = 1;
	private const STATE_CRM_REGISTERED = 2;
	private const STATE_CRM_TEMPORARY_INTENT = 3;
	private const STATE_CRM_TEMPORARY = 4;
	private const STATE_UI_COMMIT_INTENT = 5;
	private const STATE_UI_COMMITTED = 6;

	private readonly \Closure $prepare;
	private readonly \Closure $checkAccess;
	private readonly \Closure $launch;
	private readonly \Closure $checkFileAccess;
	private readonly \Closure $processField;
	private readonly \Closure $canUpload;
	private readonly \Closure $upload;
	private readonly \Closure $uploadMany;
	private readonly \Closure $makePersistent;
	private readonly \Closure $makePersistentMany;
	private readonly \Closure $removePending;
	private readonly \Closure $applyFinalState;
	private readonly \Closure $sync;
	private readonly \Closure $observerFactory;
	private readonly \Closure $actualFileIdsLoader;
	private ?Container $container;
	private ?FileUploader $fileUploader;
	private ?FileAccessChecker $fileAccessChecker = null;
	private ?FileUploadGateway $fileUploadGateway = null;
	private ?FileFinalStateApplier $fileFinalStateApplier = null;
	private ?SystemFileFieldHandlerRegistry $systemFileFieldHandlerRegistry = null;

	public function __construct(
		?callable $prepare = null,
		?callable $checkAccess = null,
		?callable $launch = null,
		?callable $checkFileAccess = null,
		?callable $processField = null,
		?callable $canUpload = null,
		?callable $upload = null,
		?callable $makePersistent = null,
		?callable $removePending = null,
		?callable $applyFinalState = null,
		?callable $sync = null,
		?callable $observerFactory = null,
		?callable $actualFileIdsLoader = null,
		?FileUploader $fileUploader = null,
		?Container $container = null,
		?callable $uploadMany = null,
		?callable $makePersistentMany = null,
		?SystemFileFieldHandlerRegistry $systemFileFieldHandlerRegistry = null,
	)
	{
		$this->container = $container;
		$this->fileUploader = $fileUploader;
		$this->systemFileFieldHandlerRegistry = $systemFileFieldHandlerRegistry;
		$this->prepare = $this->closure(
			$prepare,
			fn(AbstractItemCommand $command): object => (new ItemOperationRunner($this->getContainer()))->prepare($command),
		);
		$this->checkAccess = $this->closure(
			$checkAccess,
			static fn(object $prepared): Result => $prepared->checkAccess(),
		);
		$this->launch = $this->closure(
			$launch,
			static fn(object $prepared, ItemOperationObserver $observer): Result => $prepared->launchInTransaction($observer),
		);
		$this->checkFileAccess = $this->closure(
			$checkFileAccess,
			fn(...$arguments): Result => $this->checkFileAccessWithSystemHandler(...$arguments),
		);
		$this->processField = $this->closure(
			$processField,
			fn(...$arguments): Result => $this->processFieldWithSystemHandler(...$arguments),
		);
		$this->canUpload = $this->closure(
			$canUpload,
			fn(...$arguments): bool => $this->getFileUploadGateway()->canUpload(...$arguments),
		);
		$this->upload = $this->closure(
			$upload,
			fn(...$arguments): array => $this->getFileUploadGateway()->upload(...$arguments),
		);
		$this->uploadMany = $this->closure(
			$uploadMany,
			$upload === null
				? fn(...$arguments): array => $this->getFileUploadGateway()->uploadMany(...$arguments)
				: function (array $inputs, ...$arguments): array {
					$result = [];
					try
					{
						foreach ($inputs as $index => $input)
						{
							$result[$index] = ($this->upload)($input, ...$arguments);
						}
					}
					catch (\Throwable $throwable)
					{
						foreach ($result as $uploaded)
						{
							try
							{
								($this->removePending)($uploaded['token'], $uploaded['fileId']);
							}
							catch (\Throwable)
							{
							}
						}

						throw $throwable;
					}

					return $result;
				},
		);
		$this->makePersistent = $this->closure(
			$makePersistent,
			fn(string $token, int $fileId): Result => $this->getFileUploadGateway()->makePersistentAndVerify(
				$token,
				$fileId,
			),
		);
		$this->makePersistentMany = $this->closure(
			$makePersistentMany,
			$makePersistent === null
				? fn(array $uploads): Result => $this->getFileUploadGateway()->makePersistentAndVerifyMany($uploads)
				: function (array $uploads): Result {
					foreach ($uploads as $upload)
					{
						$result = ($this->makePersistent)($upload['token'], $upload['fileId']);
						if (!$result->isSuccess())
						{
							return $result;
						}
					}

					return new Result();
				},
		);
		$this->removePending = $this->closure(
			$removePending,
			fn(string $token, int $fileId): Result => $this->getFileUploadGateway()->removeAndVerify(
				$token,
				$fileId,
			),
		);
		$this->applyFinalState = $this->closure(
			$applyFinalState,
			fn(...$arguments): mixed => $this->applyFinalStateWithSystemHandlers(...$arguments),
		);
		$this->sync = $this->closure(
			$sync,
			static fn(object $prepared): mixed => $prepared->syncChangedFieldsFromV2Item(),
		);
		$this->observerFactory = $this->closure(
			$observerFactory,
			static fn(): ItemOperationObserver => new ItemOperationObserver(),
		);
		$this->actualFileIdsLoader = $this->closure(
			$actualFileIdsLoader,
			fn(int $entityTypeId, int $itemId, array $fieldNames): array => $this->loadActualFileIdsFromStorage(
				$entityTypeId,
				$itemId,
				$fieldNames,
			),
		);
	}

	/**
	 * @param list<FileFieldWriteRequest> $requests
	 */
	public function execute(
		AbstractItemCommand $command,
		array $requests,
		?PreparedItemOperation $prepared = null,
	): Result
	{
		if ($prepared === null)
		{
			try
			{
				$prepared = ($this->prepare)($command);
			}
			catch (CategoryDependentFieldException $exception)
			{
				return (new Result())->addError($exception->getError());
			}

			$accessResult = ($this->checkAccess)($prepared);
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}
		}

		$observer = ($this->observerFactory)();
		if ($requests === [])
		{
			return ($this->launch)($prepared, $observer);
		}

		$action = match (true)
		{
			$command instanceof AddItemCommand => FileAccessChecker::ACTION_ADD,
			$command instanceof UpdateItemCommand => FileAccessChecker::ACTION_UPDATE,
			default => throw new ArgumentException('Unsupported item command'),
		};
		$v2Item = $prepared->getV2Item();
		$legacyItem = $prepared->getLegacyItem();
		$context = $prepared->getContext();
		$userPermissions = $prepared->getUserPermissions();
		$entityType = $v2Item->getEntityType();
		$entityId = $action === FileAccessChecker::ACTION_ADD ? 0 : (int)$legacyItem->getId();
		$categoryId = $legacyItem->getCategoryIdForPermissions();

		$preflight = $this->preflight(
			$requests,
			$entityType,
			$command->getUserId(),
			$legacyItem,
			$action,
			$entityId,
			$categoryId,
			$userPermissions,
			$context,
		);
		if ($preflight instanceof Result)
		{
			return $preflight;
		}
		if ($preflight === [] && $action === FileAccessChecker::ACTION_UPDATE && !$v2Item->hasChangedFields())
		{
			return new Result();
		}

		$ledger = [];
		$uploadedFileIds = [];

		try
		{
			foreach ($preflight as $plan)
			{
				/** @var FileFieldWriteRequest $request */
				$request = $plan['request'];
				$inputs = $this->uploads($request);
				$uploadedFiles = ($this->uploadMany)(
					$inputs,
					$entityType->getId(),
					$entityId,
					$categoryId,
					$request->fieldName,
					$userPermissions,
				);
				foreach ($uploadedFiles as $index => $uploaded)
				{
					$ledger[] = [
						'token' => $uploaded['token'],
						'fileId' => $uploaded['fileId'],
						'field' => $plan['field'],
						'fieldName' => $request->fieldName,
						'state' => self::STATE_PENDING,
					];
					$uploadedFileIds[$request->fieldName][$index] = $uploaded['fileId'];
				}
			}

			foreach ($ledger as &$entry)
			{
				$entry['state'] = self::STATE_CRM_REGISTER_INTENT;
				if ($entry['field'] instanceof Field)
				{
					$this->getFileUploader()->registerFileId($entry['field'], $entry['fileId']);
				}
				$entry['state'] = self::STATE_CRM_REGISTERED;
				$entry['state'] = self::STATE_CRM_TEMPORARY_INTENT;
				$this->getFileUploader()->markFileAsTemporary($entry['fileId']);
				$entry['state'] = self::STATE_CRM_TEMPORARY;
			}
			unset($entry);

			$pendingUploads = [];
			foreach ($ledger as &$entry)
			{
				$entry['state'] = self::STATE_UI_COMMIT_INTENT;
				$pendingUploads[] = ['token' => $entry['token'], 'fileId' => $entry['fileId']];
			}
			unset($entry);
			$commitResult = ($this->makePersistentMany)($pendingUploads);
			if (!$commitResult->isSuccess())
			{
				$this->cleanup($ledger, $observer, $entityType->getId(), $preflight);

				return $commitResult;
			}
			foreach ($ledger as &$entry)
			{
				$entry['state'] = self::STATE_UI_COMMITTED;
			}
			unset($entry);

			$activeRequests = array_map(
				static fn(array $plan): FileFieldWriteRequest => $plan['request'],
				$preflight,
			);
			($this->applyFinalState)($v2Item, $activeRequests, $uploadedFileIds);
			($this->sync)($prepared);
			$operationResult = ($this->launch)($prepared, $observer);
			$observerViolation = $this->getObserverResultViolation($observer, $operationResult);
			if ($observerViolation !== null)
			{
				if (!$operationResult->isSuccess())
				{
					$this->cleanup($ledger, $observer, $entityType->getId(), $preflight);

					return $operationResult;
				}

				throw $observerViolation;
			}
			if (!$operationResult->isSuccess())
			{
				$this->cleanup($ledger, $observer, $entityType->getId(), $preflight);
			}

			return $operationResult;
		}
		catch (\Throwable $throwable)
		{
			$this->cleanup($ledger, $observer, $entityType->getId(), $preflight);

			throw $throwable;
		}
	}

	/**
	 * @param list<FileFieldWriteRequest> $requests
	 *
	 * @return list<array{
	 *     request: FileFieldWriteRequest,
	 *     field: object,
	 *     currentFileIds: list<int>
	 * }>|Result
	 */
	private function preflight(
		array $requests,
		object $entityType,
		int $userId,
		LegacyItem $legacyItem,
		string $action,
		int $entityId,
		?int $categoryId,
		UserPermissions $userPermissions,
		object $context,
	): array|Result
	{
		$plans = [];
		foreach ($requests as $request)
		{
			if (!$request instanceof FileFieldWriteRequest)
			{
				throw new ArgumentException('Unexpected file field write request');
			}

			$checkResult = ($this->checkFileAccess)(
				$entityType,
				$userId,
				$legacyItem,
				$action,
				$request,
			);
			if (!$checkResult->isSuccess())
			{
				return $checkResult;
			}
			$data = $checkResult->getData();
			if (($data['skipped'] ?? false) === true)
			{
				continue;
			}
			$field = $data['field'] ?? null;
			if (!is_object($field))
			{
				throw new ArgumentException('File access checker did not return a CRM field');
			}

			$processResult = ($this->processField)(
				$field,
				$legacyItem,
				$request,
				$userPermissions,
				$context,
			);
			if (!$processResult->isSuccess())
			{
				return $processResult;
			}
			if (($processResult->getData()['survived'] ?? true) !== true)
			{
				continue;
			}
			$currentFileIds = $this->normalizeFileIds($data['currentFileIds'] ?? []);
			if ($this->isUnchangedUpdateRequest($action, $request, $currentFileIds))
			{
				continue;
			}

			$inputs = $this->uploads($request);
			if (
				$inputs !== []
				&& !($this->canUpload)(
					$entityType->getId(),
					$entityId,
					$categoryId,
					$request->fieldName,
					$userPermissions,
				)
			)
			{
				$errorCode = $action === FileAccessChecker::ACTION_ADD
					? Operation::ERROR_CODE_ITEM_ADD_ACCESS_DENIED
					: Operation::ERROR_CODE_ITEM_UPDATE_ACCESS_DENIED
				;

				return (new Result())->addError(new Error('Access denied.', $errorCode));
			}

			$plans[] = [
				'request' => $request,
				'field' => $field,
				'currentFileIds' => $currentFileIds,
			];
		}

		return $plans;
	}

	/**
	 * @param list<int> $currentFileIds
	 */
	private function isUnchangedUpdateRequest(
		string $action,
		FileFieldWriteRequest $request,
		array $currentFileIds,
	): bool
	{
		if ($action !== FileAccessChecker::ACTION_UPDATE)
		{
			return false;
		}
		if (!$request->multiple)
		{
			return $request->value === null && $currentFileIds === [];
		}
		if (!is_array($request->value))
		{
			return false;
		}

		return $request->value === $currentFileIds;
	}

	private function processSyntheticFieldValue(
		Field $field,
		LegacyItem $legacyItem,
		FileFieldWriteRequest $request,
		UserPermissions $userPermissions,
		object $context,
	): Result
	{
		$planningItem = clone $legacyItem;
		$syntheticValue = $this->syntheticValue($request);
		$planningItem->set($request->fieldName, $syntheticValue);

		$result = $field->processWithPermissions($planningItem, $userPermissions);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$result = $field->process($planningItem, $context);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return (new Result())->setData([
			'survived' => $planningItem->get($request->fieldName) === $syntheticValue,
		]);
	}

	private function checkFileAccessWithSystemHandler(...$arguments): Result
	{
		$entityType = $arguments[0] ?? null;
		$request = $arguments[4] ?? null;
		$handler = $entityType instanceof \Bitrix\Crm\V2\Public\EntityType
			&& $request instanceof FileFieldWriteRequest
			? $this->getSystemFileFieldHandlerRegistry()->get($entityType, $request->fieldName)
			: null;

		return $handler !== null
			? $handler->checkAccess(...$arguments)
			: $this->getFileAccessChecker()->check(...$arguments);
	}

	private function processFieldWithSystemHandler(...$arguments): Result
	{
		$request = $arguments[2] ?? null;
		$handler = $request instanceof FileFieldWriteRequest
			? $this->getSystemFileFieldHandlerRegistry()->get(
				$this->getEntityTypeFromProcessArguments($arguments),
				$request->fieldName,
			)
			: null;

		return $handler !== null
			? $handler->process(...$arguments)
			: $this->processSyntheticFieldValue(...$arguments);
	}

	private function getEntityTypeFromProcessArguments(array $arguments): \Bitrix\Crm\V2\Public\EntityType
	{
		$legacyItem = $arguments[1] ?? null;
		if ($legacyItem instanceof LegacyItem)
		{
			return \Bitrix\Crm\V2\Public\EntityType::fromId($legacyItem->getEntityTypeId());
		}

		throw new ArgumentException('Legacy CRM item is required for file field processing.');
	}

	private function applyFinalStateWithSystemHandlers(
		object $item,
		array $requests,
		array $uploadedFileIds,
	): void
	{
		$customFieldRequests = [];
		$entityType = $item->getEntityType();
		foreach ($requests as $request)
		{
			$handler = $request instanceof FileFieldWriteRequest
				? $this->getSystemFileFieldHandlerRegistry()->get($entityType, $request->fieldName)
				: null;
			if ($handler !== null)
			{
				$handler->applyFinalState($item, $request, $uploadedFileIds);

				continue;
			}

			$customFieldRequests[] = $request;
		}

		if ($customFieldRequests !== [])
		{
			$customFieldNames = array_fill_keys(
				array_map(
					static fn(FileFieldWriteRequest $request): string => $request->fieldName,
					$customFieldRequests,
				),
				true,
			);
			$this->getFileFinalStateApplier()->apply(
				$item,
				$customFieldRequests,
				array_intersect_key($uploadedFileIds, $customFieldNames),
			);
		}
	}

	private function syntheticValue(FileFieldWriteRequest $request): mixed
	{
		if (!$request->multiple)
		{
			return $request->value instanceof FileUploadInput ? PHP_INT_MAX : null;
		}

		$value = [];
		$syntheticFileId = PHP_INT_MAX;
		foreach ($request->value as $entry)
		{
			$value[] = $entry instanceof FileUploadInput ? $syntheticFileId-- : $entry;
		}

		return $value;
	}

	/**
	 * @return array<int, FileUploadInput>
	 */
	private function uploads(FileFieldWriteRequest $request): array
	{
		if ($request->value instanceof FileUploadInput)
		{
			return [0 => $request->value];
		}
		if (!is_array($request->value))
		{
			return [];
		}

		return array_filter(
			$request->value,
			static fn(mixed $value): bool => $value instanceof FileUploadInput,
		);
	}

	/**
	 * @param list<array{
	 *     token: string,
	 *     fileId: int,
	 *     field: Field,
	 *     fieldName: string,
	 *     state: int
	 * }> $ledger
	 */
	private function cleanup(array $ledger, object $observer, int $entityTypeId, array $plans): void
	{
		$actualFileIdsByField = $this->loadActualFileIds($observer, $entityTypeId, $plans);
		if ($actualFileIdsByField === false)
		{
			foreach ($ledger as $entry)
			{
				try
				{
					$this->getFileUploader()->markFileAsPersistent($entry['fileId']);
				}
				catch (\Throwable)
				{
				}
			}

			return;
		}

		$hasActualFileIds = is_array($actualFileIdsByField);
		$actualFileIdSetByField = !$hasActualFileIds
			? null
			: array_map(
				static fn(array $fileIds): array => array_fill_keys($fileIds, true),
				$actualFileIdsByField,
			)
		;
		$entries = array_reverse($ledger);

		$pendingEntries = [];
		foreach ($entries as $entry)
		{
			$isBound = $hasActualFileIds
				? isset($actualFileIdSetByField[$entry['fieldName']][$entry['fileId']])
				: false
			;
			if (!$isBound)
			{
				continue;
			}

			try
			{
				$this->getFileUploader()->markFileAsPersistent($entry['fileId']);
			}
			catch (\Throwable)
			{
			}
		}

		foreach ($entries as $entry)
		{
			$isBound = $hasActualFileIds
				? isset($actualFileIdSetByField[$entry['fieldName']][$entry['fileId']])
				: false
			;
			if ($isBound)
			{
				continue;
			}

			if ($entry['state'] < self::STATE_CRM_REGISTER_INTENT)
			{
				$this->cleanupLedgerEntry($entry);

				continue;
			}

			try
			{
				$this->getFileUploader()->markFileAsTemporary($entry['fileId']);
				$pendingEntries[] = $entry;
			}
			catch (\Throwable)
			{
			}
		}

		if ($pendingEntries !== [])
		{
			$uploads = array_map(
				static fn(array $entry): array => [
					'token' => $entry['token'],
					'fileId' => $entry['fileId'],
				],
				$pendingEntries,
			);
			$commitResult = null;
			try
			{
				$commitResult = ($this->makePersistentMany)($uploads);
			}
			catch (\Throwable)
			{
			}

			foreach ($pendingEntries as $entry)
			{
				if ($commitResult?->isSuccess() === true)
				{
					try
					{
						$this->getFileUploader()->deleteTemporaryFile($entry['fileId']);
					}
					catch (\Throwable)
					{
					}

					continue;
				}

				try
				{
					($this->removePending)($entry['token'], $entry['fileId']);
				}
				catch (\Throwable)
				{
				}
			}
		}

		if (!$hasActualFileIds)
		{
			return;
		}

		foreach ($plans as $plan)
		{
			$fieldName = $plan['request']->fieldName;
			$actualFileIdSet = $actualFileIdSetByField[$fieldName] ?? [];
			foreach ($plan['currentFileIds'] as $currentFileId)
			{
				if (isset($actualFileIdSet[$currentFileId]))
				{
					continue;
				}

				try
				{
					$this->getFileUploader()->deleteFilePersistently($currentFileId);
				}
				catch (\Throwable)
				{
				}
			}
		}
	}

	/**
	 * @param list<array{request: FileFieldWriteRequest, currentFileIds: list<int>}> $plans
	 *
	 * @return array<string, list<int>>|false|null
	 */
	private function loadActualFileIds(object $observer, int $entityTypeId, array $plans): array|false|null
	{
		if (!method_exists($observer, 'hasEnteredLaunch') || !$observer->hasEnteredLaunch())
		{
			return null;
		}
		$itemId = $observer->getItemId();
		if ($itemId === null)
		{
			return null;
		}

		$fieldNames = array_values(array_map(
			static fn(array $plan): string => $plan['request']->fieldName,
			$plans,
		));

		try
		{
			$loaded = ($this->actualFileIdsLoader)($entityTypeId, $itemId, $fieldNames);
		}
		catch (\Throwable)
		{
			return false;
		}
		if (!is_array($loaded))
		{
			return false;
		}

		$actualFileIdsByField = [];
		foreach ($fieldNames as $fieldName)
		{
			$actualFileIdsByField[$fieldName] = $this->normalizeFileIds($loaded[$fieldName] ?? []);
		}

		return $actualFileIdsByField;
	}

	/**
	 * @param list<string> $fieldNames
	 *
	 * @return array<string, list<int>>
	 */
	private function loadActualFileIdsFromStorage(int $entityTypeId, int $itemId, array $fieldNames): array
	{
		$fieldNames = array_values(array_unique($fieldNames));
		$actualFileIdsByField = array_fill_keys($fieldNames, []);
		if ($fieldNames === [])
		{
			return $actualFileIdsByField;
		}

		$factory = $this->getContainer()->getFactory($entityTypeId);
		$item = $factory?->getItem($itemId, $fieldNames);
		if (!$item instanceof LegacyItem)
		{
			return $actualFileIdsByField;
		}

		foreach ($fieldNames as $fieldName)
		{
			$actualFileIdsByField[$fieldName] = $this->normalizeFileIds($item->get($fieldName));
		}

		return $actualFileIdsByField;
	}

	/**
	 * @param array{token: string, fileId: int, state: int} $entry
	 */
	private function cleanupLedgerEntry(array $entry): void
	{
		if ($entry['state'] < self::STATE_CRM_REGISTER_INTENT)
		{
			try
			{
				($this->removePending)($entry['token'], $entry['fileId']);
			}
			catch (\Throwable)
			{
			}

			return;
		}

		try
		{
			$this->getFileUploader()->markFileAsTemporary($entry['fileId']);
		}
		catch (\Throwable)
		{
		}

		$closed = false;
		try
		{
			$closed = ($this->makePersistent)($entry['token'], $entry['fileId'])->isSuccess();
		}
		catch (\Throwable)
		{
		}

		try
		{
			$this->getFileUploader()->deleteTemporaryFile($entry['fileId']);
		}
		catch (\Throwable)
		{
		}

		if (!$closed)
		{
			try
			{
				($this->removePending)($entry['token'], $entry['fileId']);
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * @return list<int>
	 */
	private function normalizeFileIds(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$fileIds = [];
		foreach ($values as $fileId)
		{
			if (is_string($fileId) && preg_match('/\\A[0-9]+\\z/', $fileId) === 1)
			{
				$fileId = (int)$fileId;
			}
			if (is_int($fileId) && $fileId > 0)
			{
				$fileIds[$fileId] = $fileId;
			}
		}

		return array_values($fileIds);
	}

	private function getObserverResultViolation(object $observer, Result $operationResult): ?SystemException
	{
		if (
			$observer instanceof ItemOperationObserver
			&& $observer->getResult() !== $operationResult
		)
		{
			return new SystemException('Item operation observer did not record the launch outcome.');
		}

		return null;
	}

	private function closure(?callable $callable, \Closure $default): \Closure
	{
		return $callable === null ? $default : \Closure::fromCallable($callable);
	}

	private function getContainer(): Container
	{
		return $this->container ??= Container::getInstance();
	}

	private function getFileUploader(): FileUploader
	{
		return $this->fileUploader ??= $this->getContainer()->getFileUploader();
	}

	private function getFileAccessChecker(): FileAccessChecker
	{
		return $this->fileAccessChecker ??= new FileAccessChecker();
	}

	private function getFileUploadGateway(): FileUploadGateway
	{
		return $this->fileUploadGateway ??= new FileUploadGateway();
	}

	private function getFileFinalStateApplier(): FileFinalStateApplier
	{
		return $this->fileFinalStateApplier ??= new FileFinalStateApplier();
	}

	private function getSystemFileFieldHandlerRegistry(): SystemFileFieldHandlerRegistry
	{
		return $this->systemFileFieldHandlerRegistry ??= new SystemFileFieldHandlerRegistry();
	}
}
