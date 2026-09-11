<?php

namespace Bitrix\Sign\Service\Sign\Document;

use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sign\Item\Document\SafeFolder;
use Bitrix\Sign\Repository\Document\SafeFolderRelationRepository;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\Document\Folder\EntityType;

/**
 * Company safe folder creation and rename. Folder rename does not move documents and does not
 * recalculate any permissions; duplicate folder names are allowed.
 */
final class SafeFolderService
{
	private readonly SafeFolderRepository $safeFolderRepository;
	private readonly SafeFolderRelationRepository $safeFolderRelationRepository;

	public function __construct(
		?SafeFolderRepository $safeFolderRepository = null,
		?SafeFolderRelationRepository $safeFolderRelationRepository = null,
	)
	{
		$container = Container::instance();
		$this->safeFolderRepository = $safeFolderRepository ?? $container->getSafeFolderRepository();
		$this->safeFolderRelationRepository = $safeFolderRelationRepository ?? $container->getSafeFolderRelationRepository();
	}

	public function create(string $title): Main\Result
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return (new Main\Result())->addError(new Main\Error('User not found'));
		}

		$folder = new SafeFolder(
			title: $title,
			createdById: $currentUserId,
			modifiedById: $currentUserId,
			dateCreate: new DateTime(),
		);

		$connection = Main\Application::getConnection();
		$connection->startTransaction();
		try
		{
			$addResult = $this->safeFolderRepository->add($folder);
			if (!$addResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return (new Main\Result())->addErrors($addResult->getErrors());
			}

			$relationResult = $this->safeFolderRelationRepository->add(
				$folder->getId(),
				EntityType::FOLDER,
				0,
				0,
				$currentUserId,
			);
			if (!$relationResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return (new Main\Result())->addErrors($relationResult->getErrors());
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			return (new Main\Result())->addError(new Main\Error($exception->getMessage()));
		}

		return (new Main\Result())->setData([
			'id' => $folder->getId(),
			'title' => $folder->title,
		]);
	}

	public function rename(int $folderId, string $newTitle): Main\Result
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return (new Main\Result())->addError(new Main\Error('User not found'));
		}

		if (trim($newTitle) === '')
		{
			return (new Main\Result())->addError(new Main\Error('Folder name cannot be empty'));
		}

		$folder = $this->safeFolderRepository->getById($folderId);
		if ($folder === null)
		{
			return (new Main\Result())->addError(new Main\Error('Folder not found'));
		}

		$folder->title = $newTitle;
		$folder->modifiedById = $currentUserId;
		$folder->dateModify = new DateTime();

		$updateResult = $this->safeFolderRepository->update($folder);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addErrors($updateResult->getErrors());
		}

		return (new Main\Result())->setData([
			'id' => $folder->getId(),
			'title' => $folder->title,
		]);
	}

	public function getById(int $folderId): ?SafeFolder
	{
		if ($folderId < 1)
		{
			return null;
		}

		return $this->safeFolderRepository->getById($folderId);
	}
}
