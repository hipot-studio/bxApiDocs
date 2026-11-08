<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Process\DocumentsState;
use Bitrix\Sign\Type\Process\RunningProcessStatus;

/**
 * One process of a launch group as the list of processes shows it. Everything but the state of the launch
 * itself is read off the engine on every request: the module keeps no states of processes of its own, so a
 * row of a process that has already started is a view and not a stored record.
 */
final class RunningProcess implements Contract\Item
{
	/**
	 * @param list<ProcessParticipant> $participants
	 * @param list<ProcessDocument> $documents
	 */
	public function __construct(
		public readonly int $launchId,
		public readonly int $launchItemId,
		public readonly int $employeeId,
		public readonly RunningProcessStatus $status,
		public readonly DocumentsState $documentsState,
		public readonly ?string $workflowId = null,
		public readonly ?int $entityId = null,
		public readonly string $title = '',
		public readonly string $stageName = '',
		public readonly ?int $lastActivityTs = null,
		public readonly array $participants = [],
		public readonly array $documents = [],
		public readonly ?string $noDocumentReason = null,
		public readonly bool $canRetry = false,
		public readonly ?string $protocolUrl = null,
	)
	{
	}
}
