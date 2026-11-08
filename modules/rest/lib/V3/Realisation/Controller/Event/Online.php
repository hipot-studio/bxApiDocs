<?php

namespace Bitrix\Rest\V3\Realisation\Controller\Event;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Service\Event\OnlineEventService;
use Bitrix\Rest\OAuth\Auth;
use Bitrix\Rest\Repository\Event\EventRepository;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Realisation\Dto\EventDto;
use Bitrix\Rest\V3\Realisation\Exception\Event\WrongAuthTypeException;

#[DtoType(EventDto::class)]
final class Online extends RestController
{
	private OnlineEventService $onlineEventService;

	public function __construct(?Request $request = null)
	{
		$eventRepository = ServiceLocator::getInstance()->get(EventRepository::class);
		$this->onlineEventService = new OnlineEventService($eventRepository);
		parent::__construct($request);
	}

	public function bindAction(AddRequest $request): BooleanResponse
	{
		/** @var \CRestApiServer $server */
		$server = $this->getServer();

		if ($server === null || $server->getAuthType() !== Auth::AUTH_TYPE)
		{
			throw new WrongAuthTypeException(Auth::AUTH_TYPE);
		}

		/** @var EventDto $dto */
		$dto = $request->fields->convertToDto('bind');
		$authData = $server->getAuthData();
		$clientInfo = AppTable::getByClientId($server->getClientId());

		$this->onlineEventService->bind(
			dto: $dto,
			request: $request,
			appId: (int)$clientInfo['ID'],
			userId: $this->getCurrentUser()?->getId(),
			connectorId: (string)($authData['auth_connector'] ?? ''),
			clientInfo: $clientInfo,
			scopeChecker: static fn(array $scopes): bool => (bool)$server->getAvailableScope($scopes),
		);

		return new BooleanResponse();
	}

	public function unbindAction(AddRequest $request): BooleanResponse
	{
		/** @var \CRestApiServer $server */
		$server = $this->getServer();

		if ($server === null)
		{
			throw new WrongAuthTypeException(Auth::AUTH_TYPE);
		}

		/** @var EventDto $dto */
		$dto = $request->fields->convertToDto('unbind');
		$authData = $server->getAuthData();
		$clientInfo = AppTable::getByClientId($server->getClientId());
		$appId = is_array($clientInfo) ? (int)$clientInfo['ID'] : null;

		$this->onlineEventService->unbind(
			dto: $dto,
			appId: $appId,
			userId: $this->getCurrentUser()?->getId(),
			connectorId: (string)($authData['auth_connector'] ?? ''),
		);

		return new BooleanResponse();
	}
}
