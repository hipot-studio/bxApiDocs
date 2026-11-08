<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftCreatorService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\OperationAuthorizationGuard;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserAdminResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlocksValidator;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentConnectionsValidator;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\SaveWorkflowValidator;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class SaveWorkflowTool extends BizprocDesignerTool
{
	private readonly AiAssistantDraftCreatorService $aiAssistantDraftCreatorService;
	private readonly AiAssistantDraftConverterService $aiAssistantDraftConverterService;
	private readonly LastWorkflowService $lastWorkflowService;
	private readonly OperationAuthorizationGuard $operationAuthorizationGuard;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?AiAssistantDraftCreatorService $aiAssistantDraftCreatorService = null,
		?AiAssistantDraftConverterService $aiAssistantDraftConverterService = null,
		?LastWorkflowService $lastWorkflowService = null,
		?OperationAuthorizationGuard $operationAuthorizationGuard = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);
		$this->aiAssistantDraftCreatorService = $aiAssistantDraftCreatorService ?? Container::getAiAssistantDraftCreatorService();
		$this->aiAssistantDraftConverterService = $aiAssistantDraftConverterService
			?? Container::getAiAssistantDraftConverterService()
		;
		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
		$this->operationAuthorizationGuard = $operationAuthorizationGuard ?? new OperationAuthorizationGuard();
	}

	public function getName(): string
	{
		return 'save_workflow_template';
	}

	public function getDescription(): string
	{
		return 'Represent workflow template for user';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'blocks' => [
					'description' => 'Array of block descriptions',
					'type' => 'array',
					// DoS guard: layout/topology processing is polynomial in the number of blocks.
					// Real templates have dozens of blocks, rarely hundreds - this is a generous upper
					// bound that never clips legitimate workflows, not a business limit. Same bound the
					// server-side validator enforces (single source of truth), so REST and Marta agree.
					'maxItems' => AgentBlocksValidator::MAX_BLOCKS,
					'items' => [
						'type' => 'object',
						'properties' => [
							'type' => [
								'description' => 'Type of block, one of described types',
								'type' => 'string',
							],
							'title' => [
								'description' => 'Optional user-facing title of the block. Provide a meaningful name only when it adds value beyond the system block name; no BB-code or other tags available, only plain-text',
								'type' => 'string',
								// Sanitary cap to keep the user-facing title short, no BB-code or tags.
								'maxLength' => 255,
							],
							'description' => [
								'description' => 'Optional description of the block and its role in the current workflow, no BB-code or other tags available, only plain-text',
								'type' => 'string',
								// Sanitary cap on the free-form block description.
								'maxLength' => 1000,
							],
							'presetId' => [
								'description' => 'Optional preset identifier for multi-preset blocks: it selects the concrete variant (e.g. CONTACT, DEAL) and its system name. Reuse the presetId the block carries in the get_workflow_template response; keep it verbatim when editing an existing block. Required for blocks whose type exposes presets.',
								'type' => 'string',
								// Sanitary cap on the preset identifier, kept in sync with AgentBlockValidator::PRESET_ID_MAX_LENGTH.
								'maxLength' => 255,
							],
							'settings' => [
								'type' => 'array',
								'description' => 'Settings of block different from type to type',
								'items' => [
									'type' => 'object',
									'properties' => [
										'name' => [
											'description' => 'Setting name from get_block_settings tool response, unique in array elements',
											'type' => 'string',
										],
										'value' => [
											'description' => 'Value of the block setting for the current action in accordance with the purpose and role in the business process and user desires, can be fixed value of described type or if setting allows multiple values, then array of values of described type',
											'anyOf' => [
												['type' => 'string'],
												['type' => 'array', 'items' => ['type' => 'string']],
												['type' => 'object'],
											],
										],
									],
									'required' => ['name', 'value'],
									'additionalProperties' => false,
								],
							],
							'id' => [
								'type' => 'string',
								'description' => 'Unique identifier of block, should be used in connections, use existed block identifier from get_workflow_template tool or use unique numeric identifier for new block'
							],
							'rules' => self::getComplexRulesSchema(),
						],
						'required' => ['type', 'id', 'settings'],
						'additionalProperties' => false,
					],
				],
				'connections' => [
					'description' => 'Array of connections of blocks',
					'type' => 'array',
					// DoS guard: connection count drives the same polynomial layout work. Kept at twice
					// the block bound to allow branching/loops on a maximal graph without clipping. Same
					// bound the server-side validator enforces (single source of truth), so REST and Marta agree.
					'maxItems' => AgentConnectionsValidator::MAX_CONNECTIONS,
					'items' => [
						'type' => 'object',
						'properties' => [
							'sourceBlockId' => [
								'description' => 'Unique identifier of source block',
								'type' => 'string',
							],
							'destinationBlockId' => [
								'description' => 'Unique identifier of destination block',
								'type' => 'string',
							],
							'sourcePortId' => [
								'description' => 'Optional output port of the source block (o0, o1, ...) taken from the block ports in get_block_settings; omit for the default o0',
								'type' => 'string',
							],
							'targetPortId' => [
								'description' => 'Optional input port of the destination block (i0, i1, ...) taken from the block ports in get_block_settings; omit for the default i0',
								'type' => 'string',
							],
						],
						'required' => ['sourceBlockId', 'destinationBlockId'],
						'additionalProperties' => false,
					],
				],
			],
			'required' => ['blocks', 'connections'],
			'additionalProperties' => false,
		];
	}

	/**
	 * Optional nested rules projection (DTO-01) of a complex node (COMPLEX). A map from input port id
	 * (i0, i1, ...) to an ordered list of rules; each rule is an ordered list of constructions of the
	 * closed allowlist condition/action/filter/output (code/php not expressible by construction). Only
	 * complex nodes carry it; simple and operator nodes omit it. The node's ports are defined here: input
	 * ports are the map keys, output ports are the portId of every output construction.
	 *
	 * The maxProperties/maxItems bounds are DoS guards (rules are processed before graph materialisation);
	 * they are generous upper bounds that never clip a legitimate complex node, not business limits.
	 */
	private static function getComplexRulesSchema(): array
	{
		return [
			'description' => 'Optional nested rules projection for complex (COMPLEX) nodes only: a map from input port id (i0, i1, ...) to an ordered list of rules. Omit for simple and operator nodes. The node ports are defined by this projection: input ports are the keys, output ports are the portId of every output construction.',
			'type' => 'object',
			'maxProperties' => 64,
			'additionalProperties' => [
				'type' => 'array',
				'maxItems' => 128,
				'items' => [
					'type' => 'object',
					'properties' => [
						'id' => [
							'description' => 'Stable rule identifier, unique within the block',
							'type' => 'string',
						],
						'constructions' => [
							'description' => 'Ordered list of constructions; the order is significant',
							'type' => 'array',
							'maxItems' => 128,
							'items' => [
								'type' => 'object',
								'properties' => [
									'type' => [
										'description' => 'Construction kind. For switchnode only condition and output are valid. code/php conditions are not expressible.',
										'type' => 'string',
										'enum' => AgentComplexConstruction::TYPES,
									],
									'condition' => self::getConditionSchema(),
									'action' => [
										'description' => 'Sub-action (present when type=action). activityCode must be one of the block complexActions[].activityCode from get_block_settings; rejected for switchnode (empty dictionary).',
										'type' => 'object',
										'properties' => [
											'activityCode' => [
												'description' => 'Sub-action activity code from complexActions[].activityCode',
												'type' => 'string',
											],
											'settings' => [
												'description' => 'Sub-action settings',
												// A settings map ({name: value}) or an empty []; a non-empty list is
												// rejected - it would be saved under the numeric key 0 and lose the value.
												'anyOf' => [
													['type' => 'object'],
													['type' => 'array', 'maxItems' => 0],
												],
											],
											'document' => [
												'description' => 'Optional document expression linking the action to a preceding filter result',
												'type' => 'string',
											],
											'auxPortId' => [
												'description' => 'Optional aux output port id of the sub-action (manual-editor aux branch). Carried verbatim on round-trip; omit for a plain sub-action — do not invent it.',
												'type' => 'string',
											],
											'auxPortTitle' => [
												'description' => 'Optional aux output port title of the sub-action, paired with auxPortId. Carried verbatim on round-trip; omit for a plain sub-action.',
												'type' => 'string',
											],
										],
										'required' => ['activityCode'],
										'additionalProperties' => false,
									],
									'filter' => [
										'description' => 'Filter construction (present when type=filter). Allowed only when the block complexActions.filterSupported is true and the backing activity is a node-filter provider.',
										'type' => 'object',
										'properties' => [
											'activityCode' => [
												'description' => 'Filter backing activity code',
												'type' => 'string',
											],
											'settings' => [
												'description' => 'Filter settings',
												// A settings map ({name: value}) or an empty []; a non-empty list is
												// rejected - it would be saved under the numeric key 0 and lose the value.
												'anyOf' => [
													['type' => 'object'],
													['type' => 'array', 'maxItems' => 0],
												],
											],
											'filterId' => [
												'description' => 'Optional stable filter identity carried on round-trip so consumer references ({=<filterId>:Document}) keep resolving. Preserve it verbatim when editing an existing filter; omit for a new filter — do not invent it.',
												'type' => 'string',
											],
										],
										'required' => ['activityCode'],
										'additionalProperties' => false,
									],
									'output' => [
										'description' => 'Output port (present when type=output).',
										'type' => 'object',
										'properties' => [
											'portId' => [
												'description' => 'Output port id (o0, o1, ...)',
												'type' => 'string',
											],
											'title' => [
												'description' => 'Output port title, plain text',
												'type' => 'string',
											],
										],
										'required' => ['portId', 'title'],
										'additionalProperties' => false,
									],
								],
								'required' => ['type'],
								'additionalProperties' => false,
							],
						],
					],
					'required' => ['id', 'constructions'],
					'additionalProperties' => false,
				],
			],
		];
	}

	/**
	 * Condition contract (DTO-03), present when construction type=condition: a field-based comparison
	 * projected from mixedcondition. Field codes are stable (object + fieldId), not localized labels;
	 * DNF is expressed through the joiner (OR opens a new group, AND stays inside; the first item of a
	 * group is treated as AND). The value is carried verbatim, including bizproc expressions.
	 */
	private static function getConditionSchema(): array
	{
		return [
			'description' => 'Condition (present when type=condition). Field-based comparison; DNF via joiner.',
			'type' => 'object',
			'properties' => [
				'field' => [
					'type' => 'object',
					'properties' => [
						'object' => [
							'description' => 'Stable object code (Document/Variable/Constant/<activityName>/...)',
							'type' => 'string',
						],
						'fieldId' => [
							'description' => 'Stable field identifier, not a localized label',
							'type' => 'string',
						],
						'type' => [
							'description' => 'Field type, for typed value restoration',
							'type' => ['string', 'null'],
						],
						'multiple' => [
							'description' => 'Field multiplicity, carried verbatim',
							'type' => ['integer', 'null'],
						],
						'options' => [
							'description' => 'Field options, carried verbatim',
							'type' => ['object', 'array', 'null'],
						],
						'settings' => [
							'description' => 'Field settings, carried verbatim',
							'type' => ['object', 'array', 'null'],
						],
					],
					'required' => ['object', 'fieldId'],
					'additionalProperties' => false,
				],
				'operator' => [
					'description' => 'Comparison operator (=, !=, empty, !empty, contain, in, >, >=, <, <=, ...)',
					'type' => 'string',
				],
				'value' => [
					'description' => 'Comparison value; a plain value, an array, or a bizproc expression {=...}/{{=...}} carried verbatim',
					'anyOf' => [
						['type' => 'string'],
						['type' => 'number'],
						['type' => 'boolean'],
						['type' => 'array'],
						['type' => 'object'],
						['type' => 'null'],
					],
				],
				'joiner' => [
					'description' => 'DNF joiner: OR opens a new group, AND stays inside the group; the first item of a group is treated as AND',
					'type' => 'string',
					'enum' => ['AND', 'OR'],
				],
			],
			'required' => ['field', 'operator'],
			'additionalProperties' => false,
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$templateIdentifier = $this->lastWorkflowService->getUserLastWorkflowTemplateIdentifier($userId);
		$documentType = $templateIdentifier?->documentDescription;

		if ($documentType === null)
		{
			return 'Error: No user saved document type';
		}

		$isUserAdmin = UserAdminResolver::isAdmin($userId);
		$verdict = $this->operationAuthorizationGuard->authorize(
			$userId,
			$documentType,
			(int)($templateIdentifier->templateId ?? 0),
			$isUserAdmin,
		);
		if (!$verdict->allowed)
		{
			return 'Error: User access denied for this document type';
		}

		$this->logUsage($userId, (array)$args);

		$validator = new SaveWorkflowValidator(
			input: $args,
			documentType: $documentType,
			context: (new ValidationContextFactory())->create(
				$templateIdentifier,
				RequestSource::Marta,
				$userId,
				$isUserAdmin,
			),
		);

		$validateResult = $validator->validate();
		if (!$validateResult instanceof AgentWorkflowValidationResult)
		{
			if (empty($validateResult->getErrors()))
			{
				return 'Error: validation logic error';
			}

			return 'Error: ' . implode(', ', $validateResult->getErrorMessages());
		}

		$draft = $this->aiAssistantDraftConverterService->covertFromAgentInput(
			draftId: $templateIdentifier->draftId ?? 0,
			templateId: $templateIdentifier->templateId ?? 0,
			userId: $userId,
			blocks: $validateResult->blocks,
			connections: $validateResult->connections,
			documentType: $documentType->toBizprocComplexType(),
			source: RequestSource::Marta,
		);

		$isSuccess = $this->aiAssistantDraftCreatorService->pushDraft($draft);

		return $isSuccess ?  'Workflow saved successfully' : 'Error saving workflow';
	}

	private function logUsage(int $userId, array $args): void
	{
		$this->tracedLogger->debug('Running tool {toolName} for user {userId}, args: {args}', [
			'toolName' => $this->getName(),
			'userId' => $userId,
			'args' => Json::encode($args, Json::DEFAULT_OPTIONS | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		]);
	}
}