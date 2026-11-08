<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Template;

use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\SaveTemplateDraftResponse;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\TemplateDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\DraftSaveFailedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\GraphValidationException;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * REST V3 controller for draft operations on workflow templates.
 *
 * bizprocdesigner.template.draft.add - validate, persist draft and push it to the open editor.
 */
#[DtoType(TemplateDto::class)]
final class Draft extends AbstractAgentRestController
{
	/**
	 * Validates blocks and connections, saves a draft and pushes it live to the open editor.
	 * The user must click "Save" in the designer to publish.
	 *
	 * The graph replaces the whole content of the bound template. A graph the domain rejects is a failed
	 * request here, unlike in the dry run of template.validate, but the two judge it the same way.
	 *
	 * @restMethod bizprocdesigner.template.draft.add
	 * @throws AccessDeniedException
	 * @throws DraftSaveFailedException
	 * @throws EntityNotFoundException
	 * @throws GraphValidationException
	 */
	public function addAction(AddRequest $request): GetResponse
	{
		$subject = $this->resolveAuthorizedSubjectForBoundTemplate();
		$identifier = $subject->toIdentifier();
		$userId = $subject->userId;

		$limitsResult = $this->validateGraphLimits($this->rawGraph($request->fields));
		if (!$limitsResult->isSuccess())
		{
			throw new GraphValidationException($limitsResult->getErrors());
		}

		$graph = $this->submittedGraph($request->fields);
		$validateResult = $this->validateSubmission($request->fields, $graph, $identifier);
		if (!$validateResult instanceof AgentWorkflowValidationResult)
		{
			throw new GraphValidationException($validateResult->getErrors());
		}

		// The declarations and the metadata ride the same result the valid blocks ride, so the graph is written
		// from the very reading of the wizard forms and of the body it was judged by. template.validate builds the
		// same result and stops at the verdict, which is what keeps a dry run from touching the constants and from
		// renaming the template.
		$saveResult = Container::getAgentDraftService()->saveAndPush(
			subject: $subject,
			blocks: $validateResult->blocks,
			connections: $validateResult->connections,
			source: RequestSource::Rest,
			setupConstantDeclarations: $validateResult->setupConstantDeclarations,
			templateMetadata: $validateResult->templateMetadata,
		);

		$savedDraftId = $saveResult instanceof SaveTemplateDraftResponse ? $saveResult->getTemplateDraftId() : 0;
		if (!$saveResult->isSuccess() || $savedDraftId <= 0)
		{
			$refusal = DraftSaveFailedException::ofSaveResult($saveResult);
			self::logRefusedWrite($identifier, $userId, $saveResult, $refusal);

			throw $refusal;
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne($this->savedTemplate($identifier->withDraftId($savedDraftId))),
		);
	}

	/**
	 * The address of a failed write, written wherever the logger of the module is configured to write.
	 *
	 * Which refusals get written is decided by the marker the refusal carries, the same one the exception log
	 * of the portal reads: a refusal the caller caused is the answer to what was asked, not an incident, and
	 * stays out of both logs. The exception log holds no identifiers, so the two together name what failed
	 * and where.
	 *
	 * Every value is placed in the message: LogFormatter substitutes {key} placeholders only, and a context
	 * naming none of them is dropped without a word.
	 */
	private static function logRefusedWrite(
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		Result $saveResult,
		DraftSaveFailedException $refusal,
	): void
	{
		if ($refusal instanceof SkipWriteToLogException)
		{
			return;
		}

		Container::getDefaultLogger()->error(
			'Agent draft save refused for template {templateId}, draft {draftId}, user {userId},'
				. ' reason codes {codes}: {errors}',
			[
				'templateId' => (int)($identifier->templateId ?? 0),
				'draftId' => (int)($identifier->draftId ?? 0),
				'userId' => $userId,
				'codes' => self::reasonCodes($saveResult),
				'errors' => implode('; ', $saveResult->getErrorMessages()) ?: 'no draft id in a successful write',
			],
		);
	}

	/**
	 * @return string machine readable codes the domain named the refusal with, in the order it named them
	 */
	private static function reasonCodes(Result $saveResult): string
	{
		$codes = array_map(
			static fn(Error $error): string => (string)$error->getCode(),
			$saveResult->getErrors(),
		);

		return implode(',', $codes) ?: 'none';
	}

	/**
	 * The template as it now stands, read through the draft the write has just produced.
	 *
	 * Which draft that is comes from the answer of the write, not from the identifier the write started
	 * from: that one may carry no draft at all, and reading through it would answer with the published
	 * template instead of the graph just written.
	 *
	 * @throws EntityNotFoundException
	 */
	private function savedTemplate(WorkflowTemplateIdentifier $saved): array
	{
		$item = $this->templateResource($saved);
		if ($item === null)
		{
			throw new EntityNotFoundException((int)$saved->templateId);
		}

		return $item;
	}
}
