<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Document;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\DocumentFieldDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\DocumentTypeDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\DocumentFieldResponseTooLargeException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\DocumentFieldsUnavailableException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\DocumentTypeAccessDeniedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\DocumentTypeInvalidException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\DocumentTypeNotAllowedException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\SemanticSearchUnavailableException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Request\DocumentFieldListRequest;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Response\DocumentFieldListResponse;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\DocumentFieldSearchResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentFieldService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RelatedDocumentFieldPolicy;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\TrustedDocumentTypeResolver;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

/**
 * REST V3 controller for the fields of a document type the agent can address in a workflow graph.
 *
 * bizprocdesigner.document.field.list - the fields of the bound template's own type, or of another type the
 * template may name and the caller may read.
 */
#[DtoType(DocumentFieldDto::class)]
final class Field extends AbstractAgentRestController
{
	/**
	 * How large the answer may get before it is refused rather than cut short. Only the read of an
	 * explicitly named type has a bound: the read of the bound type has never had one, and giving it one
	 * would refuse a request that works today.
	 */
	private const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Returns the document fields the agent can address.
	 *
	 * Without `documentType` this is the read it has always been: the type of the bound template, authorized
	 * by the token and the template alone, with the search behaving exactly as before.
	 *
	 * With `documentType` the caller names a type itself, and two things stand between it and the answer.
	 * First the type has to be one this template could name at all - which is a guard against a graph
	 * widening its own reach and not a statement about anyone's rights ({@see TrustedDocumentTypeResolver}).
	 * Then the caller has to be allowed to read that type's elements. Only after both does the schema come
	 * back, and even then without the fields of the entities crm glues onto it that the caller may not read
	 * ({@see RelatedDocumentFieldPolicy}).
	 *
	 * @restMethod bizprocdesigner.document.field.list
	 * @throws AccessDeniedException
	 * @throws DocumentFieldResponseTooLargeException
	 * @throws DocumentFieldsUnavailableException
	 * @throws DocumentTypeAccessDeniedException
	 * @throws DocumentTypeInvalidException
	 * @throws DocumentTypeNotAllowedException
	 * @throws EntityNotFoundException
	 * @throws SemanticSearchUnavailableException
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	public function listAction(DocumentFieldListRequest $request): DocumentFieldListResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();
		$search = $request->search ?? '';
		$service = new DocumentFieldService();

		if ($request->documentType === null)
		{
			$outcome = $service->readBoundTypeFields($identifier->documentDescription, $search);

			return $this->answer($request, $identifier->documentDescription, $outcome, bounded: false);
		}

		$requestedType = $this->authorizedType($request->documentType, $identifier);
		$outcome = $service->readRequestedTypeFields($requestedType, $search);
		if ($outcome->isSuccess() && !$requestedType->equals($identifier->documentDescription))
		{
			$outcome = DocumentFieldSearchResult::answered(
				$this->withoutUnreadableRelatedFields($outcome->fields),
				$outcome->mode,
			);
		}

		return $this->answer($request, $requestedType, $outcome, bounded: true);
	}

	/**
	 * The type the caller named, once it is established that this template may name it and that the caller
	 * may read it.
	 *
	 * The order of the two is deliberate. Membership is judged first, so a type this template could never
	 * name is refused without anyone's rights being consulted - the refusal then says nothing about what the
	 * caller may or may not read elsewhere. Naming the bound type explicitly is not a foreign read and skips
	 * both: the token and the template have already authorized it.
	 *
	 * @throws DocumentTypeAccessDeniedException
	 * @throws DocumentTypeInvalidException
	 * @throws DocumentTypeNotAllowedException
	 */
	private function authorizedType(string $submitted, WorkflowTemplateIdentifier $identifier): DocumentDescription
	{
		$requestedType = DocumentDescription::fromCanonicalString($submitted);
		if ($requestedType === null)
		{
			throw new DocumentTypeInvalidException();
		}

		if ($requestedType->equals($identifier->documentDescription))
		{
			return $requestedType;
		}

		$isAllowed = (new TrustedDocumentTypeResolver())->isAllowed(
			$identifier->documentDescription,
			$identifier->templateId,
			$requestedType,
		);
		if (!$isAllowed)
		{
			throw new DocumentTypeNotAllowedException();
		}

		if (!(new DocumentAccessService())->canViewDocumentType($this->currentUserId(), $requestedType))
		{
			throw new DocumentTypeAccessDeniedException();
		}

		return $requestedType;
	}

	/**
	 * The same fields without those of a related entity the caller may not read.
	 *
	 * Dropping them is not a refusal: the caller asked for the type it is allowed to read, and gets it - the
	 * names it may not see are simply not there. Answering with a refusal instead would deny the whole read
	 * over fields the caller never asked for by name (BRIEF § 6.2).
	 */
	private function withoutUnreadableRelatedFields(DocumentFieldCollection $fields): DocumentFieldCollection
	{
		$access = new DocumentAccessService();
		$userId = $this->currentUserId();

		return (new RelatedDocumentFieldPolicy())->filter(
			$fields,
			static fn(DocumentDescription $relatedType) => $access->canViewDocumentType($userId, $relatedType),
		);
	}

	/**
	 * The outcome as the transport answers it: the fields, the type they belong to and how they were chosen.
	 *
	 * A read that could not deliver is refused rather than answered with an empty list, so an agent never
	 * reads "the search is down" as "this type has no such field". Which failures can arrive here is decided
	 * by the read itself ({@see DocumentFieldService}), not here.
	 *
	 * @param bool $bounded whether the size limit of the new mode applies to this answer
	 *
	 * @throws DocumentFieldResponseTooLargeException
	 * @throws DocumentFieldsUnavailableException
	 * @throws SemanticSearchUnavailableException
	 */
	private function answer(
		DocumentFieldListRequest $request,
		DocumentDescription $documentType,
		DocumentFieldSearchResult $outcome,
		bool $bounded,
	): DocumentFieldListResponse
	{
		if ($outcome->areFieldsUnavailable)
		{
			throw new DocumentFieldsUnavailableException();
		}

		if ($outcome->isSearchUnavailable)
		{
			throw new SemanticSearchUnavailableException();
		}

		$response = new DocumentFieldListResponse(
			$this->getDtoMapper()->mapCollection(
				iterator_to_array($outcome->fields),
				$this->selectedFields($request->select),
			),
			DocumentTypeDto::fromDescription($documentType),
			$outcome->mode,
		);

		if ($bounded)
		{
			self::assertFits($response);
		}

		return $response;
	}

	/**
	 * @throws DocumentFieldResponseTooLargeException
	 * @throws ArgumentException
	 */
	private static function assertFits(DocumentFieldListResponse $response): void
	{
		$actualBytes = strlen(Json::encode($response->toArray()));
		if ($actualBytes > self::MAX_RESPONSE_BYTES)
		{
			throw new DocumentFieldResponseTooLargeException(self::MAX_RESPONSE_BYTES, $actualBytes);
		}
	}
}
