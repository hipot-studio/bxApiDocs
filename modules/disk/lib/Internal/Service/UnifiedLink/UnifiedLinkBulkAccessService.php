<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

readonly class UnifiedLinkBulkAccessService
{
	public const ERROR_EMPTY_OBJECT_COLLECTION = 'EMPTY_OBJECT_COLLECTION';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_OBJECTS_OUTSIDE_PARENT_FOLDER = 'OBJECTS_OUTSIDE_PARENT_FOLDER';

	/**
	 * @param UnifiedLinkAccessService $unifiedLinkAccessService
	 */
	public function __construct(
		protected UnifiedLinkAccessService $unifiedLinkAccessService,
	)
	{
	}

	/**
	 * @param Folder $parentFolder
	 * @param ObjectCollection $unifiedObjectCollection
	 * @param UnifiedLinkAccessLevel $requiredAccessLevel
	 * @param int $userId
	 * @return Result
	 */
	public function check(
		Folder $parentFolder,
		ObjectCollection $unifiedObjectCollection,
		UnifiedLinkAccessLevel $requiredAccessLevel,
		int $userId,
	): Result
	{
		$result = new Result();
		/** @var array<int, BaseObject> $objects */
		$objects = $unifiedObjectCollection->toArray();

		if (empty($objects))
		{
			return $result->addError(new Error(
				'Object collection is empty.',
				static::ERROR_EMPTY_OBJECT_COLLECTION,
			));
		}

		$accessResult = $this->unifiedLinkAccessService->checkAll(
			baseObject: $parentFolder,
			userId: $userId,
		);

		$accessLevel = $accessResult->getMaxAccessLevel();

		if ($accessLevel->value < $requiredAccessLevel->value)
		{
			return $result->addError(new Error(
				'Access level does not match the required level.',
				static::ERROR_ACCESS_DENIED,
			));
		}

		if (!$this->areDirectChildren($parentFolder, $objects))
		{
			return $result->addError(new Error(
				'Some objects are outside the parent folder.',
				static::ERROR_OBJECTS_OUTSIDE_PARENT_FOLDER,
			));
		}

		$result->setData([
			'accessResult' => $accessResult,
		]);

		return $result;
	}

	/**
	 * @param array<int, BaseObject> $objects
	 */
	protected function areDirectChildren(Folder $parentFolder, array $objects): bool
	{
		$parentFolderId = (int)$parentFolder->getId();

		foreach ($objects as $object)
		{
			if ((int)$object->getParentId() !== $parentFolderId)
			{
				return false;
			}
		}

		return true;
	}
}
