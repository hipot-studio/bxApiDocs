<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentField;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldCollection;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldOption;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldOptionCollection;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

class DocumentFieldService
{
	private SemanticSearch\SemanticSearchInterface $semanticSearch;

	public function __construct()
	{
		$this->semanticSearch = new SemanticSearch\SemanticSearchService();
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

		return $this->tryFromSearch($documentType, $searchField);
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

	private function tryFromSearch(
		DocumentDescription $documentType,
		?string $searchField,
	): DocumentFieldCollection
	{
		$items = new DocumentFieldCollection();

		if (empty($searchField))
		{
			return $items;
		}

		try
		{
			$result =
				$this->semanticSearch->search(
					$searchField,
					new SemanticSearch\Scope(
						SemanticSearch\ScopeType::Fields,
						$documentType->toBizprocComplexType(),
					),
				);

			if (!$result->isSuccess() || !isset($result->getData()['documentCollection']))
			{
				return $items;
			}

			/** @var SemanticSearch\Payload\DocumentCollection $collection */
			$collection = $result->getData()['documentCollection'];

			$fields = $this->getDocumentFields($documentType);

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
				'Error while searching document fields: ' . $e->getMessage(),
				[
					'documentType' => $documentType->toArray(),
					'searchField' => $searchField,
				],
			);

			return $items;
		}

		return $items;
	}

	/**
	 * @param DocumentDescription $documentType
	 *
	 * @return DocumentFieldCollection|null
	 */
	public function getDocumentFieldsCollection(DocumentDescription $documentType): ?DocumentFieldCollection
	{
		$fields = $this->getDocumentFields($documentType);
		if (empty($fields) || !is_array($fields))
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