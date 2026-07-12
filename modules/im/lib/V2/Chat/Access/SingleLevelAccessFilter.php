<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

readonly class SingleLevelAccessFilter
{
	public function __construct(
		private ?TypeCondition $openChatCondition,
		private string $relationAlias = 'RELATION',
		private ?string $chatAlias = null,
	) {}

	public function toConditionTree(): ConditionTree
	{
		$condition = Query::filter()
			->logic('or')
			->whereNotNull("{$this->relationAlias}.ID")
		;

		if ($this->openChatCondition !== null)
		{
			$condition->where(
				(new TypeFilter(
					$this->openChatCondition,
					$this->getChatField('TYPE'),
					$this->getChatField('ENTITY_TYPE'),
				))->toConditionTree()
			);
		}

		return $condition;
	}

	private function getChatField(string $field): string
	{
		return $this->chatAlias !== null ? "{$this->chatAlias}.{$field}" : $field;
	}
}
