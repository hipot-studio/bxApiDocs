<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Bizproc\EventHandler\OnGetDocumentTypeEvent;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
use Bitrix\Main\EventResult;
use Bitrix\Tasks\Integration\Bizproc\Document\Flow;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;

class GetDocumentTypes
{
	public function __construct()
	{}

	public function __invoke(OnGetDocumentTypeEvent $event): void
	{
		$event->addResult(
			new EventResult(
				EventResult::SUCCESS,
				['documentTypes' => [
					['tasks', Task::class, 'TASK'],
					// ['tasks', Flow::class, 'FLOW'],
				]],
			)
		);
	}
}
