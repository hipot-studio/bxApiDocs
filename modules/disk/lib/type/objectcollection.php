<?php

namespace Bitrix\Disk\Type;

use Bitrix\Disk\BaseObject;

/**
 * @method BaseObject[] getIterator()
 */
final class ObjectCollection extends TypedCollection
{
	protected function __construct(BaseObject ...$baseObjects)
	{
		parent::__construct(...$baseObjects);
	}

	public static function createByUniqueCodes(string ...$uniqueCodes): self
	{
		if (empty($uniqueCodes))
		{
			return new self();
		}

		$models = BaseObject::getModelList([
			'filter' => [
				'@UNIQUE_CODE' => $uniqueCodes,
			],
		]);

		return new self(...$models);
	}

	public function getUniqueCodes(): array
	{
		$uniqueCodes = [];

		foreach ($this as $baseObject)
		{
			$uniqueCodes[] = $baseObject->getUniqueCode();
		}

		return $uniqueCodes;
	}

	protected static function getItemClass(): string
	{
		return BaseObject::class;
	}
}
