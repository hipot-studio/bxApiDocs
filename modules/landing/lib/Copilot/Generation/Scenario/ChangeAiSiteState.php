<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\Copilot\Generation;

final class ChangeAiSiteState
{
	public const INPUT_KEY = 'change_ai_site_input';
	public const SELECTED_ELEMENT_CONTEXT_KEY = 'change_ai_site_selected_element_context';
	public const CANDIDATES_KEY = 'change_ai_site_candidates';
	public const ACTIONS_KEY = 'change_ai_site_actions';
	public const HTML_BLOCKS_KEY = 'change_ai_site_html_blocks';
	public const BLOCK_INSTRUCTIONS_KEY = 'change_ai_site_block_instructions';
	public const EXECUTION_PLAN_KEY = 'change_ai_site_execution_plan';
	public const APPLIED_OPERATIONS_KEY = 'change_ai_site_applied_operations';
	public const RESULT_KEY = 'change_ai_site_result';
	public const TAILWIND_RUNTIME_KEY = 'change_ai_site_tailwind_runtime';
	public const SELECTION_DEBUG_KEY = 'change_ai_site_selection_debug';
	public const STYLE_CONTEXT_KEY = 'change_ai_site_style_context';
	public const SYNCED_FONTS_KEY = 'change_ai_site_synced_fonts';
	public const HTML_CONTRACT_VALIDATION_KEY = 'change_ai_site_html_contract_validation';
	public const START_EVENT_SENT_KEY = 'change_ai_site_start_event_sent';
	public const EDIT_MODE_TARGETED = 'targeted_edit';
	public const EDIT_MODE_BALANCED = 'balanced_edit';
	public const EDIT_MODE_GLOBAL = 'global_edit';
	public const APPLIED_STATUS_APPLIED = 'applied';
	public const APPLIED_STATUS_SKIPPED = 'skipped';
	private const HTML_CONTRACT_VALIDATION_ENABLED = false;
	private const STYLE_CONTEXT_GROUPS = [
		'fonts',
		'colors',
		'surfaces',
		'layout',
		'cta',
		'typography',
	];
	private const STYLE_CONTEXT_GROUP_LIMIT = 10;
	private const STYLE_CONTEXT_PROFILE_GROUP_LIMIT = 8;
	private const STYLE_CONTEXT_PROMPT_LIMIT = 2000;

	public static function getInput(Generation $generation): array
	{
		return self::getArrayData($generation, self::INPUT_KEY);
	}

	public static function setInput(Generation $generation, array $data): void
	{
		$generation->setData(self::INPUT_KEY, [
			'landingId' => self::normalizeLandingId($data['landingId'] ?? null),
			'input' => self::normalizeInputLines($data['input'] ?? null),
		]);
	}

	public static function getSelectedElementContext(Generation $generation): array
	{
		return self::normalizeSelectedElementContext(
			self::getArrayData($generation, self::SELECTED_ELEMENT_CONTEXT_KEY),
		);
	}

	public static function setSelectedElementContext(Generation $generation, array $context): void
	{
		$generation->setData(
			self::SELECTED_ELEMENT_CONTEXT_KEY,
			self::normalizeSelectedElementContext($context),
		);
	}

	public static function resetRuntimeData(Generation $generation): void
	{
		self::setCandidates($generation, []);
		self::setActions($generation, []);
		self::setHtmlBlocks($generation, []);
		self::setBlockInstructions($generation, []);
		self::setExecutionPlan($generation, []);
		self::setAppliedOperations($generation, []);
		self::setResult($generation, []);
		self::setSelectionDebug($generation, []);
		self::setTailwindRuntime($generation, []);
		self::setStyleContext($generation, []);
		self::setSyncedFonts($generation, []);
	}

	public static function isStartEventSent(Generation $generation): bool
	{
		return (bool)($generation->getData(self::START_EVENT_SENT_KEY) ?? false);
	}

	public static function markStartEventSent(Generation $generation): void
	{
		$generation->setData(self::START_EVENT_SENT_KEY, true);
	}

	public static function resolveLandingId(Generation $generation): int
	{
		return self::normalizeLandingId(self::getInput($generation)['landingId'] ?? null);
	}

	public static function resolveInputLines(Generation $generation): array
	{
		return self::normalizeInputLines(self::getInput($generation)['input'] ?? null);
	}

