<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\ResultDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\UserDto;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\ResultCollection;

class ResultDtoMapper
{
	private const ADD_REQUEST_ALLOWED_FIELDS = ['taskId', 'text'];

	public function mapToEntityForAddRequest(ResultDto $dto): Result
	{
		return $this->mapToEntity($dto, self::ADD_REQUEST_ALLOWED_FIELDS);
	}

	public function mapToEntity(ResultDto $dto, ?array $allowedFields = null): Result
	{
		$dtoArray = $dto->toArray(true);

		$data = [];
		foreach ($dtoArray as $key => $value)
		{
			if ($allowedFields !== null && !in_array($key, $allowedFields, true))
			{
				continue;
			}

			$data[$key] = $value;
		}

		return Result::mapFromArray($data);
	}

	public function mapToDto(?Result $result, ?Request $request = null): ?ResultDto
	{
		if (!$result)
		{
			return null;
		}

		$dto = new ResultDto();

		$select = $request?->select?->getList(true) ?? [];

		$dto->id = $result->id ?? null;

		if (empty($select) || in_array('taskId', $select, true))
		{
			$dto->taskId = $result->taskId !== 0 ? $result->taskId : null;
		}

		if (empty($select) || in_array('text', $select, true))
		{
			$dto->text = $result->text ?? null;
		}

		if (empty($select) || in_array('authorId', $select, true))
		{
			$author = $result->author ?? null;
			$relation = $request?->getRelation('author')?->getRequest();

			$author = UserDto::fromEntity($author, $relation);

			$dto->authorId = $author->id ?? null;
		}

		if (in_array('author', $select, true)) {
			$author = $result->author ?? null;
			$relation = $request?->getRelation('author')?->getRequest();

			$dto->author = UserDto::fromEntity($author, $relation);
		}

		if ($request?->getRelation('author') !== null)
		{
			$author = $result->author ?? null;
			$relation = $request?->getRelation('author')?->getRequest();

			$dto->author = UserDto::fromEntity($author, $relation);
		}

		if (empty($select) || in_array('createdAt', $select, true))
		{
			$dto->createdAt = $result->createdAtTs ? DateTime::createFromTimestamp($result->createdAtTs) : null;
		}

		if (empty($select) || in_array('updatedAt', $select, true))
		{
			$dto->updatedAt = $result->updatedAtTs ? DateTime::createFromTimestamp($result->updatedAtTs) : null;
		}

		if (empty($select) || in_array('status', $select, true))
		{
			$dto->status = $result->status?->value ?? null;
		}

		if (empty($select) || in_array('fileIds', $select, true))
		{
			$dto->fileIds = $result->fileIds ?? null;
		}

		if (empty($select) || in_array('rights', $select, true))
		{
			$dto->rights = $result->rights ?? [];
		}

		if (empty($select) || in_array('messageId', $select, true))
		{
			$dto->messageId = $result->messageId !== 0 ? $result->messageId : null;
		}

		return $dto;
	}

	/**
	 * @throws SystemException
	 */
	public function mapToDtoCollection(ResultCollection $collection, Request $request): DtoCollection
	{
		$dtoCollection = new DtoCollection(ResultDto::class);

		foreach ($collection as $entity)
		{
			$dtoCollection->add($this->mapToDto($entity, $request));
		}

		return $dtoCollection;
	}
}
