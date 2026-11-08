<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\FormDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\UpdateFormRequest;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormPath;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormWriteContext;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use LogicException;

/**
 * Reads the `fields` of a write request as the change set of the domain. Mirror of
 * {@see FormRequestMapper} on the writing side.
 *
 * It works on the keys the client actually sent ({@see FieldsStructure::getItems()}) rather than on
 * the DTO those keys would fill. The whole contract of a partial update stands on telling three
 * states apart - a key that is absent, a key holding null and a key holding a value - and a DTO
 * cannot hold the difference: filling it turns an unsent section into a default one, an unsent
 * collection into an empty one, and a null collection into an empty one as well, which is the
 * silent substitution the contract exists to prevent. The published format is still the one of
 * {@see FormDto}: same names, same types, one format for reading and for writing.
 *
 * Which write the document stands for is told by the action that received it and is not guessed
 * here: a creation and a change carry different sets ({@see WebFormWriteContext}), and the activity
 * of a form belongs to the first alone. A path the context does not carry is refused as any other
 * path outside the contract, by the very same answer - the property is published for reading, and a
 * change refusing it says the one thing that is true of it there.
 *
 * Three kinds of refusal come out of here, and they are told apart by what is wrong:
 *
 * - a path the published projection does not have at all - the embedding of task 731575, a
 *   misspelled property, anything unknown - is answered the way the framework answers an
 *   unavailable property, raised at once as {@see UnknownDtoPropertyException};
 * - a path the projection publishes and the write contract does not carry in this context - a
 *   read-only field, the `booking` section derived by the server, the order of a field, the
 *   activity on a change - is a refusal of the value sent: the property exists and can be read
 *   back, so calling it unknown would misdescribe the contract;
 * - a value that breaks the contract at a path the contract does carry is refused the same way.
 *
 * The last two are collected, and every violation of the document travels out together in one
 * {@see FormValidationException}, each error coded by the input path it belongs to.
 *
 * Nothing of the domain is decided here. Whether the name is filled, whether the CRM scheme exists,
 * whether a dependency points at a field of the form - all of it belongs to the write layer, which
 * is the only side that knows what the form already holds.
 *
 * Every refusal names the input by {@see WebFormPath}, the one notation the domain validation uses
 * as well: told in two notations, one property would get two public addresses depending on the
 * stage that refused it.
 */
final class FormWriteRequestMapper
{
	use ReadsWrittenScalars;

	/**
	 * Items one collection accepts, nested collections included. The section writers store a
	 * collection row by row and without a transaction, so an unbounded list turns one request into
	 * an arbitrarily expensive operation. The limit is far above any meaningful use: a real form
	 * holds tens of fields and a handful of agreements.
	 */
	private const MAX_COLLECTION_SIZE = 500;

	/**
	 * Items the whole document accepts, every list of it counted together. The limit on a single
	 * list bounds nothing on its own, because lists multiply: five hundred dependency groups of
	 * five hundred rules each have one request write a quarter of a million rows, which outlasts
	 * the execution limit several times over and, with no transaction around the write, leaves the
	 * section half stored. The threshold keeps a fourfold margin over the richest meaningful
	 * document - a form of hundreds of fields comes to some five hundred items in total.
	 */
	private const MAX_DOCUMENT_SIZE = 2000;

	/** items of the document accepted so far, every list counted together */
	private int $acceptedItems = 0;

	/** whether the document has already been refused for the number of items it carries */
	private bool $documentOverflowed = false;

	/**
	 * The change set the fields of {@see AddRequest} or {@see UpdateFormRequest} describe.
	 *
	 * @throws UnknownDtoPropertyException when the input names a path no projection publishes
	 * @throws FormValidationException when the document breaks the write contract - a read-only path
	 *         included; all violations are carried at once
	 */
	public function mapChangeSet(FieldsStructure $fields, WebFormWriteContext $context): WebFormChangeSet
	{
		$this->acceptedItems = 0;
		$this->documentOverflowed = false;

		$errors = [];
		$values = $this->valuesOf($fields->getItems(), '', $context, $errors);

		if ($errors !== [])
		{
			throw new FormValidationException(...$errors);
		}

		return new WebFormChangeSet($values);
	}

