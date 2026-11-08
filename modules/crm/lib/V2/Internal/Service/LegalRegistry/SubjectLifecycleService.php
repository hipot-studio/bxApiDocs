<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectLinkTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\SubjectRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectTransition;
use Bitrix\Main\Application;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;

final class SubjectLifecycleService
{
	public function transition(int $userId, SubjectTransition $transition): RegistryResult
	{
		$connection = Application::getConnection(); $owned = false;
		try
		{
			if ($userId <= 0)
			{
				return self::error('NOT_FOUND_OR_DENIED');
			}
			$access = RegistryAccess::forUser($userId);
			if (!$access->canEditForm() || !TransitionProofProvider::getInstance()->confirms($transition))
			{
				return self::error('NOT_FOUND_OR_DENIED');
			}
			$transition = TransitionProofProvider::canonicalTransition($transition);
			if (!$access->canUpdateBindings(array_merge($transition->from, $transition->to)))
			{
				return self::error(
				'NOT_FOUND_OR_DENIED');
			}
			$connection->startTransaction(); $owned = true;
			$result = $this->transfer($transition, $userId, $access);
			$owned = false;
			if ($result->isSuccess()) { $connection->commitTransaction(); }
			else { self::rollback(); }

			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($owned)
			{
				$owned = false;
				try { self::rollback(); }
				catch (\Throwable) { return self::error('STORAGE_ERROR'); }
			}

			return self::error(
				$exception instanceof \UnexpectedValueException
				&& $exception->getMessage() === 'SUBJECT_UNRESOLVED'
					? 'NOT_FOUND_OR_DENIED' : 'STORAGE_ERROR',
			);
		}
	}

	public function purge(int $userId, int $subjectId): RegistryResult
	{
		// Stage 1 has no supported purge implementation, including Internal collection test policies.
		return (new PolicyGate())->requirePurge();
	}

	private function transfer(SubjectTransition $transition, int $userId, RegistryAccess $access): RegistryResult
	{
		$repository = new SubjectRepository(); $ids = [];
		foreach (array_merge($transition->from, $transition->to) as $ref)
		{
			$rows = SubjectLinkTable::getList([
				'select' => ['SUBJECT_ID'],
				'filter' => ['=ENTITY_TYPE_ID' => $ref->entityTypeId, '=ENTITY_ID' => $ref->entityId],
			])->fetchAll();
			foreach ($rows as $row)
			{
				foreach ($repository->readAliases((int)$row['SUBJECT_ID']) as $id) { $ids[$id] = $id; }
			}
		}
		if ($ids === [])
		{
			return self::error('NOT_FOUND_OR_DENIED');
		}
		ksort($ids, SORT_NUMERIC);
		$subjects = self::currentRows(SubjectTable::class, ['@ID' => array_values($ids)]);
		if (count($subjects) !== count($ids))
		{
			return self::error('NOT_FOUND_OR_DENIED');
		}
		foreach ($subjects as $row)
		{
			if ($row['CANONICAL_ID'] !== null && !isset($ids[(int)$row['CANONICAL_ID']]))
			{
				return self::error('REVISION_CONFLICT');
			}
		}
		$links = self::currentRows(SubjectLinkTable::class, ['@SUBJECT_ID' => array_values($ids), '=IS_CURRENT' => true]);
		$current = array_map(
			static fn(array $row): SubjectReference => new SubjectReference((int)$row['ENTITY_TYPE_ID'], (int)$row['ENTITY_ID']),
			$links,
		);
		if (!$access->canUpdateBindings($current))
		{
			return self::error('NOT_FOUND_OR_DENIED');
		}
		$result = $repository->transferSubjects($transition, $userId);
		if (!$result->isSuccess() && $result->getErrors()[0]->getCode() === 'SUBJECT_UNRESOLVED')
		{
			return self::error('NOT_FOUND_OR_DENIED');
		}

		return $result;
	}

	private static function currentRows(string $model, array $filter): array
	{
		$query = new class($model::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				$result = $this->entity->getConnection()->query($sql . ' FOR UPDATE');
				$result->setReplacedAliases($this->replaced_aliases);
				if ($this->isFetchModificationRequired()) { $result->addFetchDataModifier([$this,
					'fetchDataModificationCallback']); }

				return $result;
			}
		};

		return $query->setSelect(['*'])->setFilter($filter)->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	private static function rollback(): void
	{
		try { Application::getConnection()->rollbackTransaction(); }
		catch (TransactionException $exception)
		{
			if (get_class($exception) !== TransactionException::class || $exception->getMessage(
				) !== 'Nested rollbacks are unsupported.') { throw $exception; }
		}
	}

	private static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error($code, $code));
	}
}
