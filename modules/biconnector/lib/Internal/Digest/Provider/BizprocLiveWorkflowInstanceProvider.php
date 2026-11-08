<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Internal\Digest\Contract\LiveWorkflowInstanceProvider;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * The only place in the module that knows where the workflow runtime keeps its
 * running processes.
 *
 * The row exists until the process is finished, so the status is not filtered:
 * an instance waiting on a pause is a live one, and the digest is meant to
 * yield to it. The freshness threshold cuts off an instance that died mid-run
 * and would otherwise hold the guard on until the workflow module's own cleanup.
 */
final class BizprocLiveWorkflowInstanceProvider implements LiveWorkflowInstanceProvider
{
	/**
	 * Has to sit between the full length of a normal run and the schedule
	 * interval: below the first, a running digest stops counting as live and the
	 * next one overlaps it; above the second, a dead instance blocks the next
	 * scheduled run.
	 */
	public const LIVENESS_THRESHOLD_HOURS = 4;

	/**
	 * @inheritDoc
	 */
	public function findLiveInstanceIds(int $templateId): array
	{
		if ($templateId <= 0 || !Loader::includeModule('bizproc'))
		{
			return [];
		}

		$rows = WorkflowInstanceTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=WORKFLOW_TEMPLATE_ID' => $templateId,
				'>MODIFIED' => $this->resolveLivenessThreshold(),
			],
		])->fetchAll();

		return array_map('strval', array_column($rows, 'ID'));
	}

	/**
	 * The bound is built the same way the workflow runtime writes the field, so
	 * both sides stay in one zone and the predicate converts nothing itself.
	 */
	private function resolveLivenessThreshold(): DateTime
	{
		return (new DateTime())->add('-' . self::LIVENESS_THRESHOLD_HOURS . ' hours');
	}
}
