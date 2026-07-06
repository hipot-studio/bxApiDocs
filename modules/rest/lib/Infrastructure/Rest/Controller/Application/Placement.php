<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller\Application;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\Infrastructure\Rest\Dto\Placement\PlacementDto;
use Bitrix\Rest\Infrastructure\Rest\Exception\ApplicationNotFoundException;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Placement\PlacementListRequest;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use Bitrix\Rest\Public\Provider\PlacementProvider;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use CRestUtil;

#[DtoType(PlacementDto::class)]
class Placement extends RestController
{
	private ?AppAccessChecker $accessChecker = null;

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_PLACEMENT_LISTACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		If `scope` is provided, only placements for that scope are returned, and other parameters are ignored.
		If `showAll` is `true`, all available placements are returned.
		If `clientId` is provided, only placements available to that application are returned.
		If no parameters are provided, all available placements are returned.
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491"
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function listAction(
		PlacementListRequest $request,
		PlacementProvider $placementProvider,
		ApplicationProvider $appProvider,
	): ArrayResponse
	{
		if (!empty($request->scope))
		{
			return $this->makeResponse($placementProvider->getList([$request->scope]));
		}

		if (empty($request->clientId) || $request->showAll)
		{
			return $this->makeResponse($placementProvider->getAll());
		}

		$app = $appProvider->getByClientId(
			$this->getCurrentUserId(),
			$request->clientId,
		);

		if (!$app)
		{
			throw new ApplicationNotFoundException($request->clientId);
		}

		if (!$this->getAccessChecker()->canViewPlacementList($app))
		{
			throw new AccessDeniedException();
		}

		$scopes = $app->getScope();
		$scopes[] = CRestUtil::GLOBAL_SCOPE;

		return $this->makeResponse($placementProvider->getList($scopes));
	}

	private function makeResponse(array $result): ArrayResponse
	{
		return new ArrayResponse(array_keys($result));
	}

	private function getCurrentUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	private function getAccessChecker(): AppAccessChecker
	{
		return $this->accessChecker ??= new AppAccessChecker($this->getCurrentUserId());
	}
}
