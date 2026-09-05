<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteDomHelper;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Landing;

class TaskResolveChangeAiSiteSelectedElement extends TaskStep
{
	private const DEBUG_MODE_ERROR = 'error';
	private const DEBUG_REASON_NO_CONTEXT = 'no_context';
	private const DEBUG_REASON_BLOCK_NOT_FOUND = 'block_not_found';
	private const DEBUG_REASON_SELECTOR_MISS = 'selector_miss';
	private const ACTION_UPDATE = 'update_block';

	public function execute(): bool
	{
		parent::execute();

		$context = ChangeAiSiteState::getSelectedElementContext($this->generation);
		if ($context === [])
		{
			$this->failSelection(
				self::DEBUG_REASON_NO_CONTEXT,
				'ChangeAiSite selected element context is empty.',
			);
		}

		$blockId = (int)($context['blockId'] ?? 0);
		$blockHtml = $this->loadBlockHtml($this->resolveRequiredLandingId(), $blockId);
		if (trim($blockHtml) === '')
		{
			$this->failSelection(
				self::DEBUG_REASON_BLOCK_NOT_FOUND,
				'ChangeAiSite selected element block was not found in edit mode.',
			);
		}

		$resolveResult = ChangeAiSiteDomHelper::resolveSelectedElement(
			$blockHtml,
			(string)($context['selector'] ?? ''),
		);
		if (!$resolveResult->isSuccess())
		{
			$error = $resolveResult->getErrors()[0];
			if ($error->getCode() === ChangeAiSiteDomHelper::RESOLVE_ERROR_BROKEN_HTML)
			{
				$this->failSelection(
					self::DEBUG_REASON_BLOCK_NOT_FOUND,
					'ChangeAiSite selected element block HTML could not be parsed.',
				);
			}

			$this->failSelection(
				self::DEBUG_REASON_SELECTOR_MISS,
				'ChangeAiSite selected element selector did not match exactly one element in the block.',
				'match_count_' . (int)($error->getCustomData()['matchCount'] ?? 0),
			);
		}

		$resolved = $resolveResult->getData();
		$fingerprint = (string)($context['fingerprint'] ?? '');
		if ($fingerprint !== '' && $fingerprint !== (string)$resolved['fingerprint'])
		{
			$this->failSelection(
				self::DEBUG_REASON_SELECTOR_MISS,
				'ChangeAiSite selected element fingerprint did not match the resolved element.',
				'fingerprint_mismatch',
			);
		}

		$context['elementHtml'] = (string)$resolved['outerHtml'];
		ChangeAiSiteState::setSelectedElementContext($this->generation, $context);
		$this->saveSelectedElementActionPlan($blockId);
		$this->changed = true;

		return true;
	}

	protected function loadBlockHtml(int $landingId, int $blockId): string
	{
		if ($landingId <= 0 || $blockId <= 0)
		{
			return '';
		}

		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = Landing::createInstance($landingId, [
				'check_permissions' => false,
			]);
			if (!$landing->exist())
			{
				return '';
			}

			$block = $landing->getBlockById($blockId);
			if (!$block)
			{
				$editableBlockId = $this->resolveEditableBlockId($landingId, $blockId);
				$block = $editableBlockId > 0 ? $landing->getBlockById($editableBlockId) : null;
			}

			return $block ? (string)$block->getContent() : '';
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}
	}

	private function resolveEditableBlockId(int $landingId, int $blockId): int
	{
		$row = BlockTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=LID' => $landingId,
				'=DELETED' => 'N',
				'=PUBLIC' => 'N',
				[
					'LOGIC' => 'OR',
					['=ID' => $blockId],
					['=PARENT_ID' => $blockId],
				],
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (int)($row['ID'] ?? 0) : 0;
	}

	private function saveSelectedElementActionPlan(int $blockId): void
	{
		$instruction = ChangeAiSiteState::resolveRawInput($this->generation);
		$actionId = 'update_' . $blockId;

		ChangeAiSiteState::setActions($this->generation, [
			[
				'actionId' => $actionId,
				'type' => self::ACTION_UPDATE,
				'blockId' => $blockId,
				'instruction' => $instruction,
				'editMode' => ChangeAiSiteState::EDIT_MODE_TARGETED,
				'placement' => [
					'mode' => 'append',
					'blockId' => 0,
				],
			],
		]);
		ChangeAiSiteState::setBlockInstructions($this->generation, [
			'items' => [
				[
					'actionId' => $actionId,
					'blockId' => $blockId,
					'instruction' => $instruction,
					'editMode' => ChangeAiSiteState::EDIT_MODE_TARGETED,
				],
			],
			'globalConstraints' => '',
			'imageStyleBrief' => '',
			'designBrief' => '',
		]);
	}

	private function resolveRequiredLandingId(): int
	{
		$landingId = ChangeAiSiteState::resolveLandingId($this->generation);
		if ($landingId > 0)
		{
			return $landingId;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite landingId is empty.',
		);
	}

	private function failSelection(string $reason, string $message, string $detail = ''): void
	{
		ChangeAiSiteState::setSelectionDebug($this->generation, [
			'mode' => self::DEBUG_MODE_ERROR,
			'reason' => $reason,
			'detail' => $detail,
		]);

		throw new GenerationException(GenerationErrors::dataValidation, $message);
	}
}
