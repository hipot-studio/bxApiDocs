<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Service;

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Main\ArgumentException;

/**
 * Turns the document context an activity carries - what the author of the template wrote into the document
 * field of a node action - into a document id the engine can work with.
 *
 * The single place a scalar becomes a document: everything else on this path (the mixins, the module
 * resolvers) reads the answer through {@see \CBPActivity::getDocumentId()}.
 *
 * The resolver never reports a document it is not sure of. A value that resolves to nothing, to a shape that
 * is not a document id, to a document of a module the node does not serve, or to one whose module refuses to
 * tell what it is, is answered with null, and the engine skips the node instead of quietly working over the
 * document of the process ({@see \CBPWorkflow::runExecuteActivityOperation()}).
 */
final class DocumentContextResolver
{
	/**
	 * @return array{0: string, 1: string, 2: string}|null Document id, or null when the context resolves
	 *     to no document.
	 */
	public function resolveDocumentId(\CBPActivity $activity, string $contextExpression): ?array
	{
		$value = $activity->getRootActivity()->parseValue($contextExpression);

		if (is_array($value))
		{
			return $this->acceptDocumentId($activity, \CBPHelper::normalizeComplexDocumentId($value));
		}

		if (!is_scalar($value))
		{
			return null;
		}

		$id = trim((string)$value);
		if ($id === '')
		{
			return null;
		}

		$documentType = $this->resolveEffectiveDocumentType($activity);
		if ($documentType === null)
		{
			// Nothing tells which entity a bare id belongs to, so it names no document.
			return null;
		}

		$documentService = $activity->workflow?->getService('DocumentService');
		$documentId = [(string)$documentType[0], (string)$documentType[1], $id];

		if ($documentService instanceof \CBPDocumentService)
		{
			// A module may spell its ids differently from the raw one the author typed: 42 of a deal is DEAL_42.
			$normalized = $documentService->normalizeDocumentId($documentId, (string)($documentType[2] ?? ''));
			if (is_array($normalized))
			{
				$documentId = $normalized;
			}
		}

		return $this->acceptDocumentId($activity, \CBPHelper::normalizeComplexDocumentId($documentId));
	}

	/**
	 * The document type the node acts in, resolved the way the registry of target resolvers resolves it
	 * ({@see \Bitrix\Bizproc\Public\Activity\Registry\TargetDocumentResolverRegistry}), so that design time
	 * and runtime read a value alike.
	 *
	 * The second branch asks the root activity and not the node itself: the node is asking this while
	 * answering what its own document is, and asking it back would not terminate. Which is also why the root
	 * carrying a context of its own - a template written or imported with a document on its root activity -
	 * is answered with nothing at all: there is no outer activity left to read a type off, and asking the
	 * root would come straight back here.
	 *
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	public function resolveEffectiveDocumentType(\CBPActivity $activity): ?array
	{
		$parent = $activity->parent;
		if ($parent instanceof FixedDocumentComplexActivity)
		{
			return $this->asDocumentType($parent::getDocumentTypeForNodeAction());
		}

		$rootActivity = $activity->getRootActivity();
		if ($rootActivity === $activity)
		{
			return null;
		}

		return $this->asDocumentType($rootActivity->getDocumentType());
	}

	/**
	 * A node whose host fixes the document type serves that module only - the same rule the action form
	 * applies to the values it offers. A node that has no such host takes the type of the process merely to
	 * read a bare id, and that is no promise about the module, so a whole document id of another module
	 * stays acceptable there.
	 *
	 * @param array{0: string, 1: string, 2: string}|null $documentId
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	private function acceptDocumentId(\CBPActivity $activity, ?array $documentId): ?array
	{
		if ($documentId === null || !$this->isDocumentTypeReadable($activity, $documentId))
		{
			return null;
		}

		$parent = $activity->parent;
		if (!($parent instanceof FixedDocumentComplexActivity))
		{
			return $documentId;
		}

		$fixedType = $this->asDocumentType($parent::getDocumentTypeForNodeAction());
		if ($fixedType === null)
		{
			return $documentId;
		}

		return mb_strtolower((string)$documentId[0]) === mb_strtolower((string)$fixedType[0])
			? $documentId
			: null
		;
	}

	/**
	 * Whether the module will tell what the document is. Some modules answer that by looking the document up
	 * and refuse an id of an entity that is not there - CRM throws on one
	 * ({@see \CCrmDocument::GetDocumentType()}) - and the refusal used to travel into the action, which asks
	 * for the type of its target, and fault the process. The refusal is an answer: the value names no
	 * document the engine can work with, and the node is skipped with a reason instead
	 * ({@see \CBPWorkflow::runExecuteActivityOperation()}).
	 *
	 * Only the two families a provider refuses an id with are read as that answer: `CBPArgumentException`,
	 * which every provider of the portal throws on an id it cannot make sense of (crm items, lists and
	 * iblock, disk), and {@see ArgumentException}, which crm answers with about an element that is not
	 * there. Anything else goes on its way: a failing database, a module that would not load, a programming
	 * error inside a provider - none of them is an answer about a document, and skipping the node over one
	 * would carry the workflow on past a broken portal instead of faulting it.
	 * `Bitrix\Main\SystemException` is deliberately not the family taken, wide as it looks: the failures of
	 * the database and of the ORM are that class too.
	 *
	 * What the narrow set costs: the providers spelling "the document is not there" as a bare
	 * `SystemException` - crm orders and invoices, disk - fault as they did before this check existed. Told
	 * apart from a portal that is failing they cannot be, and faulting is the answer master gave.
	 *
	 * The existence of the document is still not asked about here. A module that simply does not tell types
	 * refuses nothing and is left alone, and the answer of one that does is the very answer the node goes on
	 * to read, cached for the request by {@see \CBPDocumentService::getDocumentType()}.
	 *
	 * @param array{0: string, 1: string, 2: string} $documentId
	 */
	private function isDocumentTypeReadable(\CBPActivity $activity, array $documentId): bool
	{
		$documentService = $activity->workflow?->getService('DocumentService');
		if (!($documentService instanceof \CBPDocumentService))
		{
			return true;
		}

		try
		{
			$documentService->getDocumentType($documentId);
		}
		catch (\CBPArgumentException | ArgumentException)
		{
			return false;
		}

		return true;
	}

	/** @return array{0: string, 1: string, 2: string}|null */
	private function asDocumentType(mixed $documentType): ?array
	{
		if (!is_array($documentType))
		{
			return null;
		}

		$documentType = array_values($documentType);
		if (count($documentType) < 3)
		{
			return null;
		}

		foreach ($documentType as $part)
		{
			if (!is_scalar($part) || trim((string)$part) === '')
			{
				return null;
			}
		}

		return [(string)$documentType[0], (string)$documentType[1], (string)$documentType[2]];
	}
}
