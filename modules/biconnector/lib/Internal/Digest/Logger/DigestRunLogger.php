<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Logger;

use Bitrix\BIConnector\Superset\Logger\Logger;

/**
 * Outcomes of a run nobody is notified about. Overlap is one of them: for the
 * recipients it is a normal outcome and for whoever reads the module journal
 * afterwards it is the only trace, so the run id and the instances the run
 * yielded to are carried as fields of the journal entry.
 * Left open for extension so unit tests can replace it with a stub.
 */
class DigestRunLogger
{
	/**
	 * @param string[] $liveInstanceIds
	 */
	public function logOverlap(string $runId, int $templateId, array $liveInstanceIds): void
	{
		Logger::logInfo('BI digest run skipped: a run of the same schedule is still live', [
			'run_id' => $runId,
			'workflow_template_id' => $templateId,
			'live_workflow_instance_ids' => $liveInstanceIds,
		]);
	}
}
