<?php

namespace Bitrix\Rest\V3\Realisation\Controller\App;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Repository\AppScopeRequestRepository;
use Bitrix\Rest\OAuth\Auth;
use Bitrix\Rest\Public\Services\AppScopeRequestService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Dto\App\ScopeRequestDto;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\App\ScopeRequestMapper;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\App\ScopeRequestStatusMapper;
use CRestServer;

#[DtoType(ScopeRequestDto::class)]
final class ScopeRequest extends RestController
{
	private AppScopeRequestRepository $scopeRequestRepository;
	private AppRepository $appRepository;

	private int $appId;

	private ScopeRequestMapper $scopeRequestMapper;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$this->appRepository = ServiceLocator::getInstance()->get(AppRepository::class);
		$this->scopeRequestRepository = ServiceLocator::getInstance()->get(AppScopeRequestRepository::class);
		$this->scopeRequestMapper = ServiceLocator::getInstance()->get(ScopeRequestMapper::class);
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function addAction(AddRequest $request): GetResponse
	{
		/** @var ScopeRequestDto $scopeRequestDto */
		$scopeRequestDto = $request->fields->convertToDto('add');
		/** @var AppScopeRequestService $appScopeRequestService */
		$appScopeRequestService = ServiceLocator::getInstance()->get(AppScopeRequestService::class);
		$appScopeRequest = $appScopeRequestService->requestScopes($this->appId, $scopeRequestDto->scopes, $scopeRequestDto->comment);
		$scopeRequestDto->id = $appScopeRequest->getId();
		$scopeRequestDto->status = $appScopeRequest->getCurrentState()->getStatus()->value;

		return new GetResponse($scopeRequestDto);
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function getAction(GetRequest $request): GetResponse
	{
		$appScopeRequest = $this->scopeRequestRepository->getByIdWithHistory($request->id);
		if (!$appScopeRequest || $appScopeRequest->getAppId() !== $this->appId)
		{
			throw new EntityNotFoundException($request->id);
		}

		/** @var ScopeRequestDto $dto */
		$dto = $this->scopeRequestMapper->mapOne($appScopeRequest);
		$scopeRequestStatusMapper = ServiceLocator::getInstance()->get(ScopeRequestStatusMapper::class);
		$historyCollection = $scopeRequestStatusMapper->mapCollection($appScopeRequest->getStateHistory());
		$dto->history = $historyCollection;

		return new GetResponse($dto);
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function listAction(ListRequest $request): ListResponse
	{
		$selectedFields = $request->select ? $request->select->getList() : [];
		$items = $this->scopeRequestRepository->getListByAppId($this->appId);

		return new ListResponse($this->scopeRequestMapper->mapCollection($items, $selectedFields));
	}

	public function setServer(?CRestServer $server = null): self
	{
		if ($server->getAuthType() !== Auth::AUTH_TYPE)
		{
			throw new AccessDeniedException();
		}

		$app = $this->appRepository->getByClientId($server->getClientId());
		if (!$app || !$app->isActive())
		{
			throw new AccessDeniedException();
		}
		$this->appId = $app->getId();

		return parent::setServer($server);
	}
}
