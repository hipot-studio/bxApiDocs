<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\Catalog;

use Bitrix\Main;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Auth\PairedIssuerResolver;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\CommandRunner;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog\CatalogItemDto;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\AddRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\DeleteRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\UpdateRequest;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\CreateCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\DeleteCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\UpdateCatalogItemCommand;

#[DtoType(CatalogItemDto::class)]
final class Item extends RestController
{
	use CommandRunner;

	private readonly PairedIssuerResolver $pairedIssuerResolver;

	public function __construct(
		?Main\Request $request = null,
		?PairedIssuerResolver $pairedIssuerResolver = null,
	)
	{
		parent::__construct($request);
		$this->pairedIssuerResolver = $pairedIssuerResolver ?? new PairedIssuerResolver();
	}

	#[Description('Creates a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	public function addAction(AddRequest $request): AddResponse
	{
		$userId = $this->getCurrentUserId();
		$result = $this->runCommand(new CreateCatalogItemCommand(
			userId: $userId,
			title: $request->title,
			type: $request->type,
			accessType: $request->accessType,
			description: $request->description,
			editUrl: $this->getOptionalRequestValue($request, 'editUrl'),
			viewUrl: $this->getOptionalRequestValue($request, 'viewUrl'),
			iconUrl: $this->getOptionalRequestValue($request, 'iconUrl'),
			chatId: $this->getOptionalRequestValue($request, 'chatId'),
			externalId: $this->getOptionalRequestValue($request, 'externalId'),
			iss: $this->pairedIssuerResolver->resolve($this->getServer(), $userId),
		));
		$item = $result->getData()['item'];

		return new AddResponse((int)$item->getId());
	}

	#[Description('Updates editable fields of a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	public function updateAction(UpdateRequest $request): UpdateResponse
	{
		$request->fields->convertToDto();
		$result = $this->runCommand(new UpdateCatalogItemCommand(
			userId: $this->getCurrentUserId(),
			catalogItemId: $request->catalogItemId,
			fields: $request->fields->getItems(),
		));

		return new UpdateResponse($result->isSuccess());
	}

	#[Description('Deletes a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		$result = $this->runCommand(new DeleteCatalogItemCommand(
			userId: $this->getCurrentUserId(),
			catalogItemId: $request->catalogItemId,
		));

		return new DeleteResponse($result->isSuccess());
	}

	private function getCurrentUserId(): int
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			throw new AccessDeniedException();
		}

		return $userId;
	}

	private function getOptionalRequestValue(AddRequest $request, string $propertyName): mixed
	{
		return $request->{$propertyName} ?? null;
	}
}
