<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\AgentEntryVerdict;

/**
 * The answer of {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentEntryAuthorizationService}:
 * the verdict, and - only when it allows - the identifier of the template it was pronounced about.
 *
 * The identifier is nullable rather than absent from the refusing cases because a refusal must not carry
 * the resource it refused: a caller that reads it anyway then holds a document type and a name of a
 * template it was just denied.
 */
final class AgentEntryAuthorizationResult
{
	private function __construct(
		public readonly AgentEntryVerdict $verdict,
		public readonly ?WorkflowTemplateIdentifier $identifier = null,
	) {}

	public static function of(
		AgentEntryVerdict $verdict,
		?WorkflowTemplateIdentifier $identifier = null,
	): self
	{
		return new self($verdict, $verdict === AgentEntryVerdict::Allowed ? $identifier : null);
	}

	public function isAllowed(): bool
	{
		return $this->verdict === AgentEntryVerdict::Allowed;
	}
}
