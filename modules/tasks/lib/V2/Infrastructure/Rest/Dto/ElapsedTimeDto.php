<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;

class ElapsedTimeDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?int $userId;
	public ?int $taskId;
	public ?int $minutes;
	public ?int $seconds;
	public ?string $source;
	public ?string $text;
	public ?int $createdAtTs;
	public ?int $startTs;
	public ?int $stopTs;

	public static function fromEntity(?ElapsedTime $elapsedTime, ?Request $request = null): ?self
	{
		if (!$elapsedTime)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $elapsedTime->id;
		}
		if (empty($select) || in_array('userId', $select, true))
		{
			$dto->userId = $elapsedTime->userId;
		}
		if (empty($select) || in_array('taskId', $select, true))
		{
			$dto->taskId = $elapsedTime->taskId;
		}
		if (empty($select) || in_array('minutes', $select, true))
		{
			$dto->minutes = $elapsedTime->minutes;
		}
		if (empty($select) || in_array('seconds', $select, true))
		{
			$dto->seconds = $elapsedTime->seconds;
		}
		if (empty($select) || in_array('source', $select, true))
		{
			$dto->source = $elapsedTime->source?->value;
		}
		if (empty($select) || in_array('text', $select, true))
		{
			$dto->text = $elapsedTime->text;
		}
		if (empty($select) || in_array('createdAtTs', $select, true))
		{
			$dto->createdAtTs = $elapsedTime->createdAtTs;
		}
		if (empty($select) || in_array('startTs', $select, true))
		{
			$dto->startTs = $elapsedTime->startTs;
		}
		if (empty($select) || in_array('stopTs', $select, true))
		{
			$dto->stopTs = $elapsedTime->stopTs;
		}

		return $dto;
	}
}
