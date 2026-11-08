<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller\Userfieldconfig;

use Bitrix\Intranet\ActionFilter;
use Bitrix\Main\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Main\Infrastructure\Rest\Controller\UserFieldConfigFacadeTrait;
use Bitrix\Main\Infrastructure\Rest\Dto\UserFieldTypeDto;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(UserFieldTypeDto::class)]
class Type extends AbstractController
{
	use UserFieldConfigFacadeTrait;

	protected function getDefaultPreFilters(): array
	{
		$defaultPreFilters = parent::getDefaultPreFilters();

		if (Loader::includeModule('intranet'))
		{
			$defaultPreFilters[] = new ActionFilter\IntranetUser();
		}

		return $defaultPreFilters;
	}

	#[Scope('userfieldconfig')]
	public function listAction(ListRequest $request, string $moduleId): ListResponse
	{
		$result = $this->executeFacade(
			fn() => $this->getFacade()->getTypes($moduleId, $this->getAuthScopes()),
		);

		$items = LegacyQueryConverter::sliceByPagination(
			$result->getData()['types'],
			$request->pagination,
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection(
				$items,
				$request->select?->getStructuredList() ?? [],
			),
		);
	}
}
