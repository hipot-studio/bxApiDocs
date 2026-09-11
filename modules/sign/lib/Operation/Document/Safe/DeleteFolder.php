<?php

namespace Bitrix\Sign\Operation\Document\Safe;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Sign\Contract\Operation as OperationContract;
use Bitrix\Sign\Exception\SignException;
use Bitrix\Sign\Repository\Document\SafeFolderRelationRepository;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Document\Folder\EntityType;

/**
 * Deletes a company safe folder. An empty folder is removed directly. A folder that still holds
 * records (members) requires an explicit target (a folder, or "No folder" = null): the records are
 * relocated first, then the folder and its own relation are removed. Records/documents are never
 * deleted. The whole flow runs in a single transaction.
 */
class DeleteFolder implements OperationContract
{
	public const ERROR_FOLDER_NOT_FOUND = 'FOLDER_NOT_FOUND';
	public const ERROR_TARGET_REQUIRED = 'NON_EMPTY_FOLDER_REQUIRES_TARGET';
	public const ERROR_SYNC_LIMIT_EXCEEDED = 'FOLDER_SYNC_LIMIT_EXCEEDED';
	private const SYNC_MEMBER_LIMIT = 1000;

	private readonly SafeFolderRepository $safeFolderRepository;
	private readonly SafeFolderRelationRepository $safeFolderRelationRepository;

	public function __construct(
		private readonly int $folderId,
		private readonly ?int $targetFolderId,
		private readonly bool $hasTargetFolder,
		?SafeFolderRepository $safeFolderRepository = null,
		?SafeFolderRelationRepository $safeFolderRelationRepository = null,
	)
	{
		$container = Container::instance();
		$this->safeFolderRepository = $safeFolderRepository ?? $container->getSafeFolderRepository();
		$this->safeFolderRelationRepository = $safeFolderRelationRepository ?? $container->getSafeFolderRelationRepository();
	}

	public function launch(): Main\Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$folderIdsToLock = [$this->folderId];
			if ($this->targetFolderId !== null && $this->targetFolderId > 0)
			{
				$folderIdsToLock[] = $this->targetFolderId;
			}

			if (!$this->safeFolderRelationRepository->lockFolderRelations($folderIdsToLock))
			{
				throw new SignException('Folder not found');
			}

			$folder = $this->safeFolderRepository->getById($this->folderId);
			if ($folder === null)
			{
				$this->rollbackTransaction($connection);

				return Result::createWithErrors(
					new Main\Error('Folder not found', self::ERROR_FOLDER_NOT_FOUND),
				);
			}

			$hasMembers = $this->safeFolderRelationRepository->hasEntitiesByParentIdAndType(
				$this->folderId,
				EntityType::MEMBER,
			);
			if ($hasMembers && !$this->hasTargetFolder)
			{
				$this->rollbackTransaction($connection);

				return Result::createWithErrors(
					new Main\Error(
						'Target folder is required for a non-empty folder',
						self::ERROR_TARGET_REQUIRED,
					),
				);
			}

			if ($hasMembers && $this->targetFolderId === $this->folderId)
			{
				$this->rollbackTransaction($connection);

				return Result::createByErrorMessage('Target folder must differ from the deleted folder');
			}

			if (
				$hasMembers
				&& $this->safeFolderRelationRepository->exceedsEntityLimitByParentIdAndType(
					$this->folderId,
					EntityType::MEMBER,
					self::SYNC_MEMBER_LIMIT,
				)
			)
			{
				$this->rollbackTransaction($connection);

				return Result::createWithErrors(
					new Main\Error(
						'Folder is too large for synchronous deletion',
						self::ERROR_SYNC_LIMIT_EXCEEDED,
					),
				);
			}

			if ($hasMembers)
			{
				$moveResult = $this->safeFolderRelationRepository->updateParentByParentId(
					$this->targetFolderId ?? 0,
					$this->folderId,
					EntityType::MEMBER,
				);
				if (!$moveResult->isSuccess())
				{
					throw new SignException('Move documents error');
				}
			}

			$deleteRelationResult = $this->safeFolderRelationRepository->deleteByEntityIdAndType(
				$this->folderId,
				EntityType::FOLDER,
			);
			if (!$deleteRelationResult->isSuccess())
			{
				throw new SignException('Delete folder relation error');
			}

			$deleteFolderResult = $this->safeFolderRepository->deleteById($this->folderId);
			if (!$deleteFolderResult->isSuccess())
			{
				throw new SignException('Delete folder error');
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$this->rollbackTransaction($connection);

			return Result::createByErrorMessage($e->getMessage());
		}

		return new Main\Result();
	}

	private function rollbackTransaction(Main\DB\Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (Main\DB\TransactionException)
		{
			// MySQL rolls back to the savepoint and then reports that nested rollbacks are unsupported.
		}
	}
}
