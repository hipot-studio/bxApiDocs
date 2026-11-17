<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;

/**
 * Ролевой срез списка правил раздела «Регулярные задачи» (ALG-S3).
 *
 * Оставляет только те шаблоны, в которых просматривающий пользователь состоит в
 * запрошенной роли (постановщик / исполнитель / соисполнитель / наблюдатель).
 *
 * Значение фильтра — массив `['userId' => int, 'role' => string]`, где `role` —
 * тип роли (`TemplateMemberTable::MEMBER_TYPE_*`). Пустой / отсутствующий `role`
 * означает «Все роли» и условие не добавляет. `userId` — просматривающий из
 * серверного контекста раздела, не из клиентского запроса.
 *
 * Только коррелированный EXISTS по `b_tasks_template_member`, не JOIN: пользователь
 * может состоять в шаблоне сразу в нескольких ролях, JOIN размножил бы строки грида
 * и исказил пагинацию и счётчики (см. индекс `TEMPLATE_USER_TYPE [USER_ID, TYPE]`).
 */
class RoleFilterModifier extends BaseFilterModifier
{
	public function modify(Query $query): Query
	{
		if (!is_array($this->value))
		{
			return $query;
		}

		$viewerId = (int)($this->value['userId'] ?? 0);
		$role = (string)($this->value['role'] ?? '');

		if ($viewerId <= 0 || $role === '')
		{
			return $query;
		}

		$subQuery = TemplateMemberTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $viewerId)
			->where('TYPE', $role)
			->where('TEMPLATE_ID', new SqlExpression('%s'))
		;

		return $query->whereExpr("EXISTS({$subQuery->getQuery()})", ['ID']);
	}
}
