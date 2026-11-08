<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\Catalog\Item;

use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller\CallableRunner;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog\EmbeddingDto;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog\EmbeddingDtoMapper;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Embedding\AddRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Embedding\DeleteRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Embedding\ListRequest;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\CatalogItemEmbeddingBinder;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\CatalogItemEmbeddingLister;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\CatalogItemEmbeddingUnbinder;

#[DtoType(EmbeddingDto::class)]
final class Embedding extends RestController
{
	use CallableRunner;

	#[Description('Binds an embedding to a placement for a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	#[Idempotent]
	#[Hidden]
	public function addAction(
		AddRequest $request,
		CatalogItemEmbeddingBinder $binder,
		EmbeddingDtoMapper $mapper,
	): GetResponse
	{
		$userId = $this->getCurrentUserId();
		$embedding = $this->runCallable(static fn() => $binder->bind(
			catalogItemId: $request->catalogItemId,
			userId: $userId,
			placement: $request->placement,
			handler: $request->handler,
			options: $request->options,
			languages: $request->languages,
		));

		return new GetResponse($mapper->toDto($embedding));
	}

	#[Description('Removes an embedding of a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	#[Idempotent]
	#[Hidden]
	public function deleteAction(
		DeleteRequest $request,
		CatalogItemEmbeddingUnbinder $unbinder,
	): DeleteResponse
	{
		$userId = $this->getCurrentUserId();
		$this->runCallable(static fn() => $unbinder->unbindById($request->embeddingId, $userId));

		return new DeleteResponse();
	}

	#[Description('Lists embeddings of a catalog item owned by the current REST user')]
	#[Scope(RestDeveloper::SCOPE)]
	#[Hidden]
	public function listAction(
		ListRequest $request,
		CatalogItemEmbeddingLister $lister,
		EmbeddingDtoMapper $mapper,
	): ListResponse
	{
		$userId = $this->getCurrentUserId();
		$embeddings = $this->runCallable(static fn() => $lister->list($request->catalogItemId, $userId));
		$items = new DtoCollection(EmbeddingDto::class);
		foreach ($embeddings as $embedding)
		{
			$items->add($mapper->toDto($embedding));
		}

		return new ListResponse($items);
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
