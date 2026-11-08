<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Main\ArgumentException;

/**
 * The order of a page of CRM forms: which fields the page is ordered by, in which direction, and in
 * which order those keys apply.
 *
 * The order is always complete - the last key of it is the identifier of the form, written by
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper}. Without it a page cut
 * by a limit and an offset out of an order by a field whose values repeat may lawfully lose and repeat
 * forms between neighbouring pages: a database is not obliged to keep the relative order of equal
 * values from one statement to the next.
 *
 * A field is named once: the keys are the keys of a map, which is the shape the request carries too -
 * a repeated key of a request collapses while the request is still being read, before anything here
 * could see it.
 *
 * A page of the neighbouring lists of the family - the widgets and the open lines of a form - is
 * ordered by its identifier alone and keeps its own type, {@see WebFormSort}.
 *
 * @internal
 */
final class WebFormListSort
{
	public const ASC = 'ASC';
	public const DESC = 'DESC';

	/**
	 * Which field a page of forms may be ordered by.
	 *
	 * The code of a form and the users of a form are published for filtering and not for ordering: the
	 * first has no order a client has asked for, and the second is a collection - a queue of several
	 * users does not put one form before another.
	 */
	private const SORTABLE_FIELDS = [
		WebForm::FIELD_ID,
		WebForm::FIELD_NAME,
		WebForm::FIELD_ACTIVE,
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_ENTITY_SCHEME,
		WebForm::FIELD_CREATED_TIME,
		WebForm::FIELD_MODIFIED_TIME,
	];

	/** @var array<string, string> */
	private readonly array $directionByField;

	/**
	 * @param array<string, string> $directionByField the keys of the order, in the order they apply
	 * @throws ArgumentException
	 */
	public function __construct(array $directionByField = [WebForm::FIELD_ID => self::ASC])
	{
		if ($directionByField === [])
		{
			throw new ArgumentException('Empty web form list order', 'order');
		}

		foreach ($directionByField as $field => $direction)
		{
			if (!in_array($field, self::SORTABLE_FIELDS, true))
			{
				throw new ArgumentException(
					sprintf('Unsupported web form sort field `%s`', (string)$field),
					'order',
				);
			}

			if (!in_array($direction, [self::ASC, self::DESC], true))
			{
				throw new ArgumentException(
					sprintf(
						'Unsupported web form sort direction `%s`',
						is_string($direction) ? $direction : get_debug_type($direction),
					),
					'order',
				);
			}
		}

		$this->directionByField = $directionByField;
	}

	/**
	 * The fields a page of forms may be ordered by. This is what the published description of the
	 * method is held against, the same way {@see WebFormFilter::getOperatorsByField()} is: the
	 * attributes of the contract, this list and the document say the same thing or one of them is
	 * wrong.
	 *
	 * @return string[]
	 */
	public static function getSortableFields(): array
	{
		return self::SORTABLE_FIELDS;
	}

	/** @return array<string, string> */
	public function getDirectionByField(): array
	{
		return $this->directionByField;
	}
}
