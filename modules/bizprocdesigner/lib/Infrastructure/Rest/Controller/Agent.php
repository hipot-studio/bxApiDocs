<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ConnectInstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Response\ConnectResponse;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentConnectInstructionService;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;

/**
 * REST V3 controller for agent connection.
 *
 * bizprocdesigner.agent.connect - returns a self-contained markdown brief for an external coding agent.
 *
 * No #[DtoType] here, unlike every other controller of the contour, and that is deliberate: the attribute
 * feeds the auto-wire of a Request, connectAction() takes no Request, and carrying it would publish
 * agent.field.get and agent.field.list on top of the actions the brief promises to answer. AgentCest
 * holds the surface to that closed list.
 */
final class Agent extends AbstractAgentRestController
{
	/**
	 * Returns a markdown instruction brief that an external AI agent can use to understand and edit
	 * one workflow template.
	 *
	 * The template is named by the caller, which is a plain action parameter here rather than a field of
	 * a Request: this action auto-wires none, and the entry of the server merges the json body into the
	 * source parameters, so the parameter is filled from the body like every other field of the contour
	 * ({@see \CRestUtil::getRequestData()}). Left out, it falls back to the binding of the token; under an
	 * application key there is no binding and it is required {@see AbstractAgentRestController::resolveAndAuthorize()}.
	 *
	 * @restMethod bizprocdesigner.agent.connect
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws RequiredFieldInRequestException
	 */
	public function connectAction(?int $templateId = null): ConnectResponse
	{
		$identifier = $this->resolveAndAuthorize($templateId);

		$instruction = new ConnectInstructionDto();
		$instruction->templateId = (int)$identifier->templateId;
		$instruction->instruction = (new AgentConnectInstructionService())->build($identifier);

		return new ConnectResponse($instruction);
	}
}
