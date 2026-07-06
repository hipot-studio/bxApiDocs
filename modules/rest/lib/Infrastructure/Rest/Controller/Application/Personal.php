<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller\Application;

use Bitrix\Main;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\Infrastructure\Rest\Controller\CommandRunner;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AppDto;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AppDtoMapper;
use Bitrix\Rest\Infrastructure\Rest\Request\InstallApplicationRequest;
use Bitrix\Rest\Infrastructure\Rest\Response\Application\InstallAppResponse;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\Public\Command\Application\InstallPersonalAppCommand;
use Bitrix\Rest\Public\Command\Application\UninstallPersonalAppCommand;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;

#[DtoType(AppDto::class)]
final class Personal extends RestController
{
	use CommandRunner;

	private AppDtoMapper $mapper;

	public function __construct(?Main\Request $request = null)
	{
		parent::__construct($request);
		$this->mapper = new AppDtoMapper();
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_PERSONAL_INSTALLACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Request example:
		{
			"title": "Test application",
			"handlerUrl": "https://example.com",
			"scopes": ["crm"],
			"mobile": false,
			"menuTitles": {
				"en": "Test application"
			}
		}
		If `menuTitles` is omitted, the application is installed as API-only: 
		it is not shown in the Bitrix24 interface, and its only way to access the portal is through the REST API, 
		but it can still add embeddings.
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function installAction(
		InstallApplicationRequest $request,
		ApplicationProvider $applicationProvider,
	): InstallAppResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();

		$result = $this->runCommand(new InstallPersonalAppCommand(
			userId: $userId,
			appClientId: $request->clientId,
			title: $request->title,
			scopes: $request->scopes,
			handlerUrl: $request->handlerUrl,
			installUrl: '',
			onlyApi: empty($request->menuTitles),
			mobile: $request->mobile,
			menuTitles: $request->menuTitles,
			attributes: $request->attributes,
			applicationToken: empty($request?->applicationToken) ? null : (string)$request->applicationToken,
		));

		$resultApp = reset($result->getData());

		$oauthToken = $applicationProvider->getOAuthToken(
			clientId: $resultApp->getClientId(),
			scope: $resultApp->getScope(),
			userId: $userId,
		);

		return new InstallAppResponse(
			app: $this->mapper->toDto($resultApp, includeSecret: true),
			oauthToken: $this->mapper->toOAuthTokenDto($oauthToken),
		);
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_PERSONAL_UNINSTALLACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491"
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function uninstallAction(
		string $clientId,
	): DeleteResponse
	{
		$this->runCommand(new UninstallPersonalAppCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			clientId: $clientId,
		));

		return new DeleteResponse();
	}
}
