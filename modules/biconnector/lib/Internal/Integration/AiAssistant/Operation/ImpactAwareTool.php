<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation;

/**
 * A tool that declares how far it reaches. Implemented by both BI tool hierarchies through
 * {@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BiToolBehaviour}, so the
 * operation catalogue reads the declaration from the contract instead of a base class: the
 * subject-aware tools extend a contract of their own and share no base with the plain ones.
 */
interface ImpactAwareTool
{
	public function getImpact(): OperationImpact;
}