	public static function getActions(Generation $generation): array
	{
		return self::normalizeActions(self::getArrayData($generation, self::ACTIONS_KEY));
	}

	public static function setActions(Generation $generation, array $actions): void
	{
		$generation->setData(self::ACTIONS_KEY, self::normalizeActions($actions));
	}

	public static function getHtmlBlocks(Generation $generation): array
	{
		return self::getArrayData($generation, self::HTML_BLOCKS_KEY);
	}

	public static function setHtmlBlocks(Generation $generation, array $htmlBlocks): void
	{
		$generation->setData(self::HTML_BLOCKS_KEY, array_values($htmlBlocks));
	}

	public static function getTailwindRuntime(Generation $generation): array
	{
		return self::getArrayData($generation, self::TAILWIND_RUNTIME_KEY);
	}

	public static function getBlockInstructions(Generation $generation): array
	{
		return self::normalizeBlockInstructions(self::getArrayData($generation, self::BLOCK_INSTRUCTIONS_KEY));
	}

	public static function setBlockInstructions(Generation $generation, array $data): void
	{
		$generation->setData(self::BLOCK_INSTRUCTIONS_KEY, self::normalizeBlockInstructions($data));
	}

	public static function getExecutionPlan(Generation $generation): array
	{
		return self::normalizeExecutionPlan(self::getArrayData($generation, self::EXECUTION_PLAN_KEY));
	}

	public static function setExecutionPlan(Generation $generation, array $plan): void
	{
		$generation->setData(self::EXECUTION_PLAN_KEY, self::normalizeExecutionPlan($plan));
	}

	public static function getAppliedOperations(Generation $generation): array
	{
		return self::normalizeAppliedOperations(self::getArrayData($generation, self::APPLIED_OPERATIONS_KEY));
	}

	public static function setAppliedOperations(Generation $generation, array $operations): void
	{
		$generation->setData(self::APPLIED_OPERATIONS_KEY, self::normalizeAppliedOperations($operations));
	}

	public static function getPublicAppliedOperations(Generation $generation, bool $onlyApplied = false): array
	{
		return self::projectAppliedOperations(
			self::getAppliedOperations($generation),
			$onlyApplied,
		);
	}

	public static function resolveBlockInstruction(Generation $generation, int $blockId): string
	{
		if ($blockId <= 0)
		{
			return '';
		}

		$instructions = self::getBlockInstructions($generation);
		foreach ($instructions['items'] as $item)
		{
			if ((int)($item['blockId'] ?? 0) !== $blockId)
			{
				continue;
			}

			return trim((string)($item['instruction'] ?? ''));
		}

		return '';
	}

	public static function resolveActionInstruction(Generation $generation, string $actionId): string
	{
		$actionId = trim($actionId);
		if ($actionId === '')
		{
			return '';
		}

		$instructions = self::getBlockInstructions($generation);
		foreach ($instructions['items'] as $item)
		{
			if (trim((string)($item['actionId'] ?? '')) !== $actionId)
			{
				continue;
			}

			return trim((string)($item['instruction'] ?? ''));
		}

		return '';
	}

	public static function resolveGlobalConstraints(Generation $generation): string
	{
		$instructions = self::getBlockInstructions($generation);

		return trim((string)($instructions['globalConstraints'] ?? ''));
	}

	public static function resolveImageStyleBrief(Generation $generation): string
	{
		$instructions = self::getBlockInstructions($generation);

		return trim((string)($instructions['imageStyleBrief'] ?? ''));
	}

	public static function resolveDesignBrief(Generation $generation): string
	{
		$instructions = self::getBlockInstructions($generation);

		return trim((string)($instructions['designBrief'] ?? ''));
	}

	public static function resolveActionEditMode(Generation $generation, string $actionId, int $blockId = 0): string
	{
		$actionId = trim($actionId);
		$instructions = self::getBlockInstructions($generation);
		foreach ($instructions['items'] as $item)
		{
			if ($actionId !== '' && trim((string)($item['actionId'] ?? '')) === $actionId)
			{
				return self::normalizeEditMode($item['editMode'] ?? '');
			}

			if ($actionId === '' && $blockId > 0 && (int)($item['blockId'] ?? 0) === $blockId)
			{
				return self::normalizeEditMode($item['editMode'] ?? '');
			}
		}

		return self::EDIT_MODE_BALANCED;
	}

