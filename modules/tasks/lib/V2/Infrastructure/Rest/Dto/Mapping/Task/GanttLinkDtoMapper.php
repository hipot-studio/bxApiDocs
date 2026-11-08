<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task;

use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\GanttLinkDto;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLink;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLinkCollection;

class GanttLinkDtoMapper
{
	public function mapToDto(?GanttLink $link, ?Request $request = null): ?GanttLinkDto
	{
		if (!$link)
		{
			return null;
		}

		$dto = new GanttLinkDto();
		$select = $request?->select?->getList(true) ?? [];

		if (empty($select) || in_array('taskId', $select, true))
		{
			$dto->taskId = $link->taskId;
		}

		if (empty($select) || in_array('dependentId', $select, true))
		{
			$dto->dependentId = $link->dependentId;
		}

		if (empty($select) || in_array('type', $select, true))
		{
			$dto->type = $link->type?->value;
		}

		if (empty($select) || in_array('creatorId', $select, true))
		{
			$dto->creatorId = $link->creatorId;
		}

		return $dto;
	}

	/**
	 * @throws SystemException
	 */
	public function mapToDtoCollection(GanttLinkCollection $collection, Request $request): DtoCollection
	{
		$dtoCollection = new DtoCollection(GanttLinkDto::class);

		foreach ($collection as $link)
		{
			$dtoCollection->add($this->mapToDto($link, $request));
		}

		return $dtoCollection;
	}
}
