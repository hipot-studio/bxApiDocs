<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * A page of the places a CRM form can be embedded into, read against one form.
 *
 * The places of the portal are always answered for the form they were asked about, so the form is a
 * required filter rather than an option, and the page carries no projection - the element of such a
 * list is fixed and small.
 *
 * A concrete request adds nothing to this contract and exists because an action is wired to a
 * request by its own type.
 */
abstract class AbstractFormScopedListRequest extends Request
{
	use PlainFilterTrait;

	private const FORM_ID_FIELD = 'formId';

	#[FilterRequired([self::FORM_ID_FIELD])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;

	public ?ItemListOrderStructure $order = null;

	public ?FormListPaginationStructure $pagination = null;

	/**
	 * The form of the page, read off the only condition the filter is allowed to carry.
	 *
	 * A filter that carries more than that one condition, negates its conditions or joins them by
	 * alternative is refused - negation and alternative at every level of its tree, not only at the
	 * top. The flat reading the form is taken by keeps one condition and drops the rest without a
	 * word, and the answer would then be about a form the caller did not ask about.
	 *
	 * @throws InvalidFilterException
	 */
	public function getFormId(): int
	{
		$formId = $this->filter?->getSimpleFilterConditions()[self::FORM_ID_FIELD] ?? null;
		if (
			!is_int($formId)
			|| $formId <= 0
			|| count($this->filter->getConditions()) !== 1
			|| !$this->isPlainFilterTree($this->filter)
		)
		{
			throw new InvalidFilterException($this->filter?->getList());
		}

		return $formId;
	}
}
