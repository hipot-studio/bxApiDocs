<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Step;

class ChangeAiSiteSelectedElement extends ChangeAiSite implements IStepMetaProvider
{
	private const STEP_INIT_CONTEXT = 10;
	private const STEP_COLLECT_STYLE_CONTEXT = 20;
	private const STEP_RESOLVE_SELECTED_ELEMENT = 30;
	private const STEP_INIT_TARGET_BLOCKS = 40;
	private const STEP_GENERATE_SELECTED_ELEMENT_HTML = 50;
	private const STEP_PREPARE_MARKUP = 70;
	private const STEP_PREPARE_CRM_FORMS = 80;
	private const STEP_PRESAVE_HISTORY = 90;
	private const STEP_INIT_TAILWIND_RUNTIME = 100;
	private const STEP_REPLACE_BLOCK_HTML = 110;
	private const STEP_SYNC_FONTS_HOOK = 120;
	private const STEP_GENERATE_IMAGES = 140;
	private const STEP_SAVE_HISTORY = 150;
	private const STEP_FINISH = 1000;

	protected function buildMap(): array
	{
		return [
			self::STEP_INIT_CONTEXT => new Step\TaskInitChangeAiSiteContext(),
			self::STEP_COLLECT_STYLE_CONTEXT => new Step\TaskCollectChangeAiSiteStyleContext(),
			self::STEP_RESOLVE_SELECTED_ELEMENT => new Step\TaskResolveChangeAiSiteSelectedElement(),
			self::STEP_INIT_TARGET_BLOCKS => new Step\TaskInitChangeAiSiteTargetBlocks(),
			self::STEP_GENERATE_SELECTED_ELEMENT_HTML => new Step\RequestChangeAiSiteSelectedElementBlockHtml(),
			self::STEP_PREPARE_MARKUP => new Step\TaskPrepareChangeAiSiteBlocksMarkup(),
			self::STEP_PREPARE_CRM_FORMS => new Step\TaskPrepareChangeAiSiteBlocksCrmForms(),
			self::STEP_PRESAVE_HISTORY => new Step\TaskPresaveChangeAiSiteHistory(),
			self::STEP_INIT_TAILWIND_RUNTIME => new Step\TaskInitChangeAiSiteTailwindCssHook(),
			self::STEP_REPLACE_BLOCK_HTML => new Step\TaskReplaceChangeAiSiteBlocksHtml(),
			self::STEP_SYNC_FONTS_HOOK => new Step\TaskSyncAiSiteFontsHook(),
			self::STEP_GENERATE_IMAGES => new Step\RequestChangeAiSiteImages(),
			self::STEP_SAVE_HISTORY => new Step\TaskSaveChangeAiSiteHistory(),
			self::STEP_FINISH => new Step\Finish(),
		];
	}

	public function getStepMeta(): array
	{
		return [
			self::STEP_INIT_CONTEXT => [
				'code' => 'init_context',
				'label' => 'Prepare selected-element change context',
			],
			self::STEP_COLLECT_STYLE_CONTEXT => [
				'code' => 'style_context',
				'label' => 'Collect page style context',
			],
			self::STEP_RESOLVE_SELECTED_ELEMENT => [
				'code' => 'resolve_selected_element',
				'label' => 'Resolve selected element target',
			],
			self::STEP_INIT_TARGET_BLOCKS => [
				'code' => 'init_target_blocks',
				'label' => 'Prepare target block',
			],
			self::STEP_GENERATE_SELECTED_ELEMENT_HTML => [
				'code' => 'selected_element_html',
				'label' => 'Generate HTML for selected element',
			],
			self::STEP_PREPARE_MARKUP => [
				'code' => 'prepare_markup',
				'label' => 'Prepare block HTML markup',
			],
			self::STEP_PREPARE_CRM_FORMS => [
				'code' => 'prepare_crm_forms',
				'label' => 'Prepare CRM forms',
			],
			self::STEP_PRESAVE_HISTORY => [
				'code' => 'presave_history',
				'label' => 'Save original block history',
			],
			self::STEP_INIT_TAILWIND_RUNTIME => [
				'code' => 'tailwind_assets',
				'label' => 'Initialize Tailwind runtime',
			],
			self::STEP_REPLACE_BLOCK_HTML => [
				'code' => 'replace_blocks',
				'label' => 'Apply HTML changes to target block',
			],
			self::STEP_SYNC_FONTS_HOOK => [
				'code' => 'sync_fonts',
				'label' => 'Sync fonts',
			],
			self::STEP_GENERATE_IMAGES => [
				'code' => 'images',
				'label' => 'Generate images',
			],
			self::STEP_SAVE_HISTORY => [
				'code' => 'save_history',
				'label' => 'Save changed block history',
			],
			self::STEP_FINISH => [
				'code' => 'finish',
				'label' => 'Finish',
			],
		];
	}

	public function getQuotaCalculateStep(): ?int
	{
		return self::STEP_GENERATE_SELECTED_ELEMENT_HTML;
	}

	public function onFinish(Generation $generation): void
	{
		parent::onFinish($generation);
	}
}
