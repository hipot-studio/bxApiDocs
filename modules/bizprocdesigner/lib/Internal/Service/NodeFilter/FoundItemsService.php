<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\NodeFilter;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Service\LastValues\LastValuesService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;

/**
 * The only place the server contour of the found items knows about the snapshot of the last run and
 * about the documents it lists. Three operations: the available items of a node's filter result, the
 * values of one of them, and the removal of the filter-result entries from a snapshot map.
 *
 * Identifiers of the found documents never leave the server: an item is addressed by its position in
 * the very list this service handed out, and the position is resolved back to a document here.
 */
final class FoundItemsService
{
	/** The snapshot is missing or outdated by the template version. */
	private const STATE_NO_SNAPSHOT = 'no-snapshot';

	/**
	 * The snapshot is there and nothing of the filter result is available. A node that did not run in
	 * the captured run answers the same way: an empty collection creates no key, so the snapshot has
	 * nothing to tell the two apart by.
	 */
	private const STATE_EMPTY = 'empty';

	private const STATE_READY = 'ready';

	/**
	 * Property the filter block of a node saves its result projections into; the keys of the map are the
	 * property names the snapshot addresses those results by.
	 *
	 * @see \Bitrix\Bizproc\Public\Activity\Mixins\NodeFilterResultProperties::FILTER_RETURN_PROPERTIES_MAP
	 */
	private const FILTER_RETURN_PROPERTIES_MAP = 'FilterReturnPropertiesMap';

	/**
	 * Available items of the filter result, numbered from one along the filtered list. An unknown
	 * expression, an expression outside the filter results of the template and an entry of another type
	 * are answered with an empty list: the data block must open in any case.
	 *
	 * @return array{
	 *     state: string,
	 *     items: list<array{id: string, title: string, url: string}>,
	 *     hasMore: bool,
	 * }
	 */
	public function getFoundItems(int $templateId, string $expression, int $userId): array
	{
		$collection = $this->collectAvailableDocuments($templateId, $expression, $userId);

		$items = [];
		foreach ($collection['documents'] as $index => $document)
		{
			// numbering follows the filtered list: gaps would disclose the existence of documents the
			// user may not see
			$items[] = [
				'id' => (string)($index + 1),
				'title' => $document['title'],
				'url' => $document['url'],
			];
		}

		return [
			'state' => $collection['state'],
			'items' => $items,
			'hasMore' => $collection['hasMore'],
		];
	}

	/**
	 * Values of the requested fields of one item. Null is the single refusal of the surface - no such
	 * position, no right on the document, no document any more - so the caller cannot tell the reasons
	 * apart. An empty list of fields is an empty map, not a refusal.
	 *
	 * @param list<string> $fields
	 * @return array<string, array{
	 *     value: mixed,
	 *     type: string,
	 *     multiple: bool,
	 *     truncated: bool,
	 *     totalCount: int|null,
	 * }>|null
	 */
	public function getItemValues(
		int $templateId,
		string $expression,
		string $itemId,
		array $fields,
		int $userId,
	): ?array
	{
		$position = $this->parsePosition($itemId);
		if ($position === null)
		{
			return null;
		}

		// the list is rebuilt so the position is resolved against the very same filtered list, and the
		// right on the document is checked again on this request. The walk stops at the position asked
		// for: the rights and the title of every document past it are reads nobody needs here
		$collection = $this->collectAvailableDocuments($templateId, $expression, $userId, $position);
		$document = $collection['documents'][$position - 1] ?? null;
		if ($document === null)
		{
			return null;
		}

		try
		{
			return $this->readValues($document['documentId'], $fields);
		}
		catch (\Throwable)
		{
			// the same single refusal as a missing position: a provider throwing on a document that has
			// just been removed must not send its message to the client
			return null;
		}
	}

	/**
	 * Snapshot map without the entries of the node filter results: their identifiers of found documents
	 * are not handed to the client. The removal is narrow on purpose - document entries of the other
	 * groups of the data block keep their values.
	 *
	 * @param array<string, array> $values
	 * @return array<string, array>
	 */
	public function excludeFilterResults(int $templateId, array $values): array
	{
		if ($values === [])
		{
			return $values;
		}

		$filterResults = $this->collectFilterResultExpressions($templateId);

		return $filterResults === [] ? $values : array_diff_key($values, $filterResults);
	}

