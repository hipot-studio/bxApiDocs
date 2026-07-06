<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AppDto;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AppDtoMapper;
use Bitrix\Rest\Infrastructure\Rest\Exception\ApplicationNotFoundException;
use Bitrix\Rest\Infrastructure\Rest\Request\AppListRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\GetClientIdRequest;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use Bitrix\Rest\Public\Provider\Params\ApplicationParams;
use Bitrix\Rest\Public\Provider\Params\ApplicationSelect;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;

#[DtoType(AppDto::class)]
final class Application extends RestController
{
	private AppDtoMapper $mapper;

	public function __construct(?Main\Request $request = null)
	{
		parent::__construct($request);
		$this->mapper = new AppDtoMapper();
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_GETBYCLIENTIDACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491"
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function getByClientIdAction(GetClientIdRequest $request, ApplicationProvider $applicationProvider): GetResponse
	{
		$application = $applicationProvider->getByClientId(
			userId: (int)$this->getCurrentUser()->getId(),
			clientId: $request->clientId,
		);
		if ($application === null)
		{
			throw new ApplicationNotFoundException(
				$request->clientId,
			);
		}

		return new GetResponse($this->mapper->toDto($application, $request));
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_LISTACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Scope(RestDeveloper::SCOPE)]
	public function listAction(AppListRequest $request, ApplicationProvider $applicationProvider): ListResponse
	{
		$appCollection = $applicationProvider->getInstalledList(
			userId: (int)$this->getCurrentUser()->getId(),
			gridParams: new ApplicationParams(
				pager: new Pager(
					limit: $request->pagination?->getLimit() ?? 20,
					offset: $request->pagination?->getOffset() ?? 0,
				),
//				filter: $request->filter,
//				sort: null,
				select: new ApplicationSelect($request->select?->getList() ?? []),
			),
			attributes: $request->attributes,
		);

		return new ListResponse($this->mapper->toDtoCollection($appCollection, $request));
	}
}
