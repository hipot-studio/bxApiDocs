<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\DB\Order;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\TailRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

trait TailOrmActionTrait
{
	use OrmActionTrait;

	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_TAILORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_TAILORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	final public function tailAction(TailRequest $request): ListResponse
	{
		if (!$request->filter)
		{
			$request->filter = new FilterStructure();
		}

		$field = 'id';
		$order = Order::Asc->value;
		$value = 0;
		$limit = PaginationStructure::DEFAULT_LIMIT;
		if ($request->cursor)
		{
			$field = $request->cursor->getField();
			$order = $request->cursor->getOrder()->value;
			$value = $request->cursor->getValue();
			$limit = $request->cursor->getLimit();
		}

		if ($request->filter && in_array($field, $request->filter->getFields(), true))
		{
			throw new InvalidFilterException('Cursor field ' . $field . ' cannot be used at filter.');
		}

		$operator = $order === Order::Asc->value ? Operator::Greater : Operator::Less;
		if ($request->cursor)
		{
			$condition = new Condition($field, $operator, $value);
			$request->filter->addCondition($condition);
		}

		$orderStructure = OrderStructure::create(
			[$field => $order],
			$request->getDtoClass(),
			$request,
		);

		$paginationStructure = PaginationStructure::create(['limit' => $limit]);

		$collection = $this
			->getOrmRepositoryByRequest($request)
			->getAll(
				$request->select,
				$request->filter,
				$orderStructure,
				$paginationStructure,
			)
		;

		return new ListResponse($collection);
	}
}
