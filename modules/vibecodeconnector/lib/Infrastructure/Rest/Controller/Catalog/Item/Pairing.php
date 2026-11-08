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
use Bitrix\Rest\V3\Exception\MethodNotFoundException;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Auth\PairedIssuerResolver;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\CommandRunner;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog\CatalogItemDto;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Pairing\BindRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Response\Catalog\Item\PairingBindResponse;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pairing\BindCatalogItemPairingCommand;

#[DtoType(CatalogItemDto::class)]
final class Pairing extends RestController
{
	use CommandRunner;

	private const METHOD_NAME = 'vibecodeconnector.catalog.item.pairing.bind';

	private readonly PairedIssuerResolver $pairedIssuerResolver;

	public function __construct(
		?Main\Request $request = null,
		?PairedIssuerResolver $pairedIssuerResolver = null,
	)
	{
		parent::__construct($request);
		$this->pairedIssuerResolver = $pairedIssuerResolver ?? new PairedIssuerResolver();
	}

	/**
	 * The issuer is resolved from the calling credential, exactly as `Catalog\Item::addAction()`
	 * does it: a client supplied `iss` is never read here either.
	 */
	#[Description('Binds a catalog item of the current REST user to the pairing identity of the calling credential')]
	#[Scope(RestDeveloper::SCOPE)]
	public function bindAction(BindRequest $request): PairingBindResponse
	{
		if (!BindCatalogItemPairingCommand::isSupportedHere())
		{
			// A repair that cannot be serialised must not be offered at all: VibeCode already
			// treats a missing method as "this portal cannot repair" and keeps its own state.
			throw new MethodNotFoundException(self::METHOD_NAME);
		}

		$userId = $this->getCurrentUserId();
		$result = $this->runCommand(new BindCatalogItemPairingCommand(
			userId: $userId,
			catalogItemId: $request->catalogItemId,
			iss: $this->pairedIssuerResolver->resolve($this->getServer(), $userId),
		));
		$data = $result->getData();

		return new PairingBindResponse(
			iss: (string)$data['iss'],
			bound: (string)$data['bound'],
		);
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
