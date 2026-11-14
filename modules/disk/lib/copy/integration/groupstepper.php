<?php
namespace Bitrix\Disk\Copy\Integration;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\Audit\AuditActionContextResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\Audit\AuditRightsChangeAuditService;
use Bitrix\Disk\Internal\Service\Audit\AuditRightsEventProducer;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Public\Event\Audit\AuditRightsEvent;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Update\Stepper;

class GroupStepper extends Stepper
{
	protected static $moduleId = "disk";

	protected $queueName = "DiskGroupQueue";
	protected $checkerName = "DiskGroupChecker_";
	protected $baseName = "DiskGroupStepper_";
	protected $errorName = "DiskGroupError_";

	/**
	 * Executes some action, and if return value is false, agent will be deleted.
	 * @param array $option Array with main data to show if it is necessary like {steps : 35, count : 7},
	 * where steps is an amount of iterations, count - current position.
	 * @return boolean
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function execute(array &$option)
	{
		if (!Loader::includeModule(self::$moduleId))
		{
			return false;
		}

		try
		{
			$queue = $this->getQueue();
			$this->setQueue($queue);
			$queueOption = $this->getOptionData($this->baseName);
			if (empty($queueOption))
			{
				$this->deleteQueueOption();
				return !$this->isQueueEmpty();
			}

			$groupId = (int)($queueOption['groupId'] ?? 0);
			$copiedGroupId = (int)($queueOption['copiedGroupId'] ?? 0);
			$errorOffset = (int)($queueOption['errorOffset'] ?? 0);

			$storage = Driver::getInstance()->getStorageByGroupId($groupId);
			$targetStorage = Driver::getInstance()->getStorageByGroupId($copiedGroupId);
			if (!$storage || !$targetStorage)
			{
				$this->deleteQueueOption();
				return !$this->isQueueEmpty();
			}

			$rootFolder = $storage->getRootObject();
			$targetRootFolder = $targetStorage->getRootObject();

			$limit = 5;
			$offset = max(
				(int)($queueOption['processedOffset'] ?? 0),
				$this->getOffset($targetRootFolder) + $errorOffset,
			);

			$executiveUserId = (int)($queueOption['executiveUserId'] ?? 0) ?: SystemUser::SYSTEM_USER_ID;
			$mapFolderIds = $queueOption['mapFolderIds'] ?? [];
			$mapIdsCopiedFiles = $queueOption['mapIdsCopiedFiles'] ?? [];
			if (array_is_list($mapIdsCopiedFiles))
			{
				$mapIdsCopiedFiles = [];
			}
			if (empty($queueOption['errorBatchesStored']))
			{
				$failedFileIds = $queueOption['failedFileIds'] ?? [];
				foreach ($mapIdsCopiedFiles as $fileId => $copiedFileId)
				{
					if (!$copiedFileId)
					{
						$failedFileIds[] = (int)$fileId;
					}
				}
				foreach (array_chunk(array_values(array_unique($failedFileIds)), $limit) as $index => $failedIds)
				{
					$this->saveErrorBatch('0_legacy_' . $index, $failedIds);
				}
				$queueOption['errorBatchesStored'] = true;
				unset($queueOption['failedFileIds']);
			}
			if (empty($queueOption['auditOperationId']))
			{
				$queueOption['auditOperationId'] = AuditOperationContextStack::createOperationId();
				$queueOption['mapIdsCopiedFiles'] = $mapIdsCopiedFiles;
				$this->saveQueueOption($queueOption);
			}

			$sourceFileIds = $this->getFileIds($rootFolder);
			$count = count($sourceFileIds);
			$fileIds = [];
			$nextOffset = $offset;
			while ($nextOffset < $count && count($fileIds) < $limit)
			{
				$fileId = $sourceFileIds[$nextOffset++];
				if (array_key_exists($fileId, $mapIdsCopiedFiles))
				{
					continue;
				}
				$fileIds[] = $fileId;
			}

			if (!$fileIds || !$mapFolderIds)
			{
				$this->onAfterCopy($queueOption);
				$this->deleteQueueOption();
				return !$this->isQueueEmpty();
			}

			$mapIdsCopiedFiles = [];
			$failedFileIds = [];
			foreach ($fileIds as $fileId)
			{
				try
				{
					$file = File::loadById($fileId);
					if (!$file)
					{
						$queueOption['errorOffset'] = ++$errorOffset;
						$mapIdsCopiedFiles[$fileId] = 0;
						$failedFileIds[] = (int)$fileId;
						continue;
					}
					$folderId = $this->getFolderId($file);
					$targetFolderId = $mapFolderIds[$folderId] ?? 0;
					$targetFolder = Folder::loadById($targetFolderId);
					$copiedFile = $targetFolder ? $file->copyTo($targetFolder, $executiveUserId) : null;
					if (!$copiedFile || $file->getErrors())
					{
						$queueOption['errorOffset'] = ++$errorOffset;
						$mapIdsCopiedFiles[$fileId] = 0;
						$failedFileIds[] = (int)$fileId;
					}
					else
					{
						$this->setFileRights(
							$groupId, $copiedGroupId, $file, $copiedFile,
							$queueOption['auditOperationId'], $executiveUserId,
						);
						$mapIdsCopiedFiles[$fileId] = (int)$copiedFile->getId();
					}
				}
				catch (\Throwable $exception)
				{
					$this->deleteQueueOption();
					return false;
				}
			}
			$queueOption['processedOffset'] = $nextOffset;
			$queueOption['mapIdsCopiedFiles'] = $mapIdsCopiedFiles;
			if ($failedFileIds !== [])
			{
				$this->saveErrorBatch('1_batch_' . $offset, $failedFileIds);
			}
			$this->saveQueueOption($queueOption);

			$option["count"] = $count;
			$option["steps"] = $offset;

			return true;
		}
		catch (\Exception $exception)
		{
			$this->writeToLog($exception);
			$this->deleteQueueOption();
			return false;
		}
	}

	private function onAfterCopy(array $queueOption)
	{
		$this->saveErrorOption();
	}

	private function saveErrorBatch(string $name, array $failedFileIds): void
	{
		Option::set($this->getErrorBatchModuleId(), $name, serialize($failedFileIds), '');
	}

	private function getErrorBatchModuleId(): string
	{
		return strtolower(static::$moduleId . '.' . $this->errorName);
	}

	private function saveErrorOption(): void
	{
		$batches = Option::getForModule($this->getErrorBatchModuleId(), '');
		ksort($batches, SORT_NATURAL);
		$mapIdsWithErrors = [];
		foreach ($batches as $batch)
		{
			foreach (unserialize($batch, ['allowed_classes' => false]) as $fileId)
			{
				$mapIdsWithErrors[$fileId] = (int)$fileId;
			}
		}

		if ($mapIdsWithErrors)
		{
			Option::set(self::$moduleId, $this->errorName, serialize(array_values($mapIdsWithErrors)));
		}
	}

	private function getFolderId(File $file)
	{
		$folderId = $file->getParentId();
		if (empty($folderId))
		{
			$storage = $file->getStorage();
			$folderId = $storage->getRootObject()->getId();
		}
		return $folderId;
	}

	private function getFileIds(?Folder $rootFolder): array
	{
		$fileIds = [];

		$securityContext = Driver::getInstance()->getFakeSecurityContext();

		$params = ["select" => ["*", "HAS_SUBFOLDERS"]];
		foreach ($rootFolder->getChildren($securityContext, $params) as $child)
		{
			if ($child instanceof Folder)
			{
				if ($child->getChildren($securityContext))
				{
					$fileIds = array_merge($fileIds, $this->getFileIds($child));
				}
			}
			elseif ($child instanceof File)
			{
				$fileIds[] = $child->getId();
			}
		}

		return $fileIds;
	}

	private function setFileRights(
		int $groupId,
		int $copiedGroupId,
		BaseObject $sourceFile,
		BaseObject $copiedFile,
		?string $operationId = null,
		?int $executiveUserId = null,
	): void
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		$sourceRights = $rightsManager->getSpecificRights($sourceFile);

		$newRights = [];
		foreach	($sourceRights as $right)
		{
			unset($right['ID']);

			$right['OBJECT_ID'] = $copiedFile->getId();

			$right['ACCESS_CODE'] = $this->prepareAccessCodeByCopiedGroup(
				$groupId,
				$copiedGroupId,
				$right['ACCESS_CODE']
			);

			$newRights[] = $right;
		}

		AuditTransaction::run(static function (AuditTransaction $owner) use (
			$rightsManager, $copiedFile, $newRights, $operationId, $executiveUserId, $groupId, $copiedGroupId,
		): bool {
			$audit = new AuditRightsChangeAuditService(producer: new AuditRightsEventProducer(
				sender: static function (AuditRightsEvent $event) use ($groupId, $copiedGroupId): Result {
					$event = new AuditRightsEvent(
						eventCode: $event->eventCode, operationId: $event->operationId, source: $event->source,
						actionContext: $event->actionContext, targetType: $event->targetType, targetId: $event->targetId,
						targetObject: $event->targetObject, subject: $event->subject,
						details: $event->details + ['sourceGroupId' => $groupId, 'targetGroupId' => $copiedGroupId],
						occurredAt: $event->occurredAt,
						objectSnapshot: $event->objectSnapshot,
					);

					return (new AuditRightsEventProducer())->publishEvents([$event]);
				},
			));
			$before = $audit->capture($copiedFile);
			$context = null;
			AuditCaptureService::runFailOpen(static function () use (
				$operationId, $executiveUserId, $copiedGroupId, &$context,
			): Result {
				$context = AuditOperationContext::create(
					operationId: $operationId,
					actionContext: (new AuditActionContextResolver())->resolveForSystemActor($executiveUserId, [
						'type' => 'system', 'entityType' => 'group_copy', 'entityId' => $copiedGroupId,
					]),
				);

				return new Result();
			});
			if (!$rightsManager->set($copiedFile, $newRights, $owner, isServiceEdit: true))
			{
				return false;
			}
			if ($context !== null)
			{
				$audit->schedule($copiedFile, $before, $context, $owner);
			}

			return true;
		});
	}

	private function prepareAccessCodeByCopiedGroup(
		int $groupId,
		int $copiedGroupId,
		string $accessCode
	): string
	{
		if (mb_substr($accessCode, 0, 2) === 'SG')
		{
			[$code,] = explode('_', $accessCode);
			if ($groupId == mb_substr($code, 2))
			{
				$accessCode = str_replace($groupId, $copiedGroupId, $accessCode);
			}
		}

		return $accessCode;
	}

	private function getOffset(?Folder $targetRootFolder): int
	{
		$fileIds = $this->getFileIds($targetRootFolder);
		return count($fileIds);
	}

	protected function getQueue(): array
	{
		return $this->getOptionData($this->queueName);
	}

	protected function setQueue(array $queue): void
	{
		$queueId = (string) current($queue);
		$this->checkerName = (mb_strpos($this->checkerName, $queueId) === false ?
			$this->checkerName.$queueId : $this->checkerName);
		$this->baseName = (mb_strpos($this->baseName, $queueId) === false ?
			$this->baseName.$queueId : $this->baseName);
		$this->errorName = (mb_strpos($this->errorName, $queueId) === false ?
			$this->errorName.$queueId : $this->errorName);
	}

	protected function getQueueOption()
	{
		return $this->getOptionData($this->baseName);
	}

	protected function saveQueueOption(array $data)
	{
		Option::set(static::$moduleId, $this->baseName, serialize($data));
	}

	protected function deleteQueueOption()
	{
		$queue = $this->getQueue();
		$this->setQueue($queue);
		Option::delete($this->getErrorBatchModuleId());
		$this->deleteCurrentQueue($queue);
		Option::delete(static::$moduleId, ["name" => $this->checkerName]);
		Option::delete(static::$moduleId, ["name" => $this->baseName]);
	}

	protected function deleteCurrentQueue(array $queue): void
	{
		$queueId = current($queue);
		$currentPos = array_search($queueId, $queue);
		if ($currentPos !== false)
		{
			unset($queue[$currentPos]);
			Option::set(static::$moduleId, $this->queueName, serialize($queue));
		}
	}

	protected function isQueueEmpty()
	{
		$queue = $this->getOptionData($this->queueName);
		return empty($queue);
	}

	protected function getOptionData($optionName)
	{
		$option = Option::get(static::$moduleId, $optionName);
		$option = ($option !== "" ? unserialize($option, ['allowed_classes' => false]) : []);
		return (is_array($option) ? $option : []);
	}

	protected function deleteOption($optionName)
	{
		Option::delete(static::$moduleId, ["name" => $optionName]);
	}
}
