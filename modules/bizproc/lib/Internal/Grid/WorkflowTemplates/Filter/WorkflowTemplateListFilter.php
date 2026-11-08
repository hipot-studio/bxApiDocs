<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\WorkflowTemplates\Filter;

use Bitrix\Main\Localization\Loc;

class WorkflowTemplateListFilter
{
	/**
	 * The id of the filter of the page, kept here so that the page, the tests and the fixtures read one
	 * value. The suffix is part of it: settings saved under the former id are not read at all, which is
	 * what lets the default preset reach an employee who had already set the filter up.
	 */
	public const FILTER_ID = 'bizproc_template_processes_filter_v2';

	private const FIELD_NAME = 'NAME';
	private const FIELD_CREATED_BY = 'CREATED_BY';
	private const FIELD_SEARCH_QUERY = 'FIND';

	/** Not a field of the filter: whom a row without an author of its own is listed under, see getAuthorCondition(). */
	private const COLUMN_LEGACY_AUTHOR = 'USER_ID';

	/** The joined author of a row; absent both when CREATED_BY holds nothing and when that account is gone. */
	private const COLUMN_CREATED_USER_ID = 'CREATED_USER.ID';

	private const PRESET_DEFAULT = 'filter_default';
	private const PRESET_MY_TEMPLATES = 'filter_my_templates';

	public function getFields(): array
	{
		return [
			self::FIELD_NAME => [
				'id' => self::FIELD_NAME,
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_TEMPLATE_LIST_FILTER_FIELD_NAME'),
				'type' => 'string',
				'default' => true,
			],
			self::FIELD_CREATED_BY => [
				'id' => self::FIELD_CREATED_BY,
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_TEMPLATE_LIST_FILTER_FIELD_CREATED_BY'),
				'type' => 'entity_selector',
				'default' => true,
				'params' => [
					'multiple' => 'N',
					'dialogOptions' => [
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'options' => [
									'intranetUsersOnly' => true,
									'inviteEmployeeLink' => false,
								],
							],
						],
					],
				],
			],
		];
	}

	public function getPresets(int $userId, string $userName): array
	{
		return [
			self::PRESET_DEFAULT => [
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_TEMPLATE_LIST_FILTER_FIELD_NAME'),
				'fields' => [
					self::FIELD_NAME => '',
				],
				'default' => false,
			],
			self::PRESET_MY_TEMPLATES => [
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_TEMPLATE_LIST_FILTER_PRESET_MY_TEMPLATES'),
				'fields' => [
					self::FIELD_CREATED_BY => $userId,
					self::FIELD_CREATED_BY . '_label' => $userName,
				],
				'default' => true,
			],
		];
	}

	public function getOrmFilter(array $values): array
	{
		$filter = [];

		$name = $this->readValue($values, self::FIELD_NAME);
		if ($name !== '')
		{
			$filter['%' . self::FIELD_NAME] = $name;
		}

		$createdBy = (int)$this->readValue($values, self::FIELD_CREATED_BY);
		if ($createdBy > 0)
		{
			$filter[] = $this->getAuthorCondition($createdBy);
		}

		return $filter;
	}

	/**
	 * Whom the list itself names beside a row: CREATED_BY, and USER_ID where the row has no author of its
	 * own. USER_ID is not the author but the one who saved last, and that is exactly whom the grid names
	 * for such a row - it reads getCreatedUser() ?? getUser()
	 * ({@see \Bitrix\Bizproc\Internal\Grid\WorkflowTemplates\WorkflowTemplateGridHelper}). The agreement
	 * reaches the column of the list and no further: the access area reads authorship from CREATED_BY alone
	 * ({@see \Bitrix\Bizproc\Public\Provider\TemplateAccessProvider}) and is left as it is.
	 *
	 * "No author of its own" is asked of the joined account and not of CREATED_BY, because the grid falls
	 * back on any absent CREATED_USER and there are two ways to be absent. CREATED_BY holds nothing: the
	 * column was added nullable in update 25.700.0 and left unfilled for the rows already there. Or it holds
	 * the id of an account that has since been deleted: the row keeps the number, the LEFT join answers
	 * nothing for it, and the column names the one who saved last. Asking CREATED_BY alone would leave the
	 * second kind out of the preset while the column beside it names that very employee.
	 *
	 * The condition is nested under a numeric key rather than spread over the filter: the disjunction is the
	 * author alone, and at the top level it would take the name along with it.
	 */
	private function getAuthorCondition(int $authorId): array
	{
		return [
			'LOGIC' => 'OR',
			'=' . self::FIELD_CREATED_BY => $authorId,
			[
				'=' . self::COLUMN_CREATED_USER_ID => null,
				'=' . self::COLUMN_LEGACY_AUTHOR => $authorId,
			],
		];
	}

	public function getSearchQuery(array $values): string
	{
		return $this->readValue($values, self::FIELD_SEARCH_QUERY);
	}

	/**
	 * The values come from the settings of the filter, where a field is not always a scalar: an array put
	 * into the query string reaches here as an array, and casting it would mean the author number 1 and a
	 * warning in the log. Anything but a scalar means no condition at all, the same as an empty value.
	 */
	private function readValue(array $values, string $field): string
	{
		$value = $values[$field] ?? null;

		return is_scalar($value) ? trim((string)$value) : '';
	}
}
