<?php

namespace Bitrix\Bizproc\Internal\Integration\Rag\Dto;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Bizproc\Internal\Integration\Rag\FileStatus;
use Bitrix\Main\Type\Contract\Arrayable;

class KnowledgeBaseFileStatusDtoCollection extends AbstractCollection implements Arrayable
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof KnowledgeBaseFileStatusDto;
	}

	public function toArray(): array
	{
		return array_map(static fn(KnowledgeBaseFileStatusDto $item) => $item->toArray(), $this->items);
	}

	public function getFileIds(): array
	{
		return array_map(fn(KnowledgeBaseFileStatusDto $fileDto) => $fileDto->fileId, $this->items);
	}

	public function getStatus(): ?FileStatus
	{
		if (empty($this->items))
		{
			return null;
		}

		return array_reduce(
			$this->items,
			function (?FileStatus $carry, KnowledgeBaseFileStatusDto $item): ?FileStatus
			{
				$status = $item->status;

				if ($status === null)
				{
					return $carry;
				}

				if ($carry === null)
				{
					return $status;
				}

				return $status->getPriority() < $carry->getPriority()
					? $status
					: $carry
				;
			}
		);
	}
}