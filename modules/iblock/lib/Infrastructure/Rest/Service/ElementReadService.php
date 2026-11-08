<?php

namespace Bitrix\Iblock\Infrastructure\Rest\Service;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\ORM\ElementEntity;
use Bitrix\Iblock\ORM\Fields\PropertyRelation;
use Bitrix\Iblock\ORM\ValueStorageEntity;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Field;
use Bitrix\Main\ORM\Fields\FieldTypeMask;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\ORM\Query\Chain;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * V3 read-path for default element. Ports V1 DefaultElement semantics without calling it.
 */
class ElementReadService
{
	private const ELEMENT_ALLOWED = [
		'ID',
		'IBLOCK_ID',
		'NAME',
		'IBLOCK_SECTION_ID',
	];

	private const PROPERTY_ALLOWED = [
		'ID',
		'VALUE',
		'DESCRIPTION',
	];

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(
		int $iblockId,
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $pagination = null,
	): array
	{
		return $this->query($iblockId, $select, $filter, $order, $pagination);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(
		int $iblockId,
		int $elementId,
		?SelectStructure $select = null,
	): array
	{
		$items = $this->query(
			$iblockId,
			$select,
			null,
			null,
			PaginationStructure::create(['limit' => 1]),
			$elementId,
		);

		if ($items === [])
		{
			throw new EntityNotFoundException($elementId);
		}

		return $items[0];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function query(
		int $iblockId,
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
		?int $elementId = null,
	): array
	{
		if ($iblockId <= 0)
		{
			throw new RequestValidationException([
				new Error('iblockId must be a positive integer.', 'iblockId'),
			]);
		}

		$iblock = IblockTable::getByPrimary($iblockId, [
			'select' => ['ID', 'API_CODE', 'REST_ON'],
		])->fetchObject();

		if ($iblock === null)
		{
			throw new RequestValidationException([
				new Error('Iblock not found.', 'iblockId'),
			]);
		}

		$elementEntity = IblockTable::compileEntity($iblock);
		if (!$elementEntity)
		{
			throw new RequestValidationException([
				new Error('Unable to compile iblock entity.', 'iblockId'),
			]);
		}

		if (!$elementEntity->getIblock()->fillRestOn())
		{
			throw new RequestValidationException([
				new Error(sprintf(
					'Restricted iblock ID:%s (%s)',
					$elementEntity->getIblock()->getId(),
					$elementEntity->getIblock()->getApiCode(),
				), 'iblockId'),
			]);
		}

		$elementDataClass = $elementEntity->getDataClass();
		$query = $elementDataClass::query();
		$query->addSelect(new ExpressionField('DISTINCT_ID', 'DISTINCT %s', 'ID'));

		if ($elementId !== null)
		{
			$query->where('ID', $elementId);
		}

		if ($filter !== null)
		{
			// Associative legacy filter (['=NAME' => ...]) goes to setFilter, not ConditionTree::createFromArray.
			$legacyFilter = LegacyQueryConverter::filterToLegacy($filter);
			if ($legacyFilter !== [])
			{
				$this->assertAllowedDefinitions($this->extractFilterDefinitions($legacyFilter), $elementEntity, 'filter');
				$query->setFilter($legacyFilter);
			}
		}

		$qOrder = LegacyQueryConverter::orderToLegacy($order);
		if ($qOrder !== [])
		{
			$this->assertAllowedDefinitions(array_keys($qOrder), $elementEntity, 'order');
			$query->setOrder($qOrder);
		}

		$qLimit = $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
		$qOffset = $pagination?->getOffset() ?? 0;
		if ($qLimit <= 0 || $qOffset < 0)
		{
			throw new RequestValidationException([
				new Error('Pagination limit must be positive and offset must be non-negative.', 'pagination'),
			]);
		}

		$result = $query
			->setLimit($qLimit)
			->setOffset($qOffset)
			->exec()
		;

		$ids = [];
		foreach ($result->fetchAll() as $row)
		{
			$ids[] = $row['DISTINCT_ID'];
		}

		$elements = [];
		if ($ids !== [])
		{
			$selectFields = $this->prepareSelect($select, $elementEntity);
			$dataQuery = $elementDataClass::query();
			$dataQuery->setSelect($selectFields);
			$dataQuery->whereIn('ID', $ids);

			$resultElements = [];
			foreach ($dataQuery->fetchCollection() as $elementObject)
			{
				/** @var EntityObject $elementObject */
				$resultElements[$elementObject->getId()] = $elementObject->collectValues(
					Values::ALL,
					FieldTypeMask::ALL,
					true,
				);
			}

			foreach ($ids as $id)
			{
				if (isset($resultElements[$id]))
				{
					$elements[] = $resultElements[$id];
				}
			}
		}

		return $elements;
	}

	private function prepareSelect(?SelectStructure $select, Entity $entity): array
	{
		$fields = ['*'];
		if ($select !== null)
		{
			$structured = $select->getStructuredList();
			if ($structured !== [])
			{
				$converter = new Converter(Converter::TO_UPPER | Converter::TO_SNAKE);
				$fields = [];
				foreach ($structured as $field)
				{
					if ($field === 'properties')
					{
						foreach ($this->listPropertyRelationNames($entity) as $propertyName)
						{
							$fields[] = $propertyName;
						}

						continue;
					}
					$fields[] = is_string($field) ? (string)$converter->process($field) : $field;
				}
				$fields = array_values(array_unique($fields));
				if ($fields === [])
				{
					$fields = ['*'];
				}
			}
		}

		try
		{
			return $this->checkFields($fields, $entity);
		}
		catch (ArgumentException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage(), 'select'),
			]);
		}
	}

	/**
	 * @return list<string>
	 */
	private function listPropertyRelationNames(Entity $entity): array
	{
		$names = [];
		foreach ($entity->getFields() as $field)
		{
			if (!empty(class_uses($field)[PropertyRelation::class]))
			{
				$names[] = $field->getName();
			}
		}

		return $names;
	}

	/**
	 * @param list<string> $definitions
	 */
	private function assertAllowedDefinitions(array $definitions, Entity $entity, string $errorCode): void
	{
		try
		{
			$this->checkFields($definitions, $entity);
		}
		catch (ArgumentException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage(), $errorCode),
			]);
		}
	}

	/**
	 * @param array $filter
	 * @return list<string>
	 */
	private function extractFilterDefinitions(array $filter): array
	{
		$definitions = [];
		foreach ($filter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				continue;
			}

			if (is_string($key))
			{
				$definitions[] = ltrim($key, '=!<>@%*');
			}

			if (is_array($value))
			{
				$definitions = array_merge($definitions, $this->extractFilterDefinitions($value));
			}
		}

		return array_values(array_unique(array_filter($definitions, static fn($v) => is_string($v) && $v !== '')));
	}

	/**
	 * Port of V1 DefaultElement::checkFields - keep allowlist parity.
	 *
	 * @param list<string> $fields
	 * @return list<string>
	 */
	private function checkFields(array $fields, Entity $entity): array
	{
		$propertyEntityAllowedList = self::PROPERTY_ALLOWED;
		$elementEntityAllowedList = self::ELEMENT_ALLOWED;
		$allowedList = $elementEntityAllowedList;
		$chainReplacement = [];

		foreach ($fields as $definition)
		{
			if (in_array($definition, $allowedList, true))
			{
				continue;
			}

			$chain = Chain::getChainByDefinition($entity, $definition);
			$currentDefinition = '';
			$elements = $chain->getAllElements();
			$lastElement = $chain->getLastElement();

			foreach ($elements as $element)
			{
				$isLastElement = ($element === $lastElement);

				if ($element->getValue() instanceof ElementEntity && !$isLastElement)
				{
					continue;
				}

				$currentDefinition = Chain::appendDefinition($currentDefinition, $element->getDefinitionFragment());

				if ($currentDefinition === '*' && $isLastElement)
				{
					$chainReplacement[$definition] = [];
					foreach ($elementEntityAllowedList as $allowedFieldName)
					{
						if ($entity->hasField($allowedFieldName))
						{
							$chainReplacement[$definition][] = $allowedFieldName;
						}
					}

					continue;
				}

				if (!$element->getValue() instanceof Field)
				{
					throw new ArgumentException(sprintf('Restricted field `%s`', $currentDefinition));
				}

				$currentField = $element->getValue();
				$currentEntity = $currentField->getEntity();

				if ($currentEntity instanceof ElementEntity)
				{
					if (in_array($currentField->getName(), $elementEntityAllowedList, true))
					{
						continue;
					}

					if (!empty(class_uses($currentField)[PropertyRelation::class]))
					{
						if ($isLastElement)
						{
							$propEntity = $currentField->getRefEntity();
							$chainReplacement[$definition] = [];
							foreach ($propertyEntityAllowedList as $allowedFieldName)
							{
								if ($propEntity->hasField($allowedFieldName))
								{
									$chainReplacement[$definition][] = Chain::appendDefinition(
										$currentDefinition,
										$allowedFieldName,
									);
								}
							}
						}

						continue;
					}
				}

				if ($currentEntity instanceof ValueStorageEntity)
				{
					if (in_array($currentField->getName(), $propertyEntityAllowedList, true))
					{
						continue;
					}

					if ($currentField instanceof Reference)
					{
						$refEntity = $currentField->getRefEntity();
						if ($refEntity instanceof ElementEntity && $refEntity->getIblock()->fillRestOn())
						{
							if ($isLastElement)
							{
								$chainReplacement[$definition] = [];
								foreach ($elementEntityAllowedList as $allowedFieldName)
								{
									if ($refEntity->hasField($allowedFieldName))
									{
										$chainReplacement[$definition][] = Chain::appendDefinition(
											$currentDefinition,
											$allowedFieldName,
										);
									}
								}
							}

							continue;
						}
					}
				}

				throw new ArgumentException(sprintf('Restricted field `%s`', $currentDefinition));
			}
		}

		foreach ($chainReplacement as $definition => $replacement)
		{
			$key = array_search($definition, $fields, true);
			if ($key !== false)
			{
				unset($fields[$key]);
			}
			$fields = array_merge($fields, $replacement);
		}

		return array_values($fields);
	}
}
