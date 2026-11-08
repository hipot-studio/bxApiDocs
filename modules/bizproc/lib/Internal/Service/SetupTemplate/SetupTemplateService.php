<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\SetupTemplate;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemType;
use Bitrix\Bizproc\Internal\Event\SetupTemplateUserInputEvent;
use Bitrix\Bizproc\Internal\Event\SetupTemplateValidationEvent;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectFieldStateResolver;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;

class SetupTemplateService
{
	private const SETUP_TEMPLATE_ACTIVITY_TYPE = 'SetupTemplateActivity';
	public const SETUP_CONSTANT_SOURCE = 'SetupTemplateActivity';
	private const SETUP_CONSTANT_SOURCE_KEY = 'Source';

	private ?ErrorCollection $errors = null;
	private bool $eventReceived = false;
	private ?int $eventHandler = null;
	private ProjectFieldStateResolver $projectFieldStateResolver;

	public function __construct(?ProjectFieldStateResolver $projectFieldStateResolver = null)
	{
		$this->projectFieldStateResolver = $projectFieldStateResolver ?? new ProjectFieldStateResolver();
	}

	/**
	 * @todo Pass parameters by DTO when there will be more of them
	 *
	 * @param int $userId User identifier
	 * @param string $instanceId Identifier of workflow instance
	 * @param int $templateId Identifier of workflow template
	 * @param array<string, string> $constantValues [constantId => constantValue, ...]
	 * @param bool $skipAccessValidation Skip UI-level access checks on constant values (use only for trusted scenario flows).
	 * @param bool $applyDefaults Fill setup-block constants that were not passed in $constantValues with their configured defaults (use for scenario/auto-start flows).
	 *
	 * @return Result
	 */
	public function fill(
		int $userId,
		int $templateId,
		string $instanceId,
		array $constantValues = [],
		bool $skipAccessValidation = false,
		bool $applyDefaults = false,
	): Result
	{
		$this->listenForValidationEvent($userId, $templateId);
		$this->sendUserInputEvent($templateId, $userId, $instanceId, $constantValues, $skipAccessValidation, $applyDefaults);
		$this->stopListenValidationEvent();

		$result = new Result();
		if ($this->errors instanceof ErrorCollection)
		{
			$result->addErrors($this->errors->getValues());
		}
		if (!$this->eventReceived)
		{
			$result->addError(ErrorMessage::BP_NOT_FOUND->getError());
		}

		return $result;
	}

