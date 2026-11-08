<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Main\ArgumentException;

/**
 * @internal
 */
final class WebFormFilter
{
	public const LOGIC_AND = 'and';
	public const LOGIC_OR = 'or';

	/**
	 * Which field a form may be filtered by, and which operators each of them takes.
	 *
	 * Neither date takes an enumeration. The transport cannot carry one: its check of an enumeration
	 * takes scalars, while a moment reaches it already read as a typed date, so the pair would be
	 * refused before the domain heard of it. An interval is the operator that answers a range of
	 * moments; the enumeration stays unpublished until the transport can read one.
	 */
	private const OPERATORS_BY_FIELD = [
		WebForm::FIELD_ID => [
			'=' => true,
			'!=' => true,
			'>' => true,
			'>=' => true,
			'<' => true,
			'<=' => true,
			'in' => true,
			'between' => true,
		],
		WebForm::FIELD_CODE => [
			'=' => true,
			'!=' => true,
			'in' => true,
		],
		WebForm::FIELD_NAME => [
			'=' => true,
			'!=' => true,
			'in' => true,
		],
		WebForm::FIELD_ACTIVE => [
			'=' => true,
			'!=' => true,
		],
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_ENTITY_SCHEME => [
			'=' => true,
			'!=' => true,
			'in' => true,
		],
		WebForm::SECTION_RESPONSIBLE . '.' . Section\Responsible::FIELD_USER_IDS => [
			'=' => true,
			'in' => true,
		],
		WebForm::FIELD_CREATED_TIME => [
			'=' => true,
			'!=' => true,
			'>' => true,
			'>=' => true,
			'<' => true,
			'<=' => true,
			'between' => true,
		],
		WebForm::FIELD_MODIFIED_TIME => [
			'=' => true,
			'!=' => true,
			'>' => true,
			'>=' => true,
			'<' => true,
			'<=' => true,
			'between' => true,
		],
	];

	/** @var self[] */
	private array $conditions = [];

	private function __construct(
		private readonly ?string $field = null,
		private readonly ?string $operator = null,
		private readonly mixed $value = null,
		private readonly string $logic = self::LOGIC_AND,
		private readonly bool $negative = false,
	)
	{
	}

	public static function empty(): self
	{
		return self::group(self::LOGIC_AND);
	}

	/**
	 * The fields a form may be filtered by, each with the operators it takes. This is what the
	 * published description of the method is held against: the filter of the contract, the map of the
	 * storage and the document say the same thing or one of them is wrong.
	 *
	 * @return array<string, string[]>
	 */
	public static function getOperatorsByField(): array
	{
		return array_map(
			static fn(array $operators): array => array_keys($operators),
			self::OPERATORS_BY_FIELD,
		);
	}

	/**
	 * @throws ArgumentException
	 */
	public static function condition(string $field, string $operator, mixed $value): self
	{
		if (!isset(self::OPERATORS_BY_FIELD[$field][$operator]))
		{
			throw new ArgumentException(
				sprintf('Unsupported web form filter condition `%s %s`', $field, $operator),
				'filter',
			);
		}

		return new self(field: $field, operator: $operator, value: $value);
	}

	/**
	 * @param self[] $conditions
	 * @throws ArgumentException
	 */
	public static function group(string $logic, bool $negative = false, array $conditions = []): self
	{
		if (!in_array($logic, [self::LOGIC_AND, self::LOGIC_OR], true))
		{
			throw new ArgumentException(sprintf('Unsupported web form filter logic `%s`', $logic), 'filter');
		}

		$filter = new self(logic: $logic, negative: $negative);
		foreach ($conditions as $condition)
		{
			if (!$condition instanceof self)
			{
				throw new ArgumentException('Unsupported web form filter node', 'filter');
			}

			$filter->conditions[] = $condition;
		}

		return $filter;
	}

	public function isCondition(): bool
	{
		return $this->field !== null;
	}

	public function getField(): ?string
	{
		return $this->field;
	}

	public function getOperator(): ?string
	{
		return $this->operator;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getLogic(): string
	{
		return $this->logic;
	}

	public function isNegative(): bool
	{
		return $this->negative;
	}

	/**
	 * @return self[]
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function hasConditions(): bool
	{
		return $this->isCondition() || $this->conditions !== [];
	}
}
