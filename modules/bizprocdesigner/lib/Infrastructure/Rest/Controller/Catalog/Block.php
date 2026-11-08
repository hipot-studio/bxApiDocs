<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Catalog;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\BlockTypeDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\BlockCatalogUnavailableException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\BlockContextRejectedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\BlockContextResolutionFailedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\BlockTypeNotFoundException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Request\BlockGetRequest;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockContextRejectionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockCatalogService;
use Bitrix\Main\LoaderException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * REST V3 controller for the agent-facing block catalog.
 *
 * bizprocdesigner.catalog.block.list - block types the bound template can be built from.
 * bizprocdesigner.catalog.block.get - one block of that catalog, read in full.
 */
#[DtoType(BlockTypeDto::class)]
final class Block extends AbstractAgentRestController
{
	/**
	 * Returns the block types available for the template the calling token is bound to.
	 *
	 * @restMethod bizprocdesigner.catalog.block.list
	 * @throws AccessDeniedException
	 * @throws BlockCatalogUnavailableException
	 * @throws EntityNotFoundException
	 * @throws LoaderException
	 */
	public function listAction(ListRequest $request): ListResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();

		$result = (new AgentBlockCatalogService())->getBlocksWithDescription($identifier->documentDescription);

		// A plain Result means a portal without bizproc, and the availability prefilter has already refused
		// such a portal with 403: this is the type guard of the union, not an answer an agent can receive.
		if (!$result instanceof BlockDescriptionResult)
		{
			throw new BlockCatalogUnavailableException();
		}

		return new ListResponse(
			$this->getDtoMapper()->mapCollection(
				iterator_to_array($result->blocks),
				$this->selectedFields($request->select),
			),
		);
	}

	/**
	 * Returns one block of the catalog: the same resource as a catalog entry, with its settings
	 * schema and the rest of the detail filled in.
	 *
	 * A context of setting values narrows what the block is described with, so a map whose keys the block
	 * resolves from its own values answers with them. The context never chooses the document type: the
	 * catalog is read for the type of the template the token is bound to, exactly as without one.
	 *
	 * @restMethod bizprocdesigner.catalog.block.get
	 * @throws AccessDeniedException
	 * @throws BlockContextRejectedException
	 * @throws BlockContextResolutionFailedException
	 * @throws BlockTypeNotFoundException
	 * @throws EntityNotFoundException
	 * @throws LoaderException
	 */
	public function getAction(BlockGetRequest $request): GetResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();

		$result = (new AgentBlockCatalogService())->getBlockSettings(
			$identifier->documentDescription,
			$request->id,
			$request->presetId ?? '',
			$request->contextSettings,
			$identifier->templateId,
		);

		// A context the block cannot be described in is about the request, not about the resource: the block
		// itself was read, and a 404 here would send the agent looking for another type instead of correcting
		// what it sent.
		if ($result instanceof BlockContextRejectionResult)
		{
			throw $result->isResolutionFailure()
				? new BlockContextResolutionFailedException($result->getErrors())
				: new BlockContextRejectedException($result->getErrors())
			;
		}

		// Every reason a single block is refused - unknown type, unknown preset, a type whose description
		// does not build - is also a reason the listing leaves it out, so the two actions agree. The refusal
		// names the pair the caller asked for, preset included: the catalog answers per variant, so a type
		// named alone would point at the wrong half of an unknown pair.
		if (!$result instanceof BlockSettingsResult)
		{
			throw new BlockTypeNotFoundException($request->id, $request->presetId);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne(
				$result->blockDetail,
				$this->selectedFields($request->select),
			),
		);
	}
}