	public static function setTailwindRuntime(Generation $generation, array $data): void
	{
		$generation->setData(self::TAILWIND_RUNTIME_KEY, $data);
	}

	public static function getStyleContext(Generation $generation): array
	{
		return self::normalizeStyleContext(self::getArrayData($generation, self::STYLE_CONTEXT_KEY));
	}

	public static function setStyleContext(Generation $generation, array $data): void
	{
		$generation->setData(self::STYLE_CONTEXT_KEY, self::normalizeStyleContext($data));
	}

	public static function resolvePromptStyleContext(Generation $generation): string
	{
		$context = self::getStyleContext($generation);
		if ($context === [])
		{
			return '';
		}

		$encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($encoded))
		{
			return '';
		}

		if (mb_strlen($encoded) > self::STYLE_CONTEXT_PROMPT_LIMIT)
		{
			$encoded = rtrim(mb_substr($encoded, 0, self::STYLE_CONTEXT_PROMPT_LIMIT - 1)) . '…';
		}

		return $encoded;
	}

	public static function getSyncedFonts(Generation $generation): array
	{
		return self::normalizeSyncedFonts(self::getArrayData($generation, self::SYNCED_FONTS_KEY));
	}

	public static function setSyncedFonts(Generation $generation, array $fonts): void
	{
		$generation->setData(self::SYNCED_FONTS_KEY, self::normalizeSyncedFonts($fonts));
	}

	public static function getResult(Generation $generation): array
	{
		return self::normalizeResult(self::getArrayData($generation, self::RESULT_KEY));
	}

	public static function setResult(Generation $generation, array $result): void
	{
		$generation->setData(self::RESULT_KEY, self::normalizeResult($result));
	}

	public static function setHtmlContractValidationEnabled(Generation $generation, bool $enabled): void
	{
		$generation->setData(self::HTML_CONTRACT_VALIDATION_KEY, ['enabled' => $enabled]);
	}

	public static function isHtmlContractValidationEnabled(?Generation $generation = null): bool
	{
		if ($generation)
		{
			$data = self::getArrayData($generation, self::HTML_CONTRACT_VALIDATION_KEY);
			if (array_key_exists('enabled', $data))
			{
				return !empty($data['enabled']);
			}
		}

		return self::HTML_CONTRACT_VALIDATION_ENABLED;
	}

	public static function mergeResult(Generation $generation, array $result): void
	{
		$current = self::getResult($generation);
		$incoming = self::normalizeResult($result);

		self::setResult($generation, [
			'changedIds' => array_merge(
				$current['changedIds'],
				$incoming['changedIds'],
			),
			'updatedIds' => array_merge(
				$current['updatedIds'],
				$incoming['updatedIds'],
			),
			'createdIds' => array_merge(
				$current['createdIds'],
				$incoming['createdIds'],
			),
			'deletedIds' => array_merge(
				$current['deletedIds'],
				$incoming['deletedIds'],
			),
			'movedIds' => array_merge(
				$current['movedIds'],
				$incoming['movedIds'],
			),
			'skippedIds' => array_merge(
				$current['skippedIds'],
				$incoming['skippedIds'],
			),
			'skippedReasons' => array_replace(
				$current['skippedReasons'],
				$incoming['skippedReasons'],
			),
			'moveResults' => array_replace(
				$current['moveResults'],
				$incoming['moveResults'],
			),
		]);
	}

	public static function buildResultFromAppliedOperations(array $operations): array
	{
		$operations = self::normalizeAppliedOperations($operations);
		$changedIds = [];
		$updatedIds = [];
		$createdIds = [];
		$deletedIds = [];
		$movedIds = [];
		$moveResults = [];
		$skippedIds = [];
		$skippedReasons = [];

		foreach ($operations as $operation)
		{
			$blockId = max(0, (int)($operation['blockId'] ?? 0));
			$type = (string)($operation['type'] ?? '');
			$status = (string)($operation['status'] ?? self::APPLIED_STATUS_SKIPPED);

			if ($status === self::APPLIED_STATUS_APPLIED)
			{
				if ($blockId > 0)
				{
					$changedIds[] = $blockId;
				}

				if ($type === 'update_block' && $blockId > 0)
				{
					$updatedIds[] = $blockId;
				}
				elseif ($type === 'add_block' && $blockId > 0)
				{
					$createdIds[] = $blockId;
				}
				elseif ($type === 'delete_block' && $blockId > 0)
				{
					$deletedIds[] = $blockId;
				}
				elseif ($type === 'move_block' && $blockId > 0)
				{
					$movedIds[] = $blockId;
					$moveResults[$blockId] = [
						'actionId' => self::normalizeText((string)($operation['actionId'] ?? '')),
						'placement' => self::normalizePlacement(
							$operation['resolvedPlacement'] ?? ($operation['requestedPlacement'] ?? []),
						),
						'fromIndex' => max(0, (int)($operation['fromIndex'] ?? 0)),
						'toIndex' => max(0, (int)($operation['toIndex'] ?? 0)),
					];
				}

				unset($skippedReasons[(string)$blockId]);

				continue;
			}

			$skipReason = self::normalizeText((string)($operation['skipReason'] ?? ''));
			if ($blockId > 0)
			{
				$skippedIds[] = $blockId;
			}
			if ($skipReason !== '')
			{
				$skippedReasons[(string)$blockId] = $skipReason;
			}
		}

		return self::normalizeResult([
			'changedIds' => $changedIds,
			'updatedIds' => $updatedIds,
			'createdIds' => $createdIds,
			'deletedIds' => $deletedIds,
			'movedIds' => $movedIds,
			'moveResults' => $moveResults,
			'skippedIds' => $skippedIds,
			'skippedReasons' => $skippedReasons,
		]);
	}

	public static function getCandidates(Generation $generation): array
	{
		return self::getArrayData($generation, self::CANDIDATES_KEY);
	}

	public static function setCandidates(Generation $generation, array $candidates): void
	{
		$generation->setData(self::CANDIDATES_KEY, self::normalizeCandidates($candidates));
	}

	public static function resolveRawInput(Generation $generation): string
	{
		return implode("\n", self::resolveInputLines($generation));
	}

	public static function getSelectionDebug(Generation $generation): array
	{
		return self::normalizeSelectionDebug(self::getArrayData($generation, self::SELECTION_DEBUG_KEY));
	}

	public static function setSelectionDebug(Generation $generation, array $data): void
	{
		$generation->setData(self::SELECTION_DEBUG_KEY, self::normalizeSelectionDebug($data));
	}

	public static function buildPromptMarkers(array $replacements): array
	{
		return self::preparePromptReplacements($replacements);
	}

	private static function preparePromptReplacements(array $replacements): array
	{
		$replacements += [
			'{{style_context}}' => '',
			'{{design_brief}}' => '',
			'{{edit_mode}}' => '',
		];
		$replacements['{{ai_font_policy}}'] = AiFontPolicy::getPromptInstructions();

		return $replacements;
	}

	public static function normalizeInputLines(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$normalized = [];
		foreach ($value as $item)
		{
			if (!is_string($item))
			{
				continue;
			}

			$line = self::normalizeText($item);
			if ($line !== '')
			{
				$normalized[] = $line;
			}
		}

		return array_values($normalized);
	}

	private static function getArrayData(Generation $generation, string $key): array
	{
		$data = $generation->getData($key);

		return is_array($data) ? $data : [];
	}

	private static function normalizeLandingId(mixed $value): int
	{
		return max(0, (int)$value);
	}

	private static function normalizeSelectedElementContext(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$blockId = self::normalizeLandingId($value['blockId'] ?? null);
		$selector = self::normalizeText((string)($value['selector'] ?? ''));

		if ($blockId <= 0 || $selector === '')
		{
			return [];
		}

		return [
			'blockId' => $blockId,
			'selector' => $selector,
		];
	}

	private static function normalizeIds(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$normalized = array_filter(
			array_map(static fn(mixed $id): int => (int)$id, $value),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($normalized));
	}

	private static function normalizeResult(array $result): array
	{
		$updatedIds = self::normalizeIds($result['updatedIds'] ?? null);
		$createdIds = self::normalizeIds($result['createdIds'] ?? null);
		$deletedIds = self::normalizeIds($result['deletedIds'] ?? null);
		$movedIds = self::normalizeIds($result['movedIds'] ?? null);
		$changedIds = self::normalizeIds(array_merge(
			self::normalizeIds($result['changedIds'] ?? null),
			$updatedIds,
			$createdIds,
			$deletedIds,
			$movedIds,
		));

		return [
			'changedIds' => $changedIds,
			'updatedIds' => $updatedIds,
			'createdIds' => $createdIds,
			'deletedIds' => $deletedIds,
			'movedIds' => $movedIds,
			'moveResults' => self::normalizeMoveResults($result['moveResults'] ?? null),
			'skippedIds' => self::normalizeIds($result['skippedIds'] ?? null),
			'skippedReasons' => self::normalizeSkippedReasons($result['skippedReasons'] ?? null),
		];
	}

	private static function normalizeMoveResults(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$normalized = [];
		foreach ($value as $blockId => $result)
		{
			$normalizedBlockId = max(0, (int)$blockId);
			if ($normalizedBlockId <= 0 || !is_array($result))
			{
				continue;
			}

			$item = $result;
			if (array_key_exists('placement', $item))
			{
				$item['placement'] = self::normalizePlacement($item['placement']);
			}
			if (array_key_exists('fromIndex', $item))
			{
				$item['fromIndex'] = max(0, (int)$item['fromIndex']);
			}
			if (array_key_exists('toIndex', $item))
			{
				$item['toIndex'] = max(0, (int)$item['toIndex']);
			}

			$normalized[$normalizedBlockId] = $item;
		}

		return $normalized;
	}

	private static function normalizeSkippedReasons(mixed $value): array
	{
		return is_array($value) ? $value : [];
	}

	private static function normalizeExecutionPlan(array $plan): array
	{
		$normalized = [];
		foreach ($plan as $index => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$type = self::normalizeActionType($item['type'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$actionId = self::normalizeText((string)($item['actionId'] ?? ''));
			if ($actionId === '')
			{
				continue;
			}

			$seq = max(1, (int)($item['seq'] ?? ((int)$index + 1)));
			$normalized[$actionId] = [
				'seq' => $seq,
				'actionId' => $actionId,
				'type' => $type,
				'blockId' => max(0, (int)($item['blockId'] ?? 0)),
				'sourceBlockId' => max(0, (int)($item['sourceBlockId'] ?? 0)),
			];

			if (array_key_exists('requestedPlacement', $item) || array_key_exists('placement', $item))
			{
				$normalized[$actionId]['requestedPlacement'] = self::normalizePlacement(
					$item['requestedPlacement'] ?? $item['placement'],
				);
			}
		}

		usort($normalized, static function(array $left, array $right): int {
			$sequenceCompare = ($left['seq'] ?? 0) <=> ($right['seq'] ?? 0);

			return $sequenceCompare !== 0
				? $sequenceCompare
				: strcmp((string)($left['actionId'] ?? ''), (string)($right['actionId'] ?? ''))
			;
		});

		return array_values($normalized);
	}

	private static function normalizeAppliedOperations(mixed $operations): array
	{
		if (!is_array($operations))
		{
			return [];
		}

		$normalized = [];
		foreach ($operations as $index => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$type = self::normalizeActionType($item['type'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$actionId = self::normalizeText((string)($item['actionId'] ?? ''));
			if ($actionId === '')
			{
				continue;
			}

			$seq = max(1, (int)($item['seq'] ?? ((int)$index + 1)));
			$status = self::normalizeAppliedStatus($item['status'] ?? null);
			$normalized[$actionId] = [
				'seq' => $seq,
				'actionId' => $actionId,
				'type' => $type,
				'status' => $status,
				'blockId' => max(0, (int)($item['blockId'] ?? 0)),
				'sourceBlockId' => max(0, (int)($item['sourceBlockId'] ?? 0)),
			];

			if (array_key_exists('requestedPlacement', $item) || array_key_exists('placement', $item))
			{
				$normalized[$actionId]['requestedPlacement'] = self::normalizePlacement(
					$item['requestedPlacement'] ?? $item['placement'],
				);
			}

			if (array_key_exists('resolvedPlacement', $item))
			{
				$normalized[$actionId]['resolvedPlacement'] = self::normalizePlacement($item['resolvedPlacement']);
			}

			if (array_key_exists('fromIndex', $item))
			{
				$normalized[$actionId]['fromIndex'] = max(0, (int)$item['fromIndex']);
			}
			if (array_key_exists('toIndex', $item))
			{
				$normalized[$actionId]['toIndex'] = max(0, (int)$item['toIndex']);
			}

			$skipReason = self::normalizeText((string)($item['skipReason'] ?? ''));
			if ($skipReason !== '')
			{
				$normalized[$actionId]['skipReason'] = $skipReason;
			}

			$artifacts = self::normalizeAppliedOperationArtifacts($type, $item['artifacts'] ?? null);
			if ($artifacts !== [])
			{
				$normalized[$actionId]['artifacts'] = $artifacts;
			}
		}

		usort($normalized, static function(array $left, array $right): int {
			$sequenceCompare = ($left['seq'] ?? 0) <=> ($right['seq'] ?? 0);

			return $sequenceCompare !== 0
				? $sequenceCompare
				: strcmp((string)($left['actionId'] ?? ''), (string)($right['actionId'] ?? ''))
			;
		});

		return array_values($normalized);
	}

	private static function normalizeAppliedOperationArtifacts(string $type, mixed $artifacts): array
	{
		if (!is_array($artifacts))
		{
			return [];
		}

		if ($type === 'update_block')
		{
			$normalized = [];
			if (array_key_exists('contentBefore', $artifacts))
			{
				$normalized['contentBefore'] = (string)$artifacts['contentBefore'];
			}
			if (array_key_exists('contentAfter', $artifacts))
			{
				$normalized['contentAfter'] = (string)$artifacts['contentAfter'];
			}

			return $normalized;
		}

		if ($type === 'move_block')
		{
			$normalized = [];
			if (array_key_exists('orderBefore', $artifacts))
			{
				$normalized['orderBefore'] = self::normalizeIds($artifacts['orderBefore']);
			}
			if (array_key_exists('orderAfter', $artifacts))
			{
				$normalized['orderAfter'] = self::normalizeIds($artifacts['orderAfter']);
			}

			return $normalized;
		}

		return [];
	}

	private static function normalizeAppliedStatus(mixed $status): string
	{
		$status = mb_strtolower(self::normalizeText((string)$status));

		return in_array($status, [self::APPLIED_STATUS_APPLIED, self::APPLIED_STATUS_SKIPPED], true)
			? $status
			: self::APPLIED_STATUS_SKIPPED
		;
	}

	private static function projectAppliedOperations(array $operations, bool $onlyApplied = false): array
	{
		$projected = [];
		foreach (self::normalizeAppliedOperations($operations) as $operation)
		{
			if ($onlyApplied && ($operation['status'] ?? '') !== self::APPLIED_STATUS_APPLIED)
			{
				continue;
			}

			unset($operation['artifacts']);
			$projected[] = $operation;
		}

		return $projected;
	}

	private static function normalizeCandidate(mixed $candidate): ?array
	{
		if (!is_array($candidate))
		{
			return null;
		}

		$id = (int)($candidate['id'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}

		$normalized = [
			'id' => $id,
			'code' => trim((string)($candidate['code'] ?? '')),
			'anchor' => trim((string)($candidate['anchor'] ?? '')),
			'summary' => trim((string)($candidate['summary'] ?? '')),
		];
		foreach (['rootClasses', 'styleSummary', 'sectionTypeGuess', 'imagePattern'] as $field)
		{
			if (array_key_exists($field, $candidate))
			{
				$normalized[$field] = self::normalizeText((string)$candidate[$field]);
			}
		}
		if (array_key_exists('imageCount', $candidate))
		{
			$normalized['imageCount'] = max(0, (int)$candidate['imageCount']);
		}
		if (array_key_exists('hasCrm', $candidate))
		{
			$normalized['hasCrm'] = !empty($candidate['hasCrm']);
		}
		foreach (['dominantFonts', 'dominantColors'] as $field)
		{
			if (array_key_exists($field, $candidate))
			{
				$normalized[$field] = self::normalizeTextList($candidate[$field] ?? null, 6);
			}
		}
		if (array_key_exists('styleTokens', $candidate))
		{
			$styleTokens = self::normalizeStyleTokenGroups($candidate['styleTokens'] ?? null);
			if ($styleTokens !== [])
			{
				$normalized['styleTokens'] = $styleTokens;
			}
		}
		if (array_key_exists('canUpdate', $candidate))
		{
			$normalized['canUpdate'] = !empty($candidate['canUpdate']);
		}
			if (array_key_exists('canDelete', $candidate))
			{
				$normalized['canDelete'] = !empty($candidate['canDelete']);
			}
			if (array_key_exists('hardDeny', $candidate))
			{
				$normalized['hardDeny'] = !empty($candidate['hardDeny']);
			}
			if (array_key_exists('policyReason', $candidate))
			{
				$normalized['policyReason'] = self::normalizeText((string)$candidate['policyReason']);
			}

			return $normalized;
		}

	private static function normalizeActions(array $actions): array
	{
		$normalized = [];
		foreach ($actions as $index => $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = self::normalizeActionType($action['type'] ?? $action['actionType'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$actionId = self::normalizeText((string)($action['actionId'] ?? ''));
			if ($actionId === '')
			{
				$actionId = $type . '_' . ((int)$index + 1);
			}

			$normalized[$actionId] = [
				'actionId' => $actionId,
				'type' => $type,
				'blockId' => max(0, (int)($action['blockId'] ?? 0)),
				'instruction' => self::normalizeText((string)($action['instruction'] ?? '')),
				'editMode' => self::normalizeEditMode($action['editMode'] ?? $action['mode'] ?? ''),
				'placement' => self::normalizePlacement($action['placement'] ?? [
					'mode' => $action['placementMode'] ?? '',
					'blockId' => $action['placementBlockId'] ?? 0,
				]),
				'status' => self::normalizeText((string)($action['status'] ?? 'pending')) ?: 'pending',
			];
		}

		return array_values($normalized);
	}

	private static function normalizeActionType(mixed $type): string
	{
		$type = mb_strtolower(self::normalizeText((string)$type));

		return match ($type)
		{
			'update', 'update_block', 'change', 'change_block' => 'update_block',
			'add', 'add_block', 'create', 'create_block' => 'add_block',
			'delete', 'delete_block', 'remove', 'remove_block' => 'delete_block',
			'move', 'move_block', 'reorder', 'reorder_block', 'place_block', 'sort_block' => 'move_block',
			default => '',
		};
	}

	private static function normalizePlacement(mixed $placement): array
	{
		$placement = is_array($placement) ? $placement : [];
		$mode = mb_strtolower(self::normalizeText((string)($placement['mode'] ?? '')));
		if (!in_array($mode, ['after', 'before', 'append', 'prepend'], true))
		{
			$mode = 'append';
		}

		$blockId = max(0, (int)($placement['blockId'] ?? 0));
		if (in_array($mode, ['append', 'prepend'], true))
		{
			$blockId = 0;
		}

		return [
			'mode' => $mode,
			'blockId' => $blockId,
		];
	}

	private static function normalizeCandidates(array $candidates): array
	{
		$normalized = array_filter(
			array_map([self::class, 'normalizeCandidate'], $candidates),
			static fn(?array $candidate): bool => $candidate !== null,
		);

		return array_values($normalized);
	}

	private static function normalizeSelectionDebug(array $data): array
	{
		return [
			'mode' => trim((string)($data['mode'] ?? '')),
			'reason' => trim((string)($data['reason'] ?? '')),
		];
	}

	private static function normalizeText(string $value): string
	{
		$value = preg_replace('/\s+/u', ' ', $value) ?: $value;

		return trim($value);
	}

	private static function normalizeBlockInstructions(array $data): array
	{
		$items = is_array($data['items'] ?? null) ? $data['items'] : [];
		$normalizedItems = [];
		foreach ($items as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$blockId = (int)($item['blockId'] ?? 0);
			$actionId = self::normalizeText((string)($item['actionId'] ?? ''));
			$instruction = self::normalizeText((string)($item['instruction'] ?? ''));
			if (($blockId <= 0 && $actionId === '') || $instruction === '')
			{
				continue;
			}

			$key = $actionId !== '' ? $actionId : (string)$blockId;
			$normalizedItems[$key] = [
				'blockId' => $blockId,
				'instruction' => $instruction,
				'editMode' => self::normalizeEditMode($item['editMode'] ?? ''),
			];
			if ($actionId !== '')
			{
				$normalizedItems[$key]['actionId'] = $actionId;
			}
		}

		return [
			'items' => array_values($normalizedItems),
			'globalConstraints' => self::normalizeText((string)($data['globalConstraints'] ?? '')),
			'imageStyleBrief' => self::normalizeText((string)($data['imageStyleBrief'] ?? '')),
			'designBrief' => self::normalizeText((string)($data['designBrief'] ?? '')),
		];
	}

	private static function normalizeStyleContext(array $data): array
	{
		$context = [];
		foreach (self::STYLE_CONTEXT_GROUPS as $group)
		{
			$items = is_array($data[$group] ?? null) ? $data[$group] : [];
			$normalizedItems = [];
			foreach ($items as $item)
			{
				if (!is_string($item))
				{
					continue;
				}

				$item = self::normalizeText($item);
				if ($item !== '')
				{
					$normalizedItems[] = $item;
				}
			}

			$normalizedItems = array_values(array_unique($normalizedItems));
			if ($normalizedItems !== [])
			{
				$context[$group] = array_slice($normalizedItems, 0, self::STYLE_CONTEXT_GROUP_LIMIT);
			}
		}

		$designProfile = self::normalizeDesignProfile($data['design_profile'] ?? null);
		if ($designProfile !== [])
		{
			$context['design_profile'] = $designProfile;
		}

		return $context;
	}

	public static function normalizeEditMode(mixed $mode): string
	{
		$mode = mb_strtolower(self::normalizeText((string)$mode));

		return in_array($mode, [
			self::EDIT_MODE_TARGETED,
			self::EDIT_MODE_BALANCED,
			self::EDIT_MODE_GLOBAL,
		], true)
			? $mode
			: self::EDIT_MODE_BALANCED
		;
	}

	private static function normalizeTextList(mixed $value, int $limit): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$items = [];
		foreach ($value as $item)
		{
			if (!is_string($item))
			{
				continue;
			}

			$item = self::normalizeText($item);
			if ($item !== '')
			{
				$items[] = $item;
			}
		}

		return array_slice(array_values(array_unique($items)), 0, max(1, $limit));
	}

	private static function normalizeStyleTokenGroups(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$groups = [];
		foreach (self::STYLE_CONTEXT_GROUPS as $group)
		{
			$items = self::normalizeTextList($value[$group] ?? null, 6);
			if ($items !== [])
			{
				$groups[$group] = $items;
			}
		}

		return $groups;
	}

	private static function normalizeDesignProfile(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$profile = [];
		foreach (['fonts', 'cta'] as $field)
		{
			$items = self::normalizeTextList($value[$field] ?? null, self::STYLE_CONTEXT_PROFILE_GROUP_LIMIT);
			if ($items !== [])
			{
				$profile[$field] = $items;
			}
		}

		foreach (['palette', 'surfaces', 'layout', 'typography'] as $field)
		{
			$groups = [];
			$source = is_array($value[$field] ?? null) ? $value[$field] : [];
			foreach ($source as $group => $items)
			{
				$normalizedItems = self::normalizeTextList($items, self::STYLE_CONTEXT_PROFILE_GROUP_LIMIT);
				if ($normalizedItems !== [])
				{
					$groups[self::normalizeText((string)$group)] = $normalizedItems;
				}
			}
			if ($groups !== [])
			{
				$profile[$field] = $groups;
			}
		}

		foreach (['density', 'tone'] as $field)
		{
			$text = self::normalizeText((string)($value[$field] ?? ''));
			if ($text !== '')
			{
				$profile[$field] = $text;
			}
		}

		return $profile;
	}

	private static function normalizeSyncedFonts(array $fonts): array
	{
		$normalized = [];
		foreach ($fonts as $font)
		{
			if (!is_array($font))
			{
				continue;
			}

			$class = mb_strtolower(self::normalizeText((string)($font['class'] ?? '')));
			if ($class === '' || !str_starts_with($class, 'g-font-'))
			{
				continue;
			}

			$name = self::normalizeText((string)($font['name'] ?? ''));
			if ($name === '')
			{
				continue;
			}

			$generic = mb_strtolower(self::normalizeText((string)($font['generic'] ?? 'sans-serif')));
			if (!in_array($generic, ['serif', 'sans-serif', 'monospace', 'cursive'], true))
			{
				$generic = 'sans-serif';
			}

			$normalized[$class] = [
				'class' => $class,
				'name' => $name,
				'generic' => $generic,
			];
		}

		return array_values($normalized);
	}

}
