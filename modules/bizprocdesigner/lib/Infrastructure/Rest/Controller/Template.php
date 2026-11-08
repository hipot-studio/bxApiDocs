<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\TemplateDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Request\TemplateValidateRequest;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Response\ValidateResponse;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * REST V3 controller for bizprocdesigner workflow templates.
 *
 * bizprocdesigner.template.list     - list accessible templates.
 * bizprocdesigner.template.get      - get the current graph of a specific template.
 * bizprocdesigner.template.validate - validate blocks and connections without saving.
 */
#[DtoType(TemplateDto::class)]
final class Template extends AbstractAgentRestController
{
	/**
	 * Returns accessible workflow templates for the current user.
	 *
	 * The agent token is bound to exactly one template, so the listing is scoped to that template.
	 *
	 * @restMethod bizprocdesigner.template.list
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	public function listAction(ListRequest $request): ListResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();

		return new ListResponse(
			$this->getDtoMapper()->mapCollection(
				[$this->resourceHeader($identifier)],
				$this->selectedFields($request->select),
			),
		);
	}

	/**
	 * Returns the current workflow graph (blocks and connections) for the given template.
	 *
	 * The only action of the contour that takes an identifier from the caller, so the id is checked
	 * against the token binding before anything is read.
	 *
	 * @restMethod bizprocdesigner.template.get
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws InvalidRequestFieldTypeException
	 */
	public function getAction(GetRequest $request): GetResponse
	{
		$templateId = self::requestedTemplateId($request->id);

		$identifier = $this->resolveAndAuthorize($templateId);

		$item = $this->templateResource($identifier);
		if ($item === null)
		{
			throw new EntityNotFoundException($templateId);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne($item, $this->selectedFields($request->select)),
		);
	}

	/**
	 * Judges the submitted blocks and connections without persisting anything.
	 *
	 * A graph that does not pass is not a failed request: the verdict is the answer, so a rejected graph
	 * still comes back as data. It is produced by the same two-stage validation the write path runs, so
	 * the two verdicts agree.
	 *
	 * @restMethod bizprocdesigner.template.validate
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	public function validateAction(TemplateValidateRequest $request): ValidateResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();

		$rawGraph = $this->rawGraph($request->fields);
		$limitsResult = $this->validateGraphLimits($rawGraph);
		if (!$limitsResult->isSuccess())
		{
			// A bound is a verdict like any other here: it comes back as a report, not as a refused call.
			return new ValidateResponse(
				GraphValidationErrorMapper::toReport(
					$limitsResult,
					is_array($rawGraph['blocks']) ? $rawGraph['blocks'] : [],
				),
			);
		}

		$graph = $this->submittedGraph($request->fields);
		$result = $this->validateSubmission($request->fields, $graph, $identifier);

		return new ValidateResponse(
			GraphValidationErrorMapper::toReport($result, is_array($graph['blocks']) ? $graph['blocks'] : []),
		);
	}

	/**
	 * A non-numeric id must not be cast into 0 and reach the token guard looking like a template id,
	 * so it is refused as a malformed field instead.
	 *
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function requestedTemplateId(string $id): int
	{
		if (preg_match('/^[0-9]+$/D', $id) !== 1)
		{
			throw new InvalidRequestFieldTypeException('id', 'int');
		}

		return (int)$id;
	}
}
