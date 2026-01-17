<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\Gantt;

use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLink;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLinkCollection;

class GanttLinkMapper
{
	public function __construct(
		private readonly LinkTypeMapper $linkTypeMapper,
	)
	{

	}

	public function mapToCollection(array $links): GanttLinkCollection
	{
		$collection = new GanttLinkCollection();
		foreach ($links as $link)
		{
			$collection->add($this->mapToEntity($link));
		}

		return $collection;
	}

	public function mapToEntity(array $link): GanttLink
	{
		$type = $link['TYPE'] ?? null;
		if ($type !== null)
		{
			$type = $this->linkTypeMapper->mapToEnum((int)$type);
		}

		return GanttLink::mapFromArray([
			'taskId' => $link['TASK_ID'] ?? null,
			'dependentId' => $link['DEPENDS_ON_ID'] ?? null,
			'creatorId' => $link['CREATOR_ID'] ?? null,
			'type' => $type,
		]);
	}
}