	/**
	 * Documents of the collection the caller may read, in the order of the snapshot. With $stopAfter the
	 * walk ends once that many are collected, and the list is a prefix; the state stays truthful either
	 * way, because a prefix is only cut short after something was found, and hasMore is read off the
	 * snapshot entry rather than off the walk.
	 *
	 * @return array{
	 *     documents: list<array{documentId: array, title: string, url: string}>,
	 *     state: string,
	 *     hasMore: bool,
	 * }
	 */
	private function collectAvailableDocuments(
		int $templateId,
		string $expression,
		int $userId,
		?int $stopAfter = null,
	): array
	{
		$filterResults = $this->collectFilterResultExpressions($templateId);
		if (!isset($filterResults[$expression]))
		{
			// narrow surface: an expression the filters of the template do not publish tells nothing,
			// not even whether a snapshot exists
			return $this->emptyCollection(self::STATE_NO_SNAPSHOT);
		}

		$snapshot = (new LastValuesService())->getValues($templateId, $userId);
		if ($snapshot === [])
		{
			return $this->emptyCollection(self::STATE_NO_SNAPSHOT);
		}

		$entry = $snapshot[$expression] ?? null;
		if (!$this->isDocumentCollection($entry))
		{
			// the snapshot is there, so the run happened: an empty collection creates no key at all, and
			// that is the only thing distinguishing "found nothing" from "no snapshot"
			return $this->emptyCollection(self::STATE_EMPTY);
		}

		$documents = [];
		foreach ($entry['value'] as $rawDocumentId)
		{
			$document = $this->resolveAvailableDocument($rawDocumentId, $userId);
			if ($document === null)
			{
				continue;
			}

			$documents[] = $document;
			if ($stopAfter !== null && count($documents) >= $stopAfter)
			{
				break;
			}
		}

		return [
			'documents' => $documents,
			// a list emptied by the rights answers the same way as a filter that found nothing
			'state' => $documents === [] ? self::STATE_EMPTY : self::STATE_READY,
			// only a cut snapshot raises the flag; the difference made by the rights stays inside. An
			// empty list never raises it: the cut part is unchecked by the rights, and telling about it
			// where nothing is shown would tell of documents the user may not see
			'hasMore' => $documents !== [] && $this->isSnapshotTruncated($entry),
		];
	}

	/**
	 * @return array{documents: list<never>, state: string, hasMore: bool}
	 */
	private function emptyCollection(string $state): array
	{
		return ['documents' => [], 'state' => $state, 'hasMore' => false];
	}

	/**
	 * @return array{documentId: array, title: string, url: string}|null
	 */
	private function resolveAvailableDocument(mixed $rawDocumentId, int $userId): ?array
	{
		try
		{
			$documentId = $this->parseDocumentId($rawDocumentId);
			if ($documentId === null)
			{
				return null;
			}

			// the order is mandatory: implementations of the title read the data bypassing the rights
			// (CCrmDocumentContact::getDocumentName() selects with CHECK_PERMISSIONS => 'N'), so a title
			// taken before the check is already a leak
			if (
				!\CBPDocument::canUserOperateDocument(
					\CBPCanUserOperateOperation::ReadDocument,
					$userId,
					$documentId,
				)
			)
			{
				return null;
			}

			$documentService = \CBPRuntime::getRuntime()->getDocumentService();

			// read apart and never through getDocumentNameAndUrl(): that one answers null when either
			// half is missing, so a document type without a card would silently lose all of its items
			$title = $documentService->getDocumentName($documentId);
			if (!\CBPHelper::hasStringRepresentation($title) || (string)$title === '')
			{
				// no title means the document is gone; a missing address only costs the item its link
				return null;
			}

			$url = $documentService->getDocumentAdminPage($documentId);

			return [
				'documentId' => $documentId,
				'title' => (string)$title,
				'url' => \CBPHelper::hasStringRepresentation($url) ? (string)$url : '',
			];
		}
		catch (\Throwable)
		{
			// a provider that threw on one identifier must not empty the whole group
			return null;
		}
	}

	/**
	 * Triplet of the identifier in the shape the snapshot stores it. Anything the engine cannot address
	 * is dropped instead of being guessed at, and the walk of the list goes on.
	 *
	 * @return array{string, string, string}|null
	 */
	private function parseDocumentId(mixed $rawDocumentId): ?array
	{
		if (!is_array($rawDocumentId) || count($rawDocumentId) < 3)
		{
			return null;
		}

		[$moduleId, $entity, $documentId] = \CBPHelper::parseDocumentId(array_values($rawDocumentId));
		if ((string)$entity === '' || (string)$documentId === '')
		{
			return null;
		}

		return [(string)$moduleId, (string)$entity, (string)$documentId];
	}

	private function isDocumentCollection(mixed $entry): bool
	{
		return is_array($entry)
			&& ($entry['type'] ?? '') === FieldType::DOCUMENT
			&& ($entry['multiple'] ?? false) === true
			&& is_array($entry['value'] ?? null)
		;
	}

	private function isSnapshotTruncated(array $entry): bool
	{
		if (($entry['truncated'] ?? false) === true)
		{
			return true;
		}

		$totalCount = $entry['totalCount'] ?? null;

		return is_int($totalCount) && count($entry['value']) < $totalCount;
	}

	/**
	 * Position of the item in the list handed out by getFoundItems(): the client passes the id back as
	 * it got it, so anything but a positive decimal number is not a position of that list.
	 */
	private function parsePosition(string $itemId): ?int
	{
		return preg_match('/^[1-9][0-9]*$/D', $itemId) === 1 ? (int)$itemId : null;
	}

	/**
	 * @param array{string, string, string} $documentId
	 * @param list<string> $fields
	 * @return array<string, array>
	 */
	private function readValues(array $documentId, array $fields): array
	{
		$requested = $this->normalizeFields($fields);
		if ($requested === [])
		{
			return [];
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$documentType = $documentService->getDocumentType($documentId);
		$schema = $documentType === null ? [] : (array)$documentService->getDocumentFields($documentType);

		// only the fields the document type publishes are read: several providers ignore the select of
		// getDocument() and answer with the whole document, and the client asks for schema fields anyway
		$requested = array_values(array_filter(
			$requested,
			static fn(string $field): bool => array_key_exists($field, $schema),
		));
		if ($requested === [])
		{
			return [];
		}

		$document = $documentService->getDocument($documentId, $documentType, $requested);
		if (!is_array($document) && !($document instanceof \ArrayAccess))
		{
			return [];
		}

		$values = [];
		foreach ($requested as $field)
		{
			// the answer is cut to the requested fields always, not only while enable_getdocument_select
			// is off: $select is an optional part of the provider contract
			if (!$this->hasField($document, $field))
			{
				continue;
			}

			$property = is_array($schema[$field] ?? null) ? $schema[$field] : [];
			$value = $document[$field];
			$multiple = (bool)($property['Multiple'] ?? is_array($value));
			if (!$multiple && is_array($value))
			{
				// the snapshot reduces an array in a single-valued field the same way: one form of a
				// value for both sources, or the client would show a scalar field as a collection
				$value = \CBPHelper::stringify($value);
			}

			$values[$field] = [
				// no translation, no formatting, no resolving: the presentation belongs to the client
				'value' => $value,
				'type' => (string)($property['Type'] ?? '') ?: FieldType::STRING,
				// the value is read in place, so no snapshot limit has ever cut it
				'truncated' => false,
				'multiple' => $multiple,
				'totalCount' => $multiple ? count((array)$value) : null,
			];
		}

		return $values;
	}

	/**
	 * A field the document has no value cell for at all is left out of the map entirely, which the
	 * client tells from an empty value. A provider answering with a lazy collection can only report
	 * the cell through isset(), so for such a provider a null value reads as an absent field.
	 */
	private function hasField(array|\ArrayAccess $document, string $field): bool
	{
		return is_array($document) ? array_key_exists($field, $document) : $document->offsetExists($field);
	}

	/**
	 * @param array $fields
	 * @return list<string>
	 */
	private function normalizeFields(array $fields): array
	{
		$normalized = [];
		foreach ($fields as $field)
		{
			if (is_string($field) && $field !== '')
			{
				$normalized[$field] = $field;
			}
		}

		return array_values($normalized);
	}

	/**
	 * Expressions the filter results of the template are addressed by, in the shape the snapshot keys
	 * them. This is the whole surface the actions accept and the whole set excludeFilterResults() cuts.
	 *
	 * @return array<string, true>
	 */
	private function collectFilterResultExpressions(int $templateId): array
	{
		if ($templateId <= 0)
		{
			return [];
		}

		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(['TEMPLATE'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		$activities = is_array($row) ? ($row['TEMPLATE'] ?? null) : null;

		return is_array($activities) ? $this->walkFilterResults($activities) : [];
	}

	/**
	 * @return array<string, true>
	 */
	private function walkFilterResults(array $activities): array
	{
		$expressions = [];

		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$blockId = (string)($activity['Name'] ?? '');
			$returnPropertiesMap = $activity['Properties'][self::FILTER_RETURN_PROPERTIES_MAP] ?? null;

			if ($blockId !== '' && is_array($returnPropertiesMap))
			{
				foreach (array_keys($returnPropertiesMap) as $property)
				{
					$expressions['{=' . $blockId . ':' . $property . '}'] = true;
				}
			}

			if (is_array($activity['Children'] ?? null))
			{
				$expressions += $this->walkFilterResults($activity['Children']);
			}
		}

		return $expressions;
	}
}