	/**
	 * One level of the input document, keyed by the canonical path each key stands for. A section is
	 * read through its own properties, so nothing but scalar and collection paths comes out.
	 *
	 * @param array<string|int, mixed> $node
	 * @param Error[] $errors
	 * @return array<string, mixed>
	 */
	private function valuesOf(array $node, string $prefix, WebFormWriteContext $context, array &$errors): array
	{
		$values = [];
		foreach ($node as $property => $value)
		{
			$path = WebFormPath::property($prefix, (string)$property);
			if (!WebFormChangeSet::isWritablePath($path, $context))
			{
				self::refuseOutsideTheWriteContract(
					$path,
					self::projection()->fieldAt($path) !== null,
					$errors,
				);

				continue;
			}

			$kind = WebFormChangeSet::kindOf($path);

			if ($kind === WebFormChangeSet::KIND_SECTION)
			{
				if (!is_array($value))
				{
					$errors[] = new Error(
						$value === null
							? sprintf('Section `%s` is cleared through its properties, not as a whole', $path)
							: sprintf('Section `%s` expects an object', $path),
						$path,
					);

					continue;
				}

				$values = array_merge($values, $this->valuesOf($value, $path, $context, $errors));

				continue;
			}

			if ($kind === WebFormChangeSet::KIND_COLLECTION)
			{
				$items = $this->collectionOf($path, $value, $errors);
				if ($items !== null)
				{
					$values[$path] = $items;
				}

				continue;
			}

			if ($value !== null && !is_scalar($value))
			{
				$errors[] = new Error(sprintf('`%s` expects a scalar value or null', $path), $path);

				continue;
			}

			$refusalsBefore = count($errors);
			$scalar = $this->scalarOf($path, $value, $errors);
			if (count($errors) === $refusalsBefore)
			{
				$values[$path] = $scalar;
			}
		}

		return $values;
	}

