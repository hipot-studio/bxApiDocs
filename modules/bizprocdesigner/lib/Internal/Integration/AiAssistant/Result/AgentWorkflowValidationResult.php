<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupConstantDeclaration;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplateMetadata;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AgentWorkflowValidationResult extends Result
{
	/**
	 * @param list<SetupConstantDeclaration> $setupConstantDeclarations constants the setup wizard forms of this
	 *        graph declare, taken from the nodes whose fingerprint diverged from the published baseline (TPL-03).
	 *        Empty when the graph carries no such node, and the write then behaves exactly as it did before this
	 *        field existed. It rides the very path the valid blocks ride: a channel of its own would let the graph
	 *        be judged by one reading of the forms and written by another.
	 * @param list<Error> $warnings non-blocking risks the validators named on a graph they accepted (DTO-02).
	 *        They travel apart from the errors on purpose: an error decides the verdict and a warning never
	 *        does, so adding one to the error collection would refuse the very graph it merely comments on.
	 *        Only the dry run publishes them; the write and the Marta tool read the errors alone.
	 * @param AgentTemplateMetadata $templateMetadata name and description of the template as the validator
	 *        accepted and cleaned them (AGT-DTO-01)
	 */
	public function __construct(
		public readonly AgentConnectionCollection $connections,
		public readonly AgentBlockCollection $blocks,
		public readonly array $setupConstantDeclarations = [],
		public readonly array $warnings = [],
		public readonly AgentTemplateMetadata $templateMetadata = new AgentTemplateMetadata(),
	)
	{
		parent::__construct();
	}

	public function withTemplateMetadata(AgentTemplateMetadata $templateMetadata): self
	{
		return new self(
			$this->connections,
			$this->blocks,
			$this->setupConstantDeclarations,
			$this->warnings,
			$templateMetadata,
		);
	}
}
