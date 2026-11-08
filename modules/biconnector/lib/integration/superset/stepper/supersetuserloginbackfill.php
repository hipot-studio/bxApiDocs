<?php

namespace Bitrix\BIConnector\Integration\Superset\Stepper;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Integration\Superset\SupersetUserLogin;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Update\Stepper;

/**
 * Fills LOGIN of the Superset account links written before the column existed.
 *
 * Portioned by an ID cursor and safe to repeat: only a link with no login is taken, so a reinstalled
 * update, a run that died halfway or a parallel pass neither rewrites a stored login nor does the
 * same work twice. A link left without a login goes to the event log instead of the column.
 */
final class SupersetUserLoginBackfill extends Stepper
{
	protected static $moduleId = 'biconnector';

	private const BATCH_SIZE = 500;

	public function execute(array &$option)
	{
		if (!Loader::includeModule(self::$moduleId))
		{
			return self::FINISH_EXECUTION;
		}

		$option['steps'] = (int)($option['steps'] ?? 0);
		$option['count'] ??= SupersetUserTable::getCount(self::getPendingFilter());

		$lastId = (int)($option['lastId'] ?? 0);
		$rows = $this->getPendingRows($lastId);

		foreach ($rows as $row)
		{
			$lastId = (int)$row['ID'];
			$this->fillLogin($lastId, (int)$row['USER_ID']);
			$option['steps']++;
		}

		$option['lastId'] = $lastId;

		return count($rows) < self::BATCH_SIZE ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * A login is derived from USER_ID only, by the same rule as the write path: a link without one
	 * keeps an empty column.
	 *
	 * The cursor moves past a link the pass could not fill, so a single unhealthy row neither stops
	 * the rest nor makes the pass spin on it; the event log is what keeps such a row visible. The row
	 * is not retried on its own: it gets a login only if the update registers the stepper again after
	 * a clean finish, and otherwise stays without one - the degradation the decision accepts. A pass
	 * interrupted halfway keeps its cursor, so re-registration resumes past such a row instead.
	 */
	private function fillLogin(int $id, int $userId): void
	{
		if ($userId <= 0)
		{
			Logger::logErrors(
				[new Error('USER_ID is not set, there is nothing to derive the login from')],
				['SupersetUserLoginBackfill, link ' . $id]
			);

			return;
		}

		$updateResult = SupersetUserTable::update($id, [
			'LOGIN' => SupersetUserLogin::getLogin($userId),
		]);
		if (!$updateResult->isSuccess())
		{
			Logger::logErrors($updateResult->getErrors(), ['SupersetUserLoginBackfill, link ' . $id]);
		}
	}

	private function getPendingRows(int $lastId): array
	{
		return SupersetUserTable::query()
			->setSelect(['ID', 'USER_ID'])
			->where('ID', '>', $lastId)
			->where(self::getPendingFilter())
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::BATCH_SIZE)
			->fetchAll()
		;
	}

	/**
	 * The column was added without notNull() and without default(), so a row that predates it carries
	 * NULL: a filter on the empty string alone would match nothing and report success having done
	 * nothing.
	 */
	private static function getPendingFilter(): ConditionTree
	{
		return (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->whereNull('LOGIN')
			->where('LOGIN', '')
		;
	}
}