	/**
	 * A whole collection: the input replaces it, an empty list clears it, and null is refused - one
	 * form for one intention. A list the contract has no room for is refused at the path it arrived
	 * on, whether it is too long by itself or leaves the document past its total.
	 *
	 * An item holding null is refused at the address it arrived on for the same reason the whole
	 * collection is: dropped instead, it would turn a list of one wrong identifier into a request
	 * that successfully clears the collection.
	 *
	 * @param Error[] $errors
	 * @return array<int, mixed>|null null when the value is no collection the contract accepts
	 */
	private function collectionOf(string $path, mixed $value, array &$errors): ?array
	{
		if ($value === null)
		{
			$errors[] = new Error(
				sprintf('Collection `%s` is cleared by an empty list, not by null', $path),
				$path,
			);

			return null;
		}

		if (!is_array($value) || !array_is_list($value))
		{
			$errors[] = new Error(sprintf('Collection `%s` expects a list of items', $path), $path);

			return null;
		}

		$accepted = $this->acceptedList($path, $value, $errors);
		if ($accepted === null)
		{
			return null;
		}

		$items = [];
		foreach ($accepted as $index => $element)
		{
			$itemPath = WebFormPath::item($path, $index);
			if ($element === null)
			{
				$errors[] = new Error(
					sprintf('`%s` expects a value: a collection is cleared by an empty list', $itemPath),
					$itemPath,
				);

				continue;
			}

			$item = $this->itemOf($path, $itemPath, $element, $errors);
			if ($item !== null)
			{
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * @param Error[] $errors
	 * @throws LogicException when the model publishes a collection this mapping has no reader for
	 */
	private function itemOf(string $collection, string $path, mixed $element, array &$errors): mixed
	{
		return match ($collection)
		{
			WebForm::SECTION_FIELDS => self::fieldOf($path, $element, $errors),
			WebForm::SECTION_DEPENDENCIES => $this->dependencyGroupOf($path, $element, $errors),
			WebForm::SECTION_PRESET_FIELDS => self::presetFieldOf($path, $element, $errors),
			WebFormPath::RESPONSIBLE_USER_IDS => self::asInt($element, $path, $errors),
			WebFormPath::AGREEMENT_ITEMS => self::agreementItemOf($path, $element, $errors),
			WebFormPath::ADS => $this->adIntegrationOf($path, $element, $errors),
			WebFormPath::DISABLED_PAY_SYSTEMS_ID => self::asInt($element, $path, $errors),
			default => throw new LogicException(
				sprintf('Web form collection `%s` has no reader in the write mapping', $collection),
			),
		};
	}

	/**
	 * @param Error[] $errors
	 */
	private static function fieldOf(string $path, mixed $element, array &$errors): ?Section\Field
	{
		$values = self::propertiesOf($element, $path, WebForm::SECTION_FIELDS, $errors);
		if ($values === null)
		{
			return null;
		}

		return new Section\Field(
			name: self::stringAt($values, Section\Field::FIELD_NAME, $path, $errors),
			type: self::stringAt($values, Section\Field::FIELD_TYPE, $path, $errors),
			label: self::stringAt($values, Section\Field::FIELD_LABEL, $path, $errors),
			required: self::boolAt($values, Section\Field::FIELD_REQUIRED, $path, $errors),
			multiple: self::boolAt($values, Section\Field::FIELD_MULTIPLE, $path, $errors),
			placeholder: self::stringAt($values, Section\Field::FIELD_PLACEHOLDER, $path, $errors),
			valueType: self::stringAt($values, Section\Field::FIELD_VALUE_TYPE, $path, $errors),
			value: self::stringAt($values, Section\Field::FIELD_VALUE, $path, $errors),
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function presetFieldOf(string $path, mixed $element, array &$errors): ?Section\PresetField
	{
		$values = self::propertiesOf($element, $path, WebForm::SECTION_PRESET_FIELDS, $errors);
		if ($values === null)
		{
			return null;
		}

		return new Section\PresetField(
			entityName: self::stringAt($values, Section\PresetField::FIELD_ENTITY_NAME, $path, $errors),
			fieldName: self::stringAt($values, Section\PresetField::FIELD_FIELD_NAME, $path, $errors),
			value: self::stringAt($values, Section\PresetField::FIELD_VALUE, $path, $errors),
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function agreementItemOf(string $path, mixed $element, array &$errors): ?Section\AgreementItem
	{
		$values = self::propertiesOf($element, $path, WebFormPath::AGREEMENT_ITEMS, $errors);
		if ($values === null)
		{
			return null;
		}

		return new Section\AgreementItem(
			agreementId: self::intAt($values, Section\AgreementItem::FIELD_AGREEMENT_ID, $path, $errors),
			checked: self::boolAt($values, Section\AgreementItem::FIELD_CHECKED, $path, $errors),
			required: self::boolAt($values, Section\AgreementItem::FIELD_REQUIRED, $path, $errors),
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private function dependencyGroupOf(string $path, mixed $element, array &$errors): ?Section\DependencyGroup
	{
		$values = self::propertiesOf($element, $path, WebForm::SECTION_DEPENDENCIES, $errors);
		if ($values === null)
		{
			return null;
		}

		$rulesPath = WebFormPath::property($path, Section\DependencyGroup::FIELD_RULES);
		$rawRules = $this->nestedListAt($values, Section\DependencyGroup::FIELD_RULES, $path, $errors);
		$rules = null;
		if ($rawRules !== null)
		{
			$rules = [];
			foreach ($rawRules as $index => $rawRule)
			{
				$rule = self::dependencyRuleOf(WebFormPath::item($rulesPath, $index), $rawRule, $errors);
				if ($rule !== null)
				{
					$rules[] = $rule;
				}
			}
		}

		return new Section\DependencyGroup(
			logic: self::stringAt($values, Section\DependencyGroup::FIELD_LOGIC, $path, $errors),
			rules: $rules,
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function dependencyRuleOf(string $path, mixed $element, array &$errors): ?Section\DependencyRule
	{
		$values = self::propertiesOf($element, $path, WebFormPath::DEPENDENCY_RULES, $errors);
		if ($values === null)
		{
			return null;
		}

		$rawCondition = $values[Section\DependencyRule::FIELD_CONDITION] ?? null;
		$rawAction = $values[Section\DependencyRule::FIELD_ACTION] ?? null;

		return new Section\DependencyRule(
			condition: $rawCondition === null ? null : self::dependencyConditionOf(
				WebFormPath::property($path, Section\DependencyRule::FIELD_CONDITION),
				$rawCondition,
				$errors,
			),
			action: $rawAction === null ? null : self::dependencyActionOf(
				WebFormPath::property($path, Section\DependencyRule::FIELD_ACTION),
				$rawAction,
				$errors,
			),
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function dependencyConditionOf(
		string $path,
		mixed $element,
		array &$errors,
	): ?Section\DependencyCondition
	{
		$values = self::propertiesOf($element, $path, WebFormPath::DEPENDENCY_CONDITION, $errors);
		if ($values === null)
		{
			return null;
		}

		return new Section\DependencyCondition(
			fieldName: self::stringAt($values, Section\DependencyCondition::FIELD_FIELD_NAME, $path, $errors),
			event: self::stringAt($values, Section\DependencyCondition::FIELD_EVENT, $path, $errors),
			value: self::stringAt($values, Section\DependencyCondition::FIELD_VALUE, $path, $errors),
			operation: self::stringAt($values, Section\DependencyCondition::FIELD_OPERATION, $path, $errors),
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function dependencyActionOf(string $path, mixed $element, array &$errors): ?Section\DependencyAction
	{
		$values = self::propertiesOf($element, $path, WebFormPath::DEPENDENCY_ACTION, $errors);
		if ($values === null)
		{
			return null;
		}

		return new Section\DependencyAction(
			fieldName: self::stringAt($values, Section\DependencyAction::FIELD_FIELD_NAME, $path, $errors),
			type: self::stringAt($values, Section\DependencyAction::FIELD_TYPE, $path, $errors),
			value: self::stringAt($values, Section\DependencyAction::FIELD_VALUE, $path, $errors),
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private function adIntegrationOf(string $path, mixed $element, array &$errors): ?Section\AdIntegration
	{
		$values = self::propertiesOf($element, $path, WebFormPath::ADS, $errors);
		if ($values === null)
		{
			return null;
		}

		$mappingsPath = WebFormPath::property($path, Section\AdIntegration::FIELD_FIELD_MAPPINGS);
		$rawMappings = $this->nestedListAt($values, Section\AdIntegration::FIELD_FIELD_MAPPINGS, $path, $errors);
		$mappings = null;
		if ($rawMappings !== null)
		{
			$mappings = [];
			foreach ($rawMappings as $index => $rawMapping)
			{
				$mapping = self::adFieldMappingOf(WebFormPath::item($mappingsPath, $index), $rawMapping, $errors);
				if ($mapping !== null)
				{
					$mappings[] = $mapping;
				}
			}
		}

		return new Section\AdIntegration(
			direction: self::intAt($values, Section\AdIntegration::FIELD_DIRECTION, $path, $errors),
			providerCode: self::stringAt($values, Section\AdIntegration::FIELD_PROVIDER_CODE, $path, $errors),
			accountId: self::stringAt($values, Section\AdIntegration::FIELD_ACCOUNT_ID, $path, $errors),
			accountName: self::stringAt($values, Section\AdIntegration::FIELD_ACCOUNT_NAME, $path, $errors),
			formId: self::stringAt($values, Section\AdIntegration::FIELD_FORM_ID, $path, $errors),
			formName: self::stringAt($values, Section\AdIntegration::FIELD_FORM_NAME, $path, $errors),
			fieldMappings: $mappings,
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function adFieldMappingOf(string $path, mixed $element, array &$errors): ?Section\AdFieldMapping
	{
		$values = self::propertiesOf($element, $path, WebFormPath::AD_FIELD_MAPPINGS, $errors);
		if ($values === null)
		{
			return null;
		}

		return new Section\AdFieldMapping(
			crmFieldName: self::stringAt($values, Section\AdFieldMapping::FIELD_CRM_FIELD_NAME, $path, $errors),
			adsFieldName: self::stringAt($values, Section\AdFieldMapping::FIELD_ADS_FIELD_NAME, $path, $errors),
		);
	}

	/**
	 * The properties of one element of a collection. What the element may carry is the model talking
	 * ({@see WebFormChangeSet::getWritableItemProperties()}), so the accepted set has one place to
	 * live: a property the contract does not carry is refused, and a property standing at null is not
	 * specified, so the element takes the value the creation defaults to or keeps the one it has.
	 *
	 * @param string $itemPath the path of the element, indexes elided, the model answers about
	 * @param Error[] $errors
	 * @return array<string, mixed>|null null when the element is no object at all
	 */
	private static function propertiesOf(mixed $element, string $path, string $itemPath, array &$errors): ?array
	{
		if (!is_array($element) || (array_is_list($element) && $element !== []))
		{
			$errors[] = new Error(sprintf('`%s` expects an object', $path), $path);

			return null;
		}

		$writable = WebFormChangeSet::getWritableItemProperties($itemPath);
		$published = self::projection()->propertiesAt($itemPath);
		foreach (array_keys($element) as $property)
		{
			$property = (string)$property;
			if (!in_array($property, $writable, true))
			{
				self::refuseOutsideTheWriteContract(
					WebFormPath::property($path, $property),
					in_array($property, $published, true),
					$errors,
				);
			}
		}

		return $element;
	}

	/**
	 * A list standing inside an element of a collection. Null means it is not specified, so it stays
	 * unspecified instead of turning into an empty list.
	 *
	 * @param array<string, mixed> $values
	 * @param Error[] $errors
	 * @return array<int, mixed>|null
	 */
	private function nestedListAt(array $values, string $property, string $path, array &$errors): ?array
	{
		$value = $values[$property] ?? null;
		if ($value === null)
		{
			return null;
		}

		if (!is_array($value) || !array_is_list($value))
		{
			$errors[] = self::refusal(WebFormPath::property($path, $property), 'a list of items');

			return null;
		}

		return $this->acceptedList(WebFormPath::property($path, $property), $value, $errors);
	}

	/**
	 * A list the document has room for, weighed against both limits: the one on a single list and
	 * the one on the whole document. The refusal comes before a single element of the list is read,
	 * so an oversized document costs neither the errors of its elements nor a row of writing. The
	 * total is reported once, at the path the room ran out on: it is one fact about the document,
	 * and every list after that one would repeat it.
	 *
	 * @param array<int, mixed> $list
	 * @param Error[] $errors
	 * @return array<int, mixed>|null null when the list does not fit
	 */
	private function acceptedList(string $path, array $list, array &$errors): ?array
	{
		if (count($list) > self::MAX_COLLECTION_SIZE)
		{
			$errors[] = self::sizeRefusal($path);

			return null;
		}

		$this->acceptedItems += count($list);
		if ($this->acceptedItems <= self::MAX_DOCUMENT_SIZE)
		{
			return $list;
		}

		if (!$this->documentOverflowed)
		{
			$this->documentOverflowed = true;
			$errors[] = self::documentSizeRefusal($path);
		}

		return null;
	}

	/**
	 * A scalar of the contract, read as the type the published projection gives it. Null clears the
	 * path and needs no type at all.
	 *
	 * @param Error[] $errors
	 */
	private function scalarOf(string $path, mixed $value, array &$errors): mixed
	{
		return match (self::projection()->fieldAt($path)?->getPropertyType())
		{
			'bool' => self::asBool($value, $path, $errors),
			'int' => self::asInt($value, $path, $errors),
			'string' => self::asString($value, $path, $errors),
			default => $value,
		};
	}

	/**
	 * Refuses a path the write contract does not carry, by what the published projection says about
	 * it: a property a client can read back is refused as a value that may not be written, and one
	 * the projection has never heard of is answered as an unavailable property, the way the
	 * framework answers it.
	 *
	 * @param Error[] $errors
	 * @throws UnknownDtoPropertyException
	 */
	private static function refuseOutsideTheWriteContract(string $path, bool $isPublished, array &$errors): void
	{
		if (!$isPublished)
		{
			throw new UnknownDtoPropertyException(self::projection()->name(), $path);
		}

		$errors[] = new Error(sprintf('`%s` is published for reading only', $path), $path);
	}

	/**
	 * The published format the input is read against. Reading and writing share one format, so
	 * {@see FormDto} answers for both and no second map of types is kept here.
	 */
	private static function projection(): PublishedProjection
	{
		return PublishedProjection::of(FormDto::class);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param Error[] $errors
	 */
	private static function stringAt(array $values, string $property, string $path, array &$errors): ?string
	{
		return self::asString($values[$property] ?? null, WebFormPath::property($path, $property), $errors);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param Error[] $errors
	 */
	private static function intAt(array $values, string $property, string $path, array &$errors): ?int
	{
		return self::asInt($values[$property] ?? null, WebFormPath::property($path, $property), $errors);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param Error[] $errors
	 */
	private static function boolAt(array $values, string $property, string $path, array &$errors): ?bool
	{
		return self::asBool($values[$property] ?? null, WebFormPath::property($path, $property), $errors);
	}

	private static function sizeRefusal(string $field): Error
	{
		return self::refusal($field, sprintf('at most %d items', self::MAX_COLLECTION_SIZE));
	}

	private static function documentSizeRefusal(string $field): Error
	{
		return new Error(
			sprintf(
				'`%s` leaves the document past the %d items it may carry in total',
				$field,
				self::MAX_DOCUMENT_SIZE,
			),
			$field,
		);
	}
}
