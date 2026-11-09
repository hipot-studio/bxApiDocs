<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;

use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Application;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateSite;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Query;

if (!Loader::includeModule('landing'))
{
	return;
}

class SiteSummaryProvider implements SummaryProviderInterface
{
	private const PROVIDER_ID = 'landing.created_sites';
	private const USER_ID_LIMIT = 1000;
	private int $userIdLimit = self::USER_ID_LIMIT;

	private int $lastUserId = 0;

	public function __construct(private readonly Date $fromDate, private readonly Date $toDate)
	{
	}

	public function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function getUserIdLimit(): int
	{
		return $this->userIdLimit;
	}

	private function holdBatch(array $userIds): void
	{
		$this->userIdLimit = max(1, min(self::USER_ID_LIMIT, count($userIds)));
	}

	public function provide(array $userIds): EntityCollection
	{
		$collection = new EntityCollection();

		if ($userIds === [])
		{
			return $collection;
		}

		try
		{
			$oldScenarioSql = (new SqlExpression('?s', CreateSite::class))->compile();
			$newScenarioSql = (new SqlExpression('?s', CreateAiSite::class))->compile();
			$fromDateTime = DateTime::createFromTimestamp($this->fromDate->getTimestamp());
			$toDateTime = DateTime::createFromTimestamp($this->toDate->getTimestamp());

			$generatedSitesResult = GenerationsTable::query()
				->addSelect('CREATED_BY_ID')
				->addSelect(
					(new ExpressionField(
						'OLD_AI_SITES',
						"SUM(CASE WHEN %s = {$oldScenarioSql} THEN 1 ELSE 0 END)",
						'SCENARIO',
					))->configureValueType(IntegerField::class),
				)
				->addSelect(
					(new ExpressionField(
						'NEW_AI_SITES',
						"SUM(CASE WHEN %s = {$newScenarioSql} THEN 1 ELSE 0 END)",
						'SCENARIO',
					))->configureValueType(IntegerField::class),
				)
				->registerRuntimeField(
					(new Reference(
						'SITE',
						SiteTable::getEntity(),
						Join::on('this.SITE_ID', 'ref.ID'),
					))->configureJoinType(Join::TYPE_INNER),
				)
				->whereIn('CREATED_BY_ID', $userIds)
				->whereIn('SCENARIO', [CreateSite::class, CreateAiSite::class])
				->where('SITE.TYPE', 'PAGE')
				->where('SITE.DATE_CREATE', '>=', $fromDateTime)
				->where('SITE.DATE_CREATE', '<', $toDateTime)
				->addGroup('CREATED_BY_ID')
				->addOrder('CREATED_BY_ID', 'ASC')
				->exec()
			;

			$regularSitesResult = SiteTable::query()
				->addSelect('CREATED_BY_ID')
				->addSelect(Query::expr()->count('ID'), 'REGULAR_SITES')
				->registerRuntimeField(
					(new Reference(
						'CREATION_GENERATION',
						GenerationsTable::getEntity(),
						Join::on('this.ID', 'ref.SITE_ID')
							->whereIn('ref.SCENARIO', [CreateSite::class, CreateAiSite::class]),
					))->configureJoinType(Join::TYPE_LEFT),
				)
				->whereNull('CREATION_GENERATION.ID')
				->whereIn('CREATED_BY_ID', $userIds)
				->where('TYPE', 'PAGE')
				->where('DATE_CREATE', '>=', $fromDateTime)
				->where('DATE_CREATE', '<', $toDateTime)
				->addGroup('CREATED_BY_ID')
				->addOrder('CREATED_BY_ID', 'ASC')
				->exec()
			;

			$totalsByUser = [];

			foreach ($regularSitesResult as $regularSite)
			{
				$userId = (int)$regularSite['CREATED_BY_ID'];
				$totalsByUser[$userId]['regular'] = (int)$regularSite['REGULAR_SITES'];
			}

			foreach ($generatedSitesResult as $aiSite)
			{
				$userId = (int)$aiSite['CREATED_BY_ID'];
				$totalsByUser[$userId]['regular']
					= ($totalsByUser[$userId]['regular'] ?? 0) + (int)$aiSite['OLD_AI_SITES'];
				$totalsByUser[$userId]['ai'] = (int)$aiSite['NEW_AI_SITES'];
			}

			foreach ($userIds as $userId)
			{
				$userId = (int)$userId;
				$regular = $totalsByUser[$userId]['regular'] ?? 0;
				$ai = $totalsByUser[$userId]['ai'] ?? 0;

				$collection->add(new Summary($userId, 'landing.created_regular_sites', $regular));
				$collection->add(new Summary($userId, 'landing.created_ai_sites', $ai));
				$this->lastUserId = $userId;
			}

			return $collection;
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception, ExceptionHandlerLog::CAUGHT_EXCEPTION);
			$this->lastUserId = max((min($userIds) - 1), $this->lastUserId);
			$this->holdBatch($userIds);

			return $collection;
		}
	}
}
