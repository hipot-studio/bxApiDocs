<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\Catalog\Item;

use Bitrix\Main;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\CommandRunner;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog\CatalogItemDto;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Pin\PinRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Pin\UnpinRequest;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pin\PinCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pin\UnpinCatalogItemCommand;

#[DtoType(CatalogItemDto::class)]
final class Pin extends RestController
{
	use CommandRunner;

	public function __construct(?Main\Request $request = null)
	{
		parent::__construct($request);
	}

	#[Description('Pins a catalog item for the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	public function setAction(PinRequest $request): UpdateResponse
	{
		$this->runCommand(new PinCatalogItemCommand(
			userId: $this->getCurrentUserId(),
			catalogItemId: $request->catalogItemId,
		));

		return new UpdateResponse();
	}

	#[Description('Removes the current REST user pin from a catalog item')]
	#[Scope(RestDeveloper::SCOPE)]
	public function deleteAction(UnpinRequest $request): UpdateResponse
	{
		$this->runCommand(new UnpinCatalogItemCommand(
			userId: $this->getCurrentUserId(),
			catalogItemId: $request->catalogItemId,
		));

		return new UpdateResponse();
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
}
