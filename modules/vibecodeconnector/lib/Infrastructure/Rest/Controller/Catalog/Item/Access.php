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
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog\AccessDto;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Access\SetRequest;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Access\SetCatalogItemAccessCommand;

#[DtoType(AccessDto::class)]
final class Access extends RestController
{
	use CommandRunner;

	public function __construct(?Main\Request $request = null)
	{
		parent::__construct($request);
	}

	#[Description('Replaces all ACL access codes for a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	public function setAction(SetRequest $request): UpdateResponse
	{
		$this->runCommand(new SetCatalogItemAccessCommand(
			userId: $this->getCurrentUserId(),
			catalogItemId: $request->catalogItemId,
			accessCodes: $request->accessCodes,
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
