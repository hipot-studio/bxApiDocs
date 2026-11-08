<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\FactTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubmissionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectLinkTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\EventTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\LedgerRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\{RegistryResult, SubjectReference};
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

class RevokeService
{
	public static function getInstance(): self
	{
		$l = ServiceLocator::getInstance(); $service = $l->has(self::class) ? $l->get(self::class) : null;

		return $service instanceof self ? $service : new self();
	}

	public function execute(int $userId, int $factId): RegistryResult
	{
		if ($userId <= 0 || $userId > 2147483647 || $factId <= 0 || $factId > 2147483647)
		{
			return SubmissionService::error('NOT_FOUND_OR_DENIED');
		}

		return SubmissionService::transaction(function () use ($userId, $factId): RegistryResult {
			$access = $this->access($userId);
			if (!$access->canEditForm()) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$fact = FactTable::getById($factId)->fetch();
			if (!$fact) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$submission = SubmissionTable::getById((int)$fact['SUBMISSION_ID'])->fetch();
			if (!$submission) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			SubmissionService::currentRows(SourceTable::class, ['=ID' => $submission['SOURCE_ID']]);
			$submission = SubmissionService::currentRows(SubmissionTable::class, ['=ID' => $submission['ID']])[0] ?? null;
			if ($submission === null) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$subjectId = (int)$fact['SUBJECT_ID'];
			$locator = SubjectTable::getById($subjectId)->fetch();
			if (!$locator) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$canonical = $locator['CANONICAL_ID'] === null ? $subjectId : (int)$locator['CANONICAL_ID'];
			$ids = array_unique([$subjectId, $canonical]); sort($ids, SORT_NUMERIC);
			$locked = SubmissionService::currentRows(SubjectTable::class, ['@ID' => $ids]);
			$current = array_column($locked, null, 'ID');
			if (!isset($current[$subjectId], $current[$canonical]) || $current[$canonical]['CANONICAL_ID'] !== null
				|| ($current[$subjectId]['CANONICAL_ID'] === null
					? $subjectId : (int)$current[$subjectId]['CANONICAL_ID']) !== $canonical)
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			$bindings = [];
			$links = SubmissionService::currentRows(SubjectLinkTable::class,
				['=SUBJECT_ID' => $canonical, '=IS_CURRENT' => true]);
			foreach ($links as $row)
			{
				$bindings[] = new SubjectReference((int)$row['ENTITY_TYPE_ID'], (int)$row['ENTITY_ID']);
			}
			if (!$access->canUpdateBindings($bindings)) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$fact = SubmissionService::currentRows(FactTable::class, ['=ID' => $factId])[0];
			$key = 'revoke:' . $factId;
			$existing = SubmissionService::currentRows(EventTable::class, ['=EVENT_KEY' => $key])[0] ?? null;
			if ($existing !== null)
			{
				return SubmissionService::error('ALREADY_REVOKED')->setFactIds([$factId])
					->setFacts([SubmissionService::hydrateFact($fact, $submission)])->setReplay(true)
				;
			}
			if ($fact['EXPIRES_AT'] !== null && time() >= $fact['EXPIRES_AT']->getTimestamp())
			{
				return SubmissionService::error('INVALID_STATE');
			}
			$now = new DateTime();
			$saved = (new LedgerRepository())->appendEvent($key, ['FACT_ID' => $factId, 'SUBJECT_ID' => $subjectId,
				'KIND' => 'revoke', 'ACTOR_ID' => $userId, 'EVENT_DATE' => $now, 'SERVER_TIME' => $now, 'PAYLOAD' => '{}']);
			if (!$saved->isSuccess()) { return $saved; }

			return (new RegistryResult())->setFactIds([$factId])->setFacts([SubmissionService::hydrateFact($fact, $submission)]);
		});
	}

	protected function access(int $userId): RegistryAccess
	{
		return RegistryAccess::forUser($userId);
	}
}
