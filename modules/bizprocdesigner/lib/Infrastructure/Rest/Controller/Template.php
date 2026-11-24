<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller;

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SaveTemplateRequest;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotEditorStateService;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter\AgentTokenFilter;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter\EntryAdmissionFilter;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\TemplateDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\EntryOperationFailedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\FeatureUnavailableException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\PublishRefusedException;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\AgentEntryVerdict;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\ContourCaller;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentEntryAuthorizationService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DraftFingerprint;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\BizprocDesigner\Internal\Service\WorkflowTemplate\EmptyTemplateCreationService;
use Bitrix\BizprocDesigner\Internal\Service\WorkflowTemplate\PublicationMark;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Request\TemplateValidateRequest;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Response\ValidateResponse;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Idempotent;
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
 * bizprocdesigner.template.add      - create an empty template of a document type.
 * bizprocdesigner.template.publish  - publish the stored draft as the live version of a template.
 */
#[DtoType(TemplateDto::class)]
final class Template extends AbstractAgentRestController
{
	/**
	 * The fields a publication carries over from the draft, and the whole of the contract between the blob
	 * of a draft and the row of a live template.
	 *
	 * Declared rather than written inline where it is used: the blob is a snapshot of a template row and
	 * carries whatever the editor put there, so what travels is decided here and nowhere else. A key added
	 * to the blob later does not ride along until it is named on this list, and
	 * {@see \Bitrix\BizprocDesigner\Tests\Infrastructure\Rest\Controller\Template\PublishableFieldsTest}
	 * keeps its own copy of the list precisely to notice when the two disagree.
	 */
	private const PUBLISHABLE_DRAFT_FIELDS = [
		'TEMPLATE',
		'NAME',
		'DESCRIPTION',
		'PARAMETERS',
		'VARIABLES',
		'CONSTANTS',
		'AUTO_EXECUTE',
	];

	/**
	 * Fields whose absence from the blob is an erasure rather than "leave it alone", which is why a draft
	 * without them is refused instead of published {@see self::publishableFields()}.
	 */
	private const ERASABLE_DRAFT_FIELDS = ['CONSTANTS', 'VARIABLES'];

	/**
	 * The creation is the one action of this controller an ordinary application key may reach: it opens no
	 * session with the contour and touches no existing template, so the token of the contour - which is
	 * bound to a template that does not exist yet - cannot be what authorizes it.
	 *
	 * Removed per action rather than per class deliberately: everything else here keeps demanding the
	 * token, and an action added later without a line in this list simply will not work, which is the
	 * failure that shows up immediately. `AiAvailabilityFilter` stays on every action, this one included.
	 */
	public function configureActions(): array
	{
		return [
			'add' => [
				'-prefilters' => [AgentTokenFilter::class],
				// The token of the contour is refused here as it is at the session door: a credential bound
				// to one template must not mint templates it was never scoped to {@see EntryAdmissionFilter}.
				'+prefilters' => [new EntryAdmissionFilter()],
			],
		];
	}

	/**
	 * Creates an empty workflow template of the given document type and answers with its header.
	 *
	 * Parameters are taken scalar rather than through a typed Request with a `fields` envelope, which the
	 * crud canon of V3 would prescribe. The resource dto of this contour is shared by reads and writes and
	 * declares the document type read-only ({@see TemplateDto}); teaching it a second writable group for
	 * the sake of one action would widen the write surface of every other action that uses it.
	 *
	 * The graph is not accepted here. A template is born empty and receives its graph through
	 * `template.draft.add`, exactly as one created in the editor does - which also means the graph is
	 * judged by the one validator the contour has, instead of a second path around it.
	 *
	 * @param array $documentType bizproc complex type as a triple, ['crm', 'CCrmDocumentDeal', 'DEAL']
	 *
	 * Idempotent by declaration, because the auto-detection of the platform cannot see it: a creation is
	 * recognised by an `AddRequest` in the signature, and this one takes its parameters scalar. Without
	 * the attribute a repeat under the same `Idempotency-Key` - which is what a caller sends after a lost
	 * answer - runs the action again and leaves a second empty template behind.
	 *
	 * @restMethod bizprocdesigner.template.add
	 * @throws AccessDeniedException
	 * @throws EntryOperationFailedException
	 * @throws InvalidRequestFieldTypeException
	 */
	#[Idempotent]
	public function addAction(
		array $documentType,
		?string $name = null,
		?string $startTrigger = null,
	): GetResponse
	{
		$description = self::requestedDocumentType($documentType);
		$userId = (int)CurrentUser::get()->getId();

		$verdict = (new AgentEntryAuthorizationService())->authorizeDocumentType($userId, $description);
		if ($verdict === AgentEntryVerdict::FeatureUnavailable)
		{
			throw new FeatureUnavailableException();
		}
		if ($verdict !== AgentEntryVerdict::Allowed)
		{
			throw new AccessDeniedException();
		}

		$result = (new EmptyTemplateCreationService())->create(
			$description,
			name: $name,
			startTrigger: $startTrigger,
			userId: $userId,
		);

		if (!$result->isSuccess())
		{
			// Not EntityNotFoundException: the template is not missing, it was never written. Answering
			// "not found" would have the caller look for a template that does not exist and never did.
			throw EntryOperationFailedException::ofTemplateCreation();
		}

		$templateId = (int)$result->getData()['templateId'];
		$identifier = Container::getAgentWorkflowResolverService()->resolveByTemplateId($templateId, $userId);
		if ($identifier === null)
		{
			// The write went through and the contour cannot see what it wrote - the two disagree about what
			// a template of this contour is. Answered as a failure carrying the id, never as "not found":
			// the row exists, and a caller told otherwise would create a second one.
			throw EntryOperationFailedException::ofTemplateReadBack($templateId);
		}

		return new GetResponse($this->getDtoMapper()->mapOne($this->resourceHeader($identifier)));
	}

	/**
	 * The document type as the caller wrote it. A triple is what bizproc itself uses, and the three parts
	 * are required: a partial type would resolve to a different document class and create the template
	 * somewhere the caller did not ask for.
	 *
	 * The parts are read BY POSITION rather than resequenced with array_values(): a body carrying named
	 * keys has no defined order, and resequencing one would silently accept whatever order it happened to
	 * be written in - producing a valid-looking type pointing at another document class. Explicit numeric
	 * keys written out of order (a query string may) still work, which array_is_list() would have refused.
	 *
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function requestedDocumentType(array $documentType): DocumentDescription
	{
		$triple = [$documentType[0] ?? null, $documentType[1] ?? null, $documentType[2] ?? null];
		if (count($documentType) !== 3 || array_filter($triple, static fn($p) => !is_string($p) || $p === ''))
		{
			throw new InvalidRequestFieldTypeException('documentType', 'array of three non-empty strings');
		}

		return DocumentDescription::fromBizprocComplexType($triple);
	}

	/**
	 * Returns accessible workflow templates for the current user.
	 *
	 * The one action of the contour that did not move to the application key. A token is bound to exactly
	 * one template, so the listing is a listing of that one; an application key - an OAuth application or an
	 * incoming webhook that is not a token of ours, a user webhook among them - carries no binding, and
	 * there is nothing for it to list here - enumerating the templates of a portal is not what this contour
	 * is for.
	 *
	 * The refusal stands BEFORE the resolver on purpose. The resolver would answer with the 400 of a
	 * missing templateId, which reads as "name a template" - and naming one would still not turn this into
	 * a listing the caller could use.
	 *
	 * @restMethod bizprocdesigner.template.list
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	public function listAction(ListRequest $request): ListResponse
	{
		if (ContourCaller::of($this->getServer()) !== ContourCaller::Contour)
		{
			throw new AccessDeniedException();
		}

		$identifier = $this->resolveAndAuthorize();

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
	 * The identifier travels as the resource id of the read rather than as a templateId of its own, which
	 * is the one place in the contour where it does. Under a token it is still checked against the binding
	 * before anything is read.
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
	 * @throws RequiredFieldInRequestException
	 */
	public function validateAction(TemplateValidateRequest $request): ValidateResponse
	{
		$identifier = $this->resolveAndAuthorize($request->templateId);

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
	 * Publishes the stored draft of a template as its live version.
	 *
	 * The order of the steps is a requirement and not a style. The right to publish is asked FIRST, because
	 * everything after it answers with the state of the template - whether a draft is there, whether a pilot
	 * is running - to a caller that may not publish it, and the state of a pilot is answered nowhere else in
	 * this contour.
	 *
	 * That right is per template and strictly stronger than the right to edit: a holder of EDIT saves drafts
	 * and publishes nothing. The guard of the contour asks CreateWorkflow on the document type and never
	 * asks this one, so the pre-gate duplicates nothing. checkAccess of the write below stays authoritative -
	 * the pre-gate settles the ORDER of the refusals and gives a refusal by right a code of its own instead
	 * of the code 0 every failed write carries.
	 *
	 * Not idempotent, and it is not made to look like it is: the write deletes every draft of the template,
	 * so a retry after a timeout is refused as "no draft" rather than repeating a success. A caller that lost
	 * the answer re-reads template.get and looks at hasDraft before calling again.
	 *
	 * @restMethod bizprocdesigner.template.publish
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws PublishRefusedException
	 * @throws RequiredFieldInRequestException
	 */
	public function publishAction(int $templateId, ?string $draftFingerprint = null): GetResponse
	{
		$identifier = $this->resolveAndAuthorize($templateId);
		$userId = $this->currentUserId();
		$documentType = $identifier->documentDescription->toBizprocComplexType();

		// The triple is passed on though the current rule ignores it, the same way the editor passes it: it
		// is the scope a later rule reads, and a call built without it would have to be found by hand then.
		if (!(new TemplateAccessService())->canPublish($templateId, $userId, $documentType))
		{
			throw new AccessDeniedException();
		}

		$draftId = $identifier->draftId;
		if ($draftId === null)
		{
			throw PublishRefusedException::noDraft($templateId);
		}

		$resolver = Container::getAgentWorkflowResolverService();
		$draftData = $resolver->getDraftTemplateData($draftId);

		// Compared against the CONTENT of the draft and never against its id: template.draft.add rewrites a
		// draft in place, so the id survives a substitution the comparison exists to catch
		// {@see DraftFingerprint}. Optional here on purpose - the module stays as callable as the editor is,
		// and the boundary above it, which has one client and writes its own contract, demands the value.
		if ($draftFingerprint !== null && $draftFingerprint !== DraftFingerprint::of($draftData))
		{
			throw PublishRefusedException::draftChanged();
		}

		// The one signal about a pilot this side has. Its stop is a decision of a human taken in the editor,
		// and asked here by the cheap question rather than through the state snapshot carrying a whole scheme.
		if ((new PilotEditorStateService())->hasPilot($templateId))
		{
			throw PublishRefusedException::pilotActive();
		}

		$fields = self::publishableFields($draftData, $documentType);
		// The editor tells a published block by its marks, and its own publication stamps them the same way
		// {@see PublicationMark}. Stamped after the fingerprint, which is taken over the blob as the draft holds it.
		if (is_array($fields['TEMPLATE'] ?? null))
		{
			$fields['TEMPLATE'] = PublicationMark::stamp($fields['TEMPLATE'], PublicationMark::now());
		}

		$result = (new WorkflowTemplateService())->saveTemplate(
			new SaveTemplateRequest(
				$templateId,
				// The write reads the document type out of the parameters and not out of the fields; empty
				// parameters under checkAccess are refused as INVALID_PARAM_ARG before anything is written.
				[
					'MODULE_ID' => $documentType[0],
					'ENTITY' => $documentType[1],
					'DOCUMENT_TYPE' => $documentType[2],
				],
				$fields,
				new \CBPWorkflowTemplateUser($userId),
			),
		);

		if (!$result->isSuccess())
		{
			throw PublishRefusedException::writeRefused(implode('; ', $result->getErrorMessages()));
		}

		// The identifier of the first step carries the id of a draft the write has just deleted and the date
		// the template had before it, so the answer is resolved again. The memo of the row is dropped first,
		// or the second resolve hands back the same stale row {@see AgentWorkflowResolverService}.
		$resolver->forgetTemplate($templateId);
		$published = $resolver->resolveByTemplateId($templateId, $userId);
		if ($published === null)
		{
			throw new EntityNotFoundException($templateId);
		}

		return new GetResponse($this->getDtoMapper()->mapOne($this->resourceHeader($published)));
	}

	/**
	 * The fields of the live version, assembled out of the blob of the draft by an EXPLICIT allowlist.
	 *
	 * An allowlist rather than a subtraction, because a draft has three producers - the save of the agent
	 * and of the editor, the stop of a pilot, the restore of a version - and their sets of fields differ. A
	 * key appearing in the blob later has to require a decision instead of riding along unnoticed. What comes
	 * out is the allowlist of the publication of the editor minus TEMPLATE_SETTINGS.
	 *
	 * What does NOT travel, and why:
	 *  - SORT: for a draft of the agent this is the default of the map (10) rather than the order of the
	 *    template, and the column is writable - publishing would silently renumber the template to ten. The
	 *    publication does not change the order at all, so it is dropped whatever the draft came from;
	 *  - TYPE, IS_SYSTEM, MODULE_ID, ENTITY: publishing changes neither the type of the template, nor its
	 *    systemness, nor what it belongs to;
	 *  - TEMPLATE_SETTINGS: the key is not in the blob at all, and updateTemplateSettings() is the same no-op
	 *    on an absent key and on an empty value, so the live row keeps the settings it has.
	 *
	 * DOCUMENT_TYPE comes from the identifier rather than from the blob, where it lies as a scalar - the
	 * third part of the triple the write expects.
	 *
	 * The normalization of the setup constants is deliberately NOT run, and that is a decision of the owner
	 * of the product: it deletes an orphaned constant together with the value a wizard collected from the
	 * owner of the portal, and over REST that deletion is seen by nobody. The price is named in the spec (F).
	 *
	 * @param array<string, mixed> $draftData the raw blob of the draft
	 * @param string[] $documentType bizproc complex type as a triple
	 * @return array<string, mixed>
	 * @throws PublishRefusedException
	 */
	private static function publishableFields(array $draftData, array $documentType): array
	{
		// Their absence is not "will not be updated" but an ERASURE: normalizeFields() writes both keys as
		// empty arrays when they are missing, and onAfterUpdate then rewrites the file of constants with that
		// empty set. Every other absent field is simply left alone by a partial update.
		foreach (self::ERASABLE_DRAFT_FIELDS as $required)
		{
			if (!array_key_exists($required, $draftData))
			{
				throw PublishRefusedException::incompleteDraft($required);
			}
		}

		$fields = ['DOCUMENT_TYPE' => $documentType];
		foreach (self::PUBLISHABLE_DRAFT_FIELDS as $key)
		{
			// AUTO_EXECUTE is absent from a draft left by the restore of a version, and that is harmless: the
			// type of an existing template is not recomputed, so the column is left as it was.
			if (array_key_exists($key, $draftData))
			{
				$fields[$key] = $draftData[$key];
			}
		}

		return $fields;
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
