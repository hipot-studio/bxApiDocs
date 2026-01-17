<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockDescriptionService;
use Bitrix\Main\Result;

class SaveWorkflowValidator
{
	private readonly AgentConnectionsValidator $connectionsValidator;
	private readonly AgentBlocksValidator $blocksValidator;

	public function __construct(
		public readonly array $input,
		public readonly DocumentDescription $documentType,
		?AgentConnectionsValidator $connectionsValidator =  null,
		?AgentBlocksValidator $blocksValidator = null,
	) {
		$this->connectionsValidator = $connectionsValidator ?? new AgentConnectionsValidator();
		$this->blocksValidator = $blocksValidator ?? new AgentBlocksValidator();
	}

	public function validate(): AgentWorkflowValidationResult|Result
	{
		$validateBlocksResult = $this->blocksValidator->validate(
			blocks: $this->input['blocks'] ?? null,
			documentType: $this->documentType,
			path: 'blocks',
		);

		$validateConnectionsResult = $this->connectionsValidator->validate(
			connections: $this->input['connections'] ?? null,
			blockIds: $this->blocksValidator->getBlockIds(),
			path: 'connections',
		);

		if ($validateBlocksResult->isSuccess() && $validateConnectionsResult->isSuccess())
		{
			return new AgentWorkflowValidationResult(
				connections: $this->connectionsValidator->getValidConnections(),
				blocks: $this->blocksValidator->getValidBlocks(),
			);
		}

		return (new Result())
			->addErrors($validateBlocksResult->getErrors())
			->addErrors($validateConnectionsResult->getErrors())
		;
	}
}