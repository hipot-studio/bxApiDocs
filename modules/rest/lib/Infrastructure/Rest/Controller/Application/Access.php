<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller\Application;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\Infrastructure\Rest\Controller\CommandRunner;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AccessDto;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\AccessDtoMapper;
use Bitrix\Rest\Infrastructure\Rest\Exception\ApplicationNotFoundException;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Access\DeleteAccessRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Access\GetAccessRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Access\ResetAccessRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Access\SetAccessRequest;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\Internal\Access\App\AppAction;
use Bitrix\Rest\Internal\Access\App\AppAccessController;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Public\Command\Application\Access\DeleteAppAccessCodesCommand;
use Bitrix\Rest\Public\Command\Application\Access\ResetAppAccessCommand;
use Bitrix\Rest\Public\Command\Application\Access\SetAppAccessCommand;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(AccessDto::class)]
final class Access extends RestController
{
	use CommandRunner;

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_ACCESS_SETACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Replaces all existing access codes for the application with the provided ones.
		For personal applications, the owner user access code is always added to the saved access codes.
		Access codes define which users or groups can use the application.
		Administrators and personal application owners always have access regardless of access codes.
		For shared applications, if no access codes are set, the application is available to everyone.
		For personal applications, if no access codes are set, only the owner and administrators have access.
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491",
			"codes": ["UA", "D1"]
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function setAction(SetAccessRequest $request): UpdateResponse
	{
		$result = $this->runCommand(new SetAppAccessCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			clientId: $request->clientId,
			accessCodes: $request->codes,
		));

		return new UpdateResponse($result->isSuccess());
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_ACCESS_DELETEACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Removes specified access codes from the application.
		Only the provided codes are removed; other existing codes remain unchanged.
		Administrators and personal application owners always have access regardless of access codes.
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491",
			"codes": ["D1"]
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function deleteAction(DeleteAccessRequest $request): DeleteResponse
	{
		$result = $this->runCommand(new DeleteAppAccessCodesCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			clientId: $request->clientId,
			codesToRemove: $request->codes,
		));

		return new DeleteResponse($result->isSuccess());
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_ACCESS_RESETACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Removes all custom access codes and restores the default access settings for the application.
		For personal applications, the default is the owner and administrators only.
		For shared applications, the default is no restrictions (available to everyone).
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491"
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function resetAction(ResetAccessRequest $request): UpdateResponse
	{
		$result = $this->runCommand(new ResetAppAccessCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			clientId: $request->clientId,
		));

		return new UpdateResponse($result->isSuccess());
	}

	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_ACCESS_GETACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Returns the current access codes assigned to the application,
		including detailed information about each code (provider and display name).
		Administrators and personal application owners always have access regardless of access codes.
		For shared applications, if no access codes are set, the application is available to everyone.
		For personal applications, if no access codes are set, only the owner and administrators have access.
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491"
		}
	')]
	#[Scope(RestDeveloper::SCOPE)]
	public function getAction(
		GetAccessRequest $request,
		AppRepository $applicationRepository,
	): GetResponse
	{
		$app = $applicationRepository->getByClientId($request->clientId);

		if ($app === null)
		{
			throw new ApplicationNotFoundException($request->clientId);
		}

		$appModel = AppModel::createFromApp($app);
		$controller = AppAccessController::getInstance(
			(int)$this->getCurrentUser()->getId(),
		);

		if (!$controller->check(AppAction::ManageAppAccess, $appModel))
		{
			throw new AccessDeniedException();
		}

		$mapper = new AccessDtoMapper();

		return new GetResponse($mapper->toDto($app));
	}
}
