<?php

namespace Bitrix\Landing\Markup\Form;

use Bitrix\Crm\WebForm\EntityFieldProvider;

/**
 * Maps the fields of a form declaration (DTO-F1) to the fields of a CRM form.
 *
 * The scheme of the portal decides the target: a lead, or a contact with a deal when leads are off.
 * A kind is mapped once, a repeated one and a kind whose CRM field the portal lacks are skipped.
 * A field without a label takes the caption of its CRM field.
 */
class FormFieldMapper
{
	/** Values of Crm\WebForm\Entity::ENUM_ENTITY_SCHEME_LEAD and ENUM_ENTITY_SCHEME_DEAL. */
	public const SCHEME_LEAD = '1';
	public const SCHEME_DEAL = '3';

	private const CODES_LEAD = [
		'name' => 'LEAD_NAME',
		'last_name' => 'LEAD_LAST_NAME',
		'phone' => 'LEAD_PHONE',
		'email' => 'LEAD_EMAIL',
		'comment' => 'LEAD_COMMENTS',
		'company' => 'LEAD_COMPANY_TITLE',
	];

	private const CODES_DEAL = [
		'name' => 'CONTACT_NAME',
		'last_name' => 'CONTACT_LAST_NAME',
		'phone' => 'CONTACT_PHONE',
		'email' => 'CONTACT_EMAIL',
		'comment' => 'DEAL_COMMENTS',
		'company' => 'COMPANY_TITLE',
	];

	private const TYPES = [
		'name' => 'string',
		'last_name' => 'string',
		'phone' => 'phone',
		'email' => 'email',
		'comment' => 'text',
		'company' => 'string',
	];

	private const SORT_STEP = 100;

	/** @var \Closure(string): ?array */
	private \Closure $findCrmField;

	/** @var array<string, array>|null */
	private ?array $crmFields = null;

	/**
	 * @param \Closure(string): ?array|null $findCrmField The CRM field by its code, null when the portal has none;
	 * the field provider of the CRM by default.
	 */
	public function __construct(?\Closure $findCrmField = null)
	{
		$this->findCrmField = $findCrmField ?? fn(string $code): ?array => $this->findProviderField($code);
	}

	/**
	 * @param list<array{kind: string, label?: string, required?: bool}> $fields
	 * @return list<array> Fields in the format of Crm\WebForm\Form.
	 */
	public function map(array $fields, string $entityScheme): array
	{
		return $this->resolve($fields, $entityScheme)['fields'];
	}

	/**
	 * Kinds of the declaration that get no field of the CRM form, in the order of the declaration.
	 * @param list<array{kind: string, label?: string, required?: bool}> $fields
	 * @return list<string>
	 */
	public function getSkippedKinds(array $fields, string $entityScheme): array
	{
		return $this->resolve($fields, $entityScheme)['skippedKinds'];
	}

	/**
	 * @return array{fields: list<array>, skippedKinds: list<string>}
	 */
	private function resolve(array $fields, string $entityScheme): array
	{
		$codes = $entityScheme === self::SCHEME_LEAD ? self::CODES_LEAD : self::CODES_DEAL;

		$mapped = [];
		$skippedKinds = [];
		foreach ($fields as $field)
		{
			$kind = (string)($field['kind'] ?? '');
			$code = $codes[$kind] ?? null;
			$crmField = ($code !== null && !isset($mapped[$kind])) ? ($this->findCrmField)($code) : null;
			if ($crmField === null)
			{
				$skippedKinds[] = $kind;

				continue;
			}

			$label = trim((string)($field['label'] ?? ''));
			$mapped[$kind] = [
				'TYPE' => self::TYPES[$kind],
				'CODE' => $code,
				'CAPTION' => $label !== '' ? $label : (string)($crmField['caption'] ?? ''),
				'SORT' => self::SORT_STEP * (count($mapped) + 1),
				'REQUIRED' => !empty($field['required']) ? 'Y' : 'N',
				'MULTIPLE' => 'N',
				'PLACEHOLDER' => '',
			];
		}

		return [
			'fields' => array_values($mapped),
			'skippedKinds' => $skippedKinds,
		];
	}

	private function findProviderField(string $code): ?array
	{
		if ($this->crmFields === null)
		{
			$this->crmFields = [];
			foreach (EntityFieldProvider::getFields() as $crmField)
			{
				$this->crmFields[(string)$crmField['name']] = $crmField;
			}
		}

		return $this->crmFields[$code] ?? null;
	}
}
