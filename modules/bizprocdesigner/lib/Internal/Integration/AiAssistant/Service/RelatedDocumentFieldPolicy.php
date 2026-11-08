<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentField;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldCollection;

/**
 * The fields of another entity that the crm field provider glues onto a document schema, and who may see them.
 *
 * The provider of the deal appends the whole field set of its contact and of its company to the deal's own,
 * under the `CONTACT.` and `COMPANY.` prefixes, and asks nobody about rights on those two
 * ({@see \CCrmDocumentDeal::GetDocumentFields()}). The right on the deal therefore says nothing about them, so
 * the fields of a related entity are dropped from the answer unless the caller passes the same read check on
 * that entity itself.
 *
 * The prefix is a habit of the provider, not a contract it publishes, which is exactly why the whole of it -
 * the list of prefixes, what each of them stands for, and how a field name is read - lives here alone and is
 * held by a test. If crm ever renames a prefix, a test fails; nobody's rights change quietly.
 *
 * Removing fields is not a refusal: the answer stays successful and the names that were dropped are simply
 * absent, so a caller who may read the deal keeps working while seeing less (BRIEF § 6.2).
 */
final class RelatedDocumentFieldPolicy
{
	/** What separates the prefix of a related entity from the field name behind it. */
	private const PREFIX_SEPARATOR = '.';

	/**
	 * @var array<string, list<string>> prefix (without the separator) => the document type it stands for.
	 *      Mirrors what {@see \CCrmOwnerType::ResolveName()} yields for the two entities the crm providers
	 *      append ({@see \CCrmDocumentDeal::GetDocumentFields()}, {@see \Bitrix\Crm\Integration\BizProc\Document\Order}).
	 */
	private const RELATED_TYPES = [
		'CONTACT' => ['crm', 'CCrmDocumentContact', 'CONTACT'],
		'COMPANY' => ['crm', 'CCrmDocumentCompany', 'COMPANY'],
	];

	/** @var array<string, bool> one verdict per related entity, so a filter run asks about each of them once */
	private array $verdicts = [];

	/**
	 * The document type a field name belongs to when it is not the host's own, or null when it is.
	 *
	 * The name is read as prefix + separator + something: a bare `CONTACT` field of the host itself carries no
	 * separator and stays, and so does a name whose prefix is not one of the appended entities.
	 */
	public static function relatedTypeOf(string $fieldId): ?DocumentDescription
	{
		$separatorPosition = strpos($fieldId, self::PREFIX_SEPARATOR);
		if ($separatorPosition === false || $separatorPosition === 0 || $separatorPosition === strlen($fieldId) - 1)
		{
			return null;
		}

		$type = self::RELATED_TYPES[substr($fieldId, 0, $separatorPosition)] ?? null;

		return $type === null ? null : new DocumentDescription(...$type);
	}

	/**
	 * The same fields without those of a related entity the caller may not read.
	 *
	 * @param callable(DocumentDescription): bool $canRead asked at most once per related entity
	 */
	public function filter(DocumentFieldCollection $fields, callable $canRead): DocumentFieldCollection
	{
		$this->verdicts = [];

		$allowed = new DocumentFieldCollection();
		foreach ($fields as $field)
		{
			/** @var DocumentField $field */
			if ($this->isReadable($field->id, $canRead))
			{
				$allowed->add($field);
			}
		}

		return $allowed;
	}

	/** @param callable(DocumentDescription): bool $canRead */
	private function isReadable(string $fieldId, callable $canRead): bool
	{
		$relatedType = self::relatedTypeOf($fieldId);
		if ($relatedType === null)
		{
			return true;
		}

		$key = $relatedType->toCanonicalString();

		return $this->verdicts[$key] ??= (bool)$canRead($relatedType);
	}
}
