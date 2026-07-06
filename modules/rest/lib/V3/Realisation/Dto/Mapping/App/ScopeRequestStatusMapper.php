<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Mapping\App;

use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestState;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Realisation\Dto\App\ScopeRequestStatusDto;

final class ScopeRequestStatusMapper extends Mapper
{
	private static array $fieldMapping = [
		'id' => 'ID',
		'requestId' => 'REQUEST_ID',
		'status' => 'STATUS',
		'comment' => 'COMMENT',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ScopeRequestStatusDto::class);
		foreach ($items as $item)
		{
			if ($item instanceof AppScopeRequestState)
			{
				$dto = $this->mapAppScopeRequestState($item);
			}
			else
			{
				$dto = $this->mapDto($item, $fields);
			}
			$collection->add($dto);
		}

		return $collection;
	}

	public function mapAppScopeRequestState(AppScopeRequestState $appScopeRequestState): ScopeRequestStatusDto
	{
		$dto = new ScopeRequestStatusDto();
		$dto->id = $appScopeRequestState->getId();
		$dto->requestId = $appScopeRequestState->getRequestId();
		$dto->status = $appScopeRequestState->getStatus()->value;
		$dto->comment = $appScopeRequestState->getComment();
		$dto->createdAt = $appScopeRequestState->getDateCreate();

		return $dto;
	}

	private function mapDto(array $itemData, array $fields): ScopeRequestStatusDto
	{
		$dto = new ScopeRequestStatusDto();
		if (isset($itemData['ID']))
		{
			$dto->id = (int)$itemData['ID']; // id always present
		}

		$emptyFields = empty($fields);

		foreach (self::$fieldMapping as $dtoField => $dataField)
		{
			if ($emptyFields || in_array($dtoField, $fields, true))
			{
				switch ($dtoField)
				{
					default:
						$dto->{$dtoField} = $itemData[$dataField];
						break;
				}
			}
		}

		return $dto;
	}
}
