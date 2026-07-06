<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Mapping\App;

use Bitrix\Rest\Internal\Entity\Application\AppScopeRequest;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Realisation\Dto\App\ScopeRequestDto;

final class ScopeRequestMapper extends Mapper
{
	private ScopeRequestStatusMapper $scopeRequestStatusMapper;

	public function __construct()
	{
		$this->scopeRequestStatusMapper = new ScopeRequestStatusMapper();
	}

	private static array $appScopeRequestFields = [
		'id',
		'status',
		'scopes',
		'currentState',
		'history',
		'createdAt',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ScopeRequestDto::class);
		foreach ($items as $item)
		{
			if ($item instanceof AppScopeRequest)
			{
				$dto = $this->mapAppScopeRequest($item, $fields);
			}
			else
			{
				$dto = new ScopeRequestDto();
			}
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapAppScopeRequest(AppScopeRequest $item, array $fields)
	{
		$dto = new ScopeRequestDto();
		$dto->id = $item->getId();

		$emptyFields = empty($fields);

		foreach (self::$appScopeRequestFields as $entityField)
		{
			if ($emptyFields || in_array($entityField, $fields, true))
			{
				switch ($entityField)
				{
					case 'status':
						$dto->{$entityField} = $item->getCurrentState()->getStatus()->value;
						break;
					case 'scopes':
						$dto->{$entityField} = $item->getScopes();
						break;
					case 'currentState':
						$dto->{$entityField} = $this->scopeRequestStatusMapper->mapAppScopeRequestState($item->getCurrentState());
						break;
					case 'createdAt':
						$dto->{$entityField} = $item->getDateCreate();
						break;
					default:
						break;
				}
			}
		}

		return $dto;
	}
}