	/**
	 * Returns the setup-template blocks of a template narrowed down to the given constant codes,
	 * so the existing setup-template UI can render only a subset of constants (used by the
	 * AI-agent upgrade fill scenario to show only the new required constants of the reference).
	 *
	 * Reads the template's SetupTemplateActivity 'blocks' property and delegates filtering to
	 * CBPSetupTemplateActivity::filterBlocksByConstantCodes() (no block-parsing duplication).
	 * Returns an empty array when the template has no setup activity or nothing matches.
	 *
	 * @param int $templateId Template whose reference setup blocks are read.
	 * @param list<string> $constantCodes Constant codes to keep.
	 */
	public function getRequiredConstantBlocks(int $templateId, array $constantCodes): array
	{
		if ($templateId <= 0 || $constantCodes === [])
		{
			return [];
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['TEMPLATE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		$template = is_array($row['TEMPLATE'] ?? null) ? $row['TEMPLATE'] : null;
		if ($template === null)
		{
			return [];
		}

		$rawBlocks = $this->findSetupActivityBlocks($template);
		if ($rawBlocks === null)
		{
			return [];
		}

		if (!\CBPRuntime::getRuntime()->includeActivityFile('SetupTemplateActivity'))
		{
			return [];
		}

		return \CBPSetupTemplateActivity::filterBlocksByConstantCodes($rawBlocks, $constantCodes);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed> $constantValues
	 * @return array<int, array<string, mixed>>
	 */
	public function enrichProjectConstantBlocks(
		array $blocks,
		array $constantValues,
		int $userId,
	): array
	{
		if ($blocks === [] || $constantValues === [] || $userId <= 0)
		{
			return $blocks;
		}

		$projectConstantValues = $this->collectProjectConstantValues($blocks, $constantValues);
		$this->projectFieldStateResolver->preloadDirect($projectConstantValues, $userId);

		foreach ($blocks as &$block)
		{
			if (!is_array($block['items'] ?? null))
			{
				continue;
			}

			foreach ($block['items'] as &$item)
			{
				$constantId = $item['id'] ?? null;
				if (!$this->isProjectConstantWithValue($item, $constantValues))
				{
					continue;
				}

				$projectState = $this->projectFieldStateResolver->resolveDirect(
					$constantValues[$constantId],
					$userId,
				);
				if ($projectState === null)
				{
					continue;
				}

				$item['meta']['projectState'] = $projectState;
			}
			unset($item);
		}
		unset($block);

		return $blocks;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed> $constantValues
	 * @return list<mixed>
	 */
	private function collectProjectConstantValues(array $blocks, array $constantValues): array
	{
		$projectConstantValues = [];
		foreach ($blocks as $block)
		{
			foreach ((array)($block['items'] ?? []) as $item)
			{
				$constantId = $item['id'] ?? null;
				if ($this->isProjectConstantWithValue($item, $constantValues))
				{
					$projectConstantValues[] = $constantValues[$constantId];
				}
			}
		}

		return $projectConstantValues;
	}

	private function isProjectConstantWithValue(mixed $item, array $constantValues): bool
	{
		$constantId = is_array($item) ? ($item['id'] ?? null) : null;

		return is_array($item)
			&& ($item['itemType'] ?? null) === ItemType::Constant->value
			&& ($item['constantType'] ?? null) === ProjectType::getType()
			&& is_string($constantId)
			&& array_key_exists($constantId, $constantValues)
		;
	}

	/**
	 * Codes of submitted values that fail field-type validation against a template's setup blocks.
	 *
	 * Reads the template's SetupTemplateActivity blocks and its complex document type, then
	 * delegates the instance-free field-definition validation to CBPSetupTemplateActivity, so the
	 * AI-agent upgrade can reject invalid submitted values before it commits (never writing them,
	 * unlike the post-apply best-effort fill()). Empty values are not reported here - a missing
	 * required value is the caller's separate "still missing required" check. Returns an empty
	 * array when the template has no setup activity or all submitted values are valid.
	 *
	 * @param int $templateId Template whose setup blocks describe the editable constants.
	 * @param array<string, mixed> $constantValues Submitted values keyed by constant code.
	 * @return list<string> Invalid constant codes.
	 */
	public function collectInvalidConstantValues(int $templateId, array $constantValues): array
	{
		if ($templateId <= 0 || $constantValues === [])
		{
			return [];
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'TEMPLATE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		$template = is_array($row['TEMPLATE'] ?? null) ? $row['TEMPLATE'] : null;
		if ($template === null)
		{
			return [];
		}

		$rawBlocks = $this->findSetupActivityBlocks($template);
		if ($rawBlocks === null)
		{
			return [];
		}

		if (!\CBPRuntime::getRuntime()->includeActivityFile('SetupTemplateActivity'))
		{
			return [];
		}

		$documentType = [
			(string)($row['MODULE_ID'] ?? ''),
			(string)($row['ENTITY'] ?? ''),
			(string)($row['DOCUMENT_TYPE'] ?? ''),
		];

		return \CBPSetupTemplateActivity::collectInvalidConstantCodes($documentType, $rawBlocks, $constantValues);
	}

	/**
	 * Removes setup constants whose setup activity no longer exists in the template.
	 *
	 * @param array $template Workflow template tree.
	 * @param array<string, mixed> $constants Template constants keyed by constant code.
	 * @return array<string, mixed>
	 */
	public function normalizeConstantsByTemplate(array $template, array $constants): array
	{
		if ($template === [])
		{
			return $constants;
		}

		$activeSetupConstantIds = $this->getActiveSetupConstantIds($template);
		if ($activeSetupConstantIds === null)
		{
			return $constants;
		}

		$orphanConstantIds = $this->getOrphanSetupConstantIdsByActiveIds($activeSetupConstantIds, $constants);
		foreach ($orphanConstantIds as $constantId)
		{
			unset($constants[$constantId]);
		}

		foreach ($activeSetupConstantIds as $constantId)
		{
			if (
				isset($constants[$constantId])
				&& is_array($constants[$constantId])
				&& !$this->isSetupConstant($constants[$constantId])
			)
			{
				$constants[$constantId][self::SETUP_CONSTANT_SOURCE_KEY] = self::SETUP_CONSTANT_SOURCE;
			}
		}

		return $constants;
	}

	/**
	 * @param array $template Workflow template tree.
	 * @param array<string, mixed> $constants Template constants keyed by constant code.
	 * @return list<string>
	 */
	public function getOrphanSetupConstantIds(array $template, array $constants): array
	{
		$activeSetupConstantIds = $this->getActiveSetupConstantIds($template);
		if ($activeSetupConstantIds === null)
		{
			return [];
		}

		return $this->getOrphanSetupConstantIdsByActiveIds($activeSetupConstantIds, $constants);
	}

	/**
	 * @param list<string> $activeSetupConstantIds
	 * @param array<string, mixed> $constants Template constants keyed by constant code.
	 * @return list<string>
	 */
	private function getOrphanSetupConstantIdsByActiveIds(array $activeSetupConstantIds, array $constants): array
	{
		$confirmedSetupConstantIds = [];
		foreach ($constants as $constantId => $constant)
		{
			$constantId = (string)$constantId;
			if ($this->isSetupConstant($constant))
			{
				$confirmedSetupConstantIds[] = $constantId;
			}
		}

		return array_values(array_diff($confirmedSetupConstantIds, $activeSetupConstantIds));
	}

	private function isSetupConstant(mixed $constant): bool
	{
		return is_array($constant)
			&& ($constant[self::SETUP_CONSTANT_SOURCE_KEY] ?? null) === self::SETUP_CONSTANT_SOURCE
		;
	}

	/**
	 * Finds the 'blocks' property of the first SetupTemplateActivity in a template tree.
	 *
	 * @return string|array|null Raw blocks value, or null when there is no setup activity.
	 */
	private function findSetupActivityBlocks(mixed $node): string|array|null
	{
		if (!is_array($node))
		{
			return null;
		}

		if (
			($node['Type'] ?? null) === self::SETUP_TEMPLATE_ACTIVITY_TYPE
			&& isset($node['Properties']['blocks'])
		)
		{
			$blocks = $node['Properties']['blocks'];

			return (is_array($blocks) || is_string($blocks)) ? $blocks : null;
		}

		foreach ($node as $child)
		{
			$found = $this->findSetupActivityBlocks($child);
			if ($found !== null)
			{
				return $found;
			}
		}

		return null;
	}

	/**
	 * @return list<string>|null
	 */
	private function getActiveSetupConstantIds(array $template): ?array
	{
		if (!\CBPRuntime::getRuntime()->includeActivityFile(self::SETUP_TEMPLATE_ACTIVITY_TYPE))
		{
			return null;
		}

		$constantIds = [];
		$activityBlocks = $this->collectSetupActivityBlocks($template);
		if ($activityBlocks === null)
		{
			return null;
		}

		foreach ($activityBlocks as $rawBlocks)
		{
			$activityConstantIds = \CBPSetupTemplateActivity::collectConstantCodes($rawBlocks);
			if ($activityConstantIds === null)
			{
				return null;
			}

			foreach ($activityConstantIds as $constantId)
			{
				$constantIds[] = $constantId;
			}
		}

		return array_values(array_unique($constantIds));
	}

	/**
	 * @return list<string|array>|null
	 */
	private function collectSetupActivityBlocks(mixed $node): ?array
	{
		if (!is_array($node))
		{
			return [];
		}

		$blocks = [];
		if (
			($node['Type'] ?? null) === self::SETUP_TEMPLATE_ACTIVITY_TYPE
		)
		{
			$rawBlocks = $node['Properties']['blocks'] ?? null;
			if (!is_array($rawBlocks) && !is_string($rawBlocks))
			{
				return null;
			}

			$blocks[] = $rawBlocks;
		}

		foreach ($node as $child)
		{
			$childBlocks = $this->collectSetupActivityBlocks($child);
			if ($childBlocks === null)
			{
				return null;
			}

			foreach ($childBlocks as $childBlock)
			{
				$blocks[] = $childBlock;
			}
		}

		return $blocks;
	}

	private function listenForValidationEvent(int $userId, int $templateId): void
	{
		$this->resetProperties();
		$this->eventHandler = EventManager::getInstance()
			->addEventHandler(
				fromModuleId: SetupTemplateValidationEvent::MODULE_ID,
				eventType: SetupTemplateValidationEvent::EVENT_NAME,
				callback: function(SetupTemplateValidationEvent $event) use ($userId, $templateId)
				{
					if ($event->getTemplateId() === $templateId && $event->getUserId() === $userId)
					{
						$this->errors = $event->getErrors();
						$this->eventReceived = true;
					}
				}
			)
		;
	}

	private function sendUserInputEvent(
		int $templateId,
		int $userId,
		string $instanceId,
		array $constantValues,
		bool $skipAccessValidation,
		bool $applyDefaults,
	): void
	{
		(new SetupTemplateUserInputEvent(
			// parameter order is important
			parameters: [
				SetupTemplateUserInputEvent::PARAMETER_INSTANCE_ID => $instanceId,
				SetupTemplateUserInputEvent::PARAMETER_USER_ID => $userId,
				SetupTemplateUserInputEvent::PARAMETER_TEMPLATE_ID => $templateId,
				SetupTemplateUserInputEvent::PARAMETER_CONSTANT_VALUES => $constantValues,
				SetupTemplateUserInputEvent::PARAMETER_SKIP_ACCESS_VALIDATION => $skipAccessValidation,
				SetupTemplateUserInputEvent::PARAMETER_APPLY_DEFAULTS => $applyDefaults,
			]
		))
			->send()
		;
	}

	private function stopListenValidationEvent(): void
	{
		if (!$this->eventHandler)
		{
			return;
		}

		EventManager::getInstance()
			->removeEventHandler(
				fromModuleId: SetupTemplateValidationEvent::MODULE_ID,
				eventType: SetupTemplateValidationEvent::EVENT_NAME,
				iEventHandlerKey: $this->eventHandler,
			)
		;
	}

	private function resetProperties(): void
	{
		$this->errors = null;
		$this->eventReceived = false;
	}
}
