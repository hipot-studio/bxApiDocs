<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;

/**
 * Resolves the objects the links of a set stand for, in one query for the whole set. A link owns neither
 * a stored file nor a unique code of its own: both belong to the object behind it, and asking the link for
 * either resolves that object anew, which would cost a query per listed link.
 *
 * One resolver serves the listing and the action, so that both judge an item by the same object.
 */
class RealObjectResolver
{
	/** @var array<int, File> Resolved object, by its own id. */
	private array $objectsById = [];

	/**
	 * @param array<BaseObject> $objects
	 */
	public function warmUp(array $objects): void
	{
		$realObjectIds = [];
		foreach ($objects as $object)
		{
			if (!$object instanceof File || !$object->isLink())
			{
				continue;
			}

			$realObjectId = (int)$object->getRealObjectId();
			if ($realObjectId > 0 && !isset($this->objectsById[$realObjectId]))
			{
				$realObjectIds[$realObjectId] = $realObjectId;
			}
		}

		if (empty($realObjectIds))
		{
			return;
		}

		foreach ($this->loadRealObjects(array_values($realObjectIds)) as $realObject)
		{
			$this->objectsById[(int)$realObject->getId()] = $realObject;
		}
	}

	/**
	 * The object a link stands for, when the warm up resolved it. Everything else answers null: a plain
	 * file is its own source, and a link left out of the warm up is left to the model.
	 */
	public function resolve(File $file): ?File
	{
		if (!$file->isLink())
		{
			return null;
		}

		return $this->objectsById[(int)$file->getRealObjectId()] ?? null;
	}

	/**
	 * The whole row is selected: a model of Disk does not load a field left out of the select, so an object
	 * resolved by a narrow select would answer null for its name and its size.
	 *
	 * @param array<int, int> $realObjectIds
	 * @return array<File>
	 */
	protected function loadRealObjects(array $realObjectIds): array
	{
		$objects = BaseObject::getModelList([
			'select' => ['*'],
			'filter' => ['@ID' => $realObjectIds],
		]);

		return array_filter(
			$objects,
			static fn(BaseObject $object): bool => $object instanceof File && !$object->isLink(),
		);
	}
}
