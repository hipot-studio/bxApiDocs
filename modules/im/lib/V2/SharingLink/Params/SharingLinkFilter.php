<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Params;

use Bitrix\Im\V2\Common\FormatConverter;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\Type;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;
use Traversable;

final class SharingLinkFilter implements \IteratorAggregate, FilterInterface
{
	private function __construct(
		public readonly ?int $id = null,
		public readonly ?LinkEntityType $entityType = null,
		public readonly ?string $entityId = null,
		public readonly ?int $authorId = null,
		public readonly ?string $code = null,
		public readonly ?Type $type = null,
		public readonly ?bool $isRevoked = null,
	){}

	public static function initById(int $id): self
	{
		return new self(id: $id);
	}

	public static function initByCode(string $code): self
	{
		return new self(code: $code);
	}

	public static function initForPrimary(LinkEntityType $entityType, string $entityId): self
	{
		return new self(
			entityType: $entityType,
			entityId: $entityId,
			type: Type::Primary,
			isRevoked: false,
		);
	}

	public static function initForIndividual(LinkEntityType $entityType, string $entityId, int $authorId): self
	{
		return new self(
			entityType: $entityType,
			entityId: $entityId,
			authorId: $authorId,
			type: Type::Individual,
			isRevoked: false,
		);
	}

	public function isEmpty(): bool
	{
		return empty(iterator_to_array($this));
	}

	public function getIterator(): Traversable
	{
		$properties = get_object_vars($this);

		foreach ($properties as $key => $value)
		{
			if (!isset($value))
			{
				continue;
			}

			$ormValue = match (true)
			{
				$value instanceof \UnitEnum => $value->value,
				is_bool($value) => $value ? 'Y' : 'N',
				default => $value,
			};

			yield FormatConverter::toUpperSnakeCase($key) => $ormValue;
		}
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		foreach ($this as $key => $value)
		{
			$this->applyOperator($result, $key, $value);
		}

		return $result;
	}

	private function applyOperator(ConditionTree $conditionTree, string $key, mixed $value): void
	{
		match (true)
		{
			is_array($value) => $conditionTree->whereIn($key, $value),
			is_string($value) || is_numeric($value) => $conditionTree->where($key, $value),
		};
	}
}