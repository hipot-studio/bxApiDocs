<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Debugger\Contract;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugSessionCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;


interface DebugSessionQueryInterface
{
	public function findActiveDebugByDocumentAndTemplate(array $documentId, int $templateId): ?DebugSession;

	public function getActiveDebugsByDocument(array $documentId, bool $withTraces = false): DebugSessionCollection;

	public function getDebugsByTemplateId(int $templateId, bool $withTraces = false): DebugSessionCollection;

	public function getDebugByWorkflowId(string $workflowId): ?DebugSession;
}
