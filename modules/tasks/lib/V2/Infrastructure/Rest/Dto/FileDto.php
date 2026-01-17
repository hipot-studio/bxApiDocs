<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\File;

class FileDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?string $src;
	public ?string $name;
	public ?int $width;
	public ?int $height;
	public ?int $size;
	public ?string $subDir;
	public ?string $contentType;
	public ?array $file;

	public static function fromEntity(?File $file, ?Request $request = null): ?self
	{
		if (!$file)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $file->id;
		}
		if (empty($select) || in_array('src', $select, true))
		{
			$dto->src = $file->src;
		}
		if (empty($select) || in_array('name', $select, true))
		{
			$dto->name = $file->name;
		}
		if (empty($select) || in_array('width', $select, true))
		{
			$dto->width = $file->width;
		}
		if (empty($select) || in_array('height', $select, true))
		{
			$dto->height = $file->height;
		}
		if (empty($select) || in_array('size', $select, true))
		{
			$dto->size = $file->size;
		}
		if (empty($select) || in_array('subDir', $select, true))
		{
			$dto->subDir = $file->subDir;
		}
		if (empty($select) || in_array('contentType', $select, true))
		{
			$dto->contentType = $file->contentType;
		}
		if (empty($select) || in_array('file', $select, true))
		{
			$dto->file = $file->file;
		}

		return $dto;
	}
}
