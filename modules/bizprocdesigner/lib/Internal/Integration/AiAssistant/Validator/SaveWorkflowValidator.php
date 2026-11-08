<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class SaveWorkflowValidator
{
	private readonly AgentConnectionsValidator $connectionsValidator;
	private readonly AgentBlocksValidator $blocksValidator;
	private readonly AgentFrameMembershipValidator $frameMembershipValidator;
	private readonly GraphLimitsValidator $limitsValidator;

	/**
	 * How strictly this graph is judged, read from the context; the frame membership overlay is the one branch it
	 * drives here. Fail-closed like {@see ValidationProfile::of()}: no context at all means the internal assistant,
	 * that is the least and not the most permissive profile.
	 */
	private readonly ValidationProfile $profile;

	/**
	 * Non-blocking risks the graph pass named on a graph it accepted (DTO-02), kept apart from the errors because
	 * an error decides the verdict and a warning never does. The channel is complete from here to the report
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper}); the risks
	 * themselves are named by the graph pass ({@see AgentBlocksValidator::getWarnings()}) and only carried here.
	 *
	 * @var list<Error>
	 */
	private array $warnings = [];

	public function __construct(
		public readonly array $input,
		public readonly DocumentDescription $documentType,
		?AgentConnectionsValidator $connectionsValidator =  null,
		?AgentBlocksValidator $blocksValidator = null,
		public readonly ?ValidationContext $context = null,
		?AgentFrameMembershipValidator $frameMembershipValidator = null,
		?GraphLimitsValidator $limitsValidator = null,
	) {
		$this->profile = ValidationProfile::of($context);
		$this->connectionsValidator = $connectionsValidator ?? new AgentConnectionsValidator();
		$this->blocksValidator = $blocksValidator ?? new AgentBlocksValidator(context: $context);
		$this->frameMembershipValidator = $frameMembershipValidator ?? new AgentFrameMembershipValidator();
		$this->limitsValidator = $limitsValidator ?? new GraphLimitsValidator();
	}

	public function validate(): AgentWorkflowValidationResult|Result
	{
		$this->warnings = [];

		// Bounds first: a graph over them is refused by its size, not walked block by block through the
		// catalog lookups the ordinary validators do.
		$limitsResult = $this->limitsValidator->validate(
			blocks: $this->input['blocks'] ?? null,
			connections: $this->input['connections'] ?? null,
			profile: $this->profile,
		);
		if (!$limitsResult->isSuccess())
		{
			return $limitsResult;
		}

		$validateBlocksResult = $this->blocksValidator->validate(
			blocks: $this->input['blocks'] ?? null,
			documentType: $this->documentType,
			path: 'blocks',
			// The order of creating and using storage is a question about blocks AND edges at once (ALG-03), so
			// the graph pass is handed the connections as submitted; it reads them and judges them nowhere.
			connections: $this->input['connections'] ?? null,
		);
		$this->warnings = $this->blocksValidator->getWarnings();

		$validateConnectionsResult = $this->connectionsValidator->validate(
			connections: $this->input['connections'] ?? null,
			blockIds: $this->blocksValidator->getBlockIds(),
			path: 'connections',
			blockPortsMap: $this->blocksValidator->getBlockPortsMap(),
			blockLoopbackInputPortsMap: $this->blocksValidator->getBlockLoopbackInputPortsMap(),
			frameBlockIds: $this->blocksValidator->getFrameBlockIds(),
		);

		// Frame membership is an overlay of the external contour; for the internal assistant frames are already
		// rejected at block level.
		$validateMembershipResult = new Result();
		if ($this->profile->allowsFrameOverlay())
		{
			$validateMembershipResult = $this->frameMembershipValidator->validate(
				blocks: $this->input['blocks'] ?? null,
				connections: $this->input['connections'] ?? null,
				path: 'blocks',
			);
		}

		if (
			$validateBlocksResult->isSuccess()
			&& $validateConnectionsResult->isSuccess()
			&& $validateMembershipResult->isSuccess()
		)
		{
			return new AgentWorkflowValidationResult(
				connections: $this->connectionsValidator->getValidConnections(),
				blocks: $this->blocksValidator->getValidBlocks(),
				setupConstantDeclarations: $this->blocksValidator->getSetupConstantDeclarations(),
				warnings: $this->getWarnings(),
			);
		}

		return (new Result())
			->addErrors($validateBlocksResult->getErrors())
			->addErrors($validateConnectionsResult->getErrors())
			->addErrors($validateMembershipResult->getErrors())
		;
	}

	/**
	 * The warnings of the last run, or none at all for a profile that emits none: the internal assistant is
	 * answered with the errors alone whatever a check has named (TPL-02 `emitsWarnings`).
	 *
	 * @return list<Error>
	 */
	public function getWarnings(): array
	{
		return $this->profile->emitsWarnings() ? $this->warnings : [];
	}
}
