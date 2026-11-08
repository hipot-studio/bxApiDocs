<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentField;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldCollection;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldOption;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldOptionCollection;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\DocumentFieldSearchMode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\DocumentFieldSearchResult;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

final class DocumentFieldService
{
	private ?SemanticSearch\SemanticSearchInterface $semanticSearch = null;

	/**
	 * Fields closest to the search phrase, or the whole set when there is nothing to search by or the
	 * semantic search service is not configured.
	 *
	 * The collection-shaped projection of {@see self::readBoundTypeFields()}, which is where the legacy rules
	 * live. Null still means the field set could not be read, exactly as before.
	 *
	 * @throws LoaderException|ArgumentException
	 */
	public function findFields(DocumentDescription $documentType, string $search = ''): ?DocumentFieldCollection
	{
		return $this->readBoundTypeFields($documentType, $search)->fields;
	}

	/**
	 * The fields of the document type the token is bound to, with the search outcomes this read has always
	 * had (BRIEF § 6.4): a phrase the semantic search cannot answer - because it is not configured - falls
	 * back to the whole set, and a configured search that fails answers with an empty set rather than with a
	 * refusal. Only the failure to read the field set itself is a failure here.
	 *
	 * @throws LoaderException|ArgumentException
	 */
	public function readBoundTypeFields(
		DocumentDescription $documentType,
		string $search = '',
	): DocumentFieldSearchResult
	{
		if ($search === '' || !$this->isSemanticSearchConfigured())
		{
			return $this->wholeSet($documentType, DocumentFieldSearchMode::All);
		}

		$searched = $this->readSemanticSearch($documentType, $search);

		// The three ways the semantic search can come back with nothing are one answer on this path, and that
		// answer is a successful empty list: this is what every client of this action has been getting. A
		// failure to read the field set at all is not one of them - that one is about the portal and keeps its
		// own outcome on both paths (BRIEF § 6.4).
		return $searched->isSuccess() || $searched->areFieldsUnavailable
			? $searched
			: DocumentFieldSearchResult::answered(new DocumentFieldCollection(), DocumentFieldSearchMode::Semantic)
		;
	}

	/**
	 * The fields of a document type the caller named, where the outcomes are told apart (BRIEF § 6.4): a
	 * portal without the semantic search narrows by substring instead of answering with everything, and a
	 * configured search that fails is a failure rather than an empty answer.
	 *
	 * @throws LoaderException|ArgumentException
	 */
	public function readRequestedTypeFields(
		DocumentDescription $documentType,
		string $search = '',
	): DocumentFieldSearchResult
	{
		if ($search === '')
		{
			return $this->wholeSet($documentType, DocumentFieldSearchMode::All);
		}

		if ($this->isSemanticSearchConfigured())
		{
			return $this->readSemanticSearch($documentType, $search);
		}

		$whole = $this->wholeSet($documentType, DocumentFieldSearchMode::Substring);

		return $whole->isSuccess()
			? DocumentFieldSearchResult::answered(
				self::narrowBySubstring($whole->fields, $search),
				DocumentFieldSearchMode::Substring,
			)
			: $whole
		;
	}

	private function isSemanticSearchConfigured(): bool
	{
		return !empty(Configuration::getValue('semantic_search')['url']);
	}

	/**
	 * @throws LoaderException|ArgumentException
	 */
	public function getFields(
		DocumentDescription $documentType,
		?string $searchField = null,
	): DocumentFieldCollection
	{
		$items = new DocumentFieldCollection();

		if (!Loader::includeModule('bizproc'))
		{
			return $items;
		}

		return $this->tryFromSearch($documentType, $searchField)->fields ?? $items;
	}

	/**
	 * @throws LoaderException|ArgumentException
	 */
	private function wholeSet(
		DocumentDescription $documentType,
		DocumentFieldSearchMode $mode,
	): DocumentFieldSearchResult
	{
		$fields = $this->getDocumentFieldsCollection($documentType);

		return $fields === null
			? DocumentFieldSearchResult::fieldsUnavailable($mode)
			: DocumentFieldSearchResult::answered($fields, $mode)
		;
	}

	/**
	 * @throws LoaderException|ArgumentException
	 */
	private function readSemanticSearch(
		DocumentDescription $documentType,
		string $search,
	): DocumentFieldSearchResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return DocumentFieldSearchResult::fieldsUnavailable(DocumentFieldSearchMode::Semantic);
		}

		return $this->tryFromSearch($documentType, $search);
	}

	/**
	 * The fields whose code or name contains the phrase, compared without regard to case. What a portal with
	 * no semantic search can honestly offer: it is not a search by meaning and says so through its mode.
	 */
	private static function narrowBySubstring(
		DocumentFieldCollection $fields,
		string $search,
	): DocumentFieldCollection
	{
		$narrowed = new DocumentFieldCollection();
		foreach ($fields as $field)
		{
			/** @var DocumentField $field */
			if (mb_stripos($field->id, $search) !== false || mb_stripos($field->name, $search) !== false)
			{
				$narrowed->add($field);
			}
		}

		return $narrowed;
	}

	private function getOptions(array $field): ?DocumentFieldOptionCollection
	{
		if (empty($field['Options']) || !is_array($field['Options']))
		{
			return null;
		}

		$options = new DocumentFieldOptionCollection();
		foreach ($field['Options'] as $key => $value)
		{
			$options->add(
				new DocumentFieldOption(
					id: (string)$key,
					name: (string)$value,
				),
			);
		}

		return $options;
	}

	/**
	 * One pass of the semantic search, as an outcome rather than as a collection.
	 *
	 * Three situations used to leave here as the same empty collection - nothing matched, the service replied
	 * without success, and the call threw - and an agent cannot act on the difference it was not told. The
	 * last two are the service failing to deliver and are reported as such; only the first is an answer.
	 * Which of the two the caller then shows the agent is the caller's rule (BRIEF § 6.4).
	 */
	private function tryFromSearch(
		DocumentDescription $documentType,
		?string $searchField,
	): DocumentFieldSearchResult
	{
		$items = new DocumentFieldCollection();

		if (empty($searchField))
		{
			return DocumentFieldSearchResult::answered($items, DocumentFieldSearchMode::Semantic);
		}

		try
		{
			$this->semanticSearch ??= new SemanticSearch\SemanticSearchService();
			$result
				= $this->semanticSearch->search(
					$searchField,
					new SemanticSearch\Scope(
						SemanticSearch\ScopeType::Fields,
						$documentType->toBizprocComplexType(),
					),
				);

			// No result set in a reply that carries no error either: the exchange did not deliver. An answer of
			// "nothing matched" comes as an empty collection and is handled below, as an answer.
			if (!$result->isSuccess() || !isset($result->getData()['documentCollection']))
			{
				return DocumentFieldSearchResult::searchUnavailable();
			}

			/** @var SemanticSearch\Payload\DocumentCollection $collection */
			$collection = $result->getData()['documentCollection'];

			// One read of the field set for the whole answer: the search returns ids, and looking each of them
			// up separately would re-read the schema of the type per result.
			$fields = $this->getDocumentFields($documentType);
			if ($fields === null)
			{
				return DocumentFieldSearchResult::fieldsUnavailable(DocumentFieldSearchMode::Semantic);
			}

			foreach ($collection as $payload)
			{
				$field = $fields[$payload->id] ?? null;

				if (!$field)
				{
					continue;
				}

				$items->add(
					$this->createField(
						$payload->id,
						$field,
					),
				);
			}
		}
		catch (\Exception $e)
		{
			Container::getDefaultLogger()->error(
				'Error while searching document fields of {documentType} by "{searchField}": ' . $e->getMessage(),
				[
					'documentType' => $documentType->toArray(),
					'searchField' => $searchField,
				],
			);

			return DocumentFieldSearchResult::searchUnavailable();
		}

		return DocumentFieldSearchResult::answered($items, DocumentFieldSearchMode::Semantic);
	}

	/**
	 * The fields of the type as a collection, or null when the field set could not be READ at all.
	 *
	 * A type the portal reads and finds no field on answers with an empty collection, not with null: the two
	 * were one answer before, and telling them apart is what keeps the field read of the bound type the
	 * success it has always been while a real failure stays `DocumentFieldsUnavailable` (BRIEF § 6.4).
	 *
	 * @param DocumentDescription $documentType
	 *
	 * @return DocumentFieldCollection|null
	 */
	public function getDocumentFieldsCollection(DocumentDescription $documentType): ?DocumentFieldCollection
	{
		$fields = $this->getDocumentFields($documentType);
		if ($fields === null)
		{
			return null;
		}

		$collection = new DocumentFieldCollection();
		foreach ($fields as $id => $field)
		{
			$collection->add(
				$this->createField(
					id: $id,
					field: $field,
				),
			);
		}

		return $collection;
	}

	/**
	 * @param DocumentDescription $documentType
	 *
	 * @return array|null
	 */
	private function getDocumentFields(DocumentDescription $documentType): ?array
	{
		static $cache = [];

		$key = implode(':', $documentType->toBizprocComplexType());
		if (array_key_exists($key, $cache))
		{
			return $cache[$key];
		}

		$cache[$key] = \CBPRuntime::GetRuntime()
			->getDocumentService()
			->getDocumentFields($documentType->toBizprocComplexType())
		;

		return $cache[$key];
	}

	private function createField(int|string $id, mixed $field): DocumentField
	{
		return new DocumentField(
			id: $id,
			name: (string)($field['Name'] ?? ''),
			editable: !empty($field['Editable']),
			options: $this->getOptions($field),
		);
	}
}
