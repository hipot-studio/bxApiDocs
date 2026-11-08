<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Contract;

/**
 * Live workflow instances of one schedule: the only thing the digest run needs
 * to know about the workflow runtime.
 *
 * What counts as live is the implementation's own matter, see
 * {@see \Bitrix\BIConnector\Internal\Digest\Provider\BizprocLiveWorkflowInstanceProvider}.
 * The interface exists so the overlap decision is testable without the workflow
 * runtime and so the whole access path can be replaced at once.
 */
interface LiveWorkflowInstanceProvider
{
	/**
	 * @return string[] Instance ids, the caller's own one included when it is already stored.
	 */
	public function findLiveInstanceIds(int $templateId): array;
}
