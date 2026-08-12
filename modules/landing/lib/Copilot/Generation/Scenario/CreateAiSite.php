<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Step;
use Bitrix\Landing\Metrika;

class CreateAiSite extends BaseScenario implements IStepMetaProvider
{
	private const STEP_INIT_CONTEXT = 10;
	private const STEP_MODERATE_TOPIC = 15;
	private const STEP_ENHANCED_INPUT = 20;
	private const STEP_PREPARE_DATA = 30;
	private const STEP_PREVIEW_IMAGE = 40;
	private const STEP_STRUCTURE = 50;
	private const STEP_BLOCK_HTML = 60;
	private const STEP_IMPROVE_BLOCK_HTML = 70;
	private const STEP_RESOLVE_DOMAIN = 75;
	private const STEP_CREATE_SITE = 80;
	private const STEP_INIT_TAILWIND = 90;
	private const STEP_PREPARE_MARKUP = 100;
	private const STEP_PREPARE_CRM_FORMS = 110;
	private const STEP_CREATE_BLOCKS = 120;
	private const STEP_SYNC_FONTS_HOOK = 130;
	private const STEP_PREPARE_CREATED_MARKUP = 140;
	private const STEP_GENERATE_IMAGES = 150;
	private const STEP_PUBLISH_SITE = 160;
	private const STEP_FINISH = 1000;

	private const DOWNSTREAM_STEP_IDS = [
		self::STEP_RESOLVE_DOMAIN,
		self::STEP_CREATE_SITE,
		self::STEP_INIT_TAILWIND,
		self::STEP_PREPARE_MARKUP,
		self::STEP_PREPARE_CRM_FORMS,
		self::STEP_CREATE_BLOCKS,
		self::STEP_SYNC_FONTS_HOOK,
		self::STEP_PREPARE_CREATED_MARKUP,
		self::STEP_GENERATE_IMAGES,
		self::STEP_PUBLISH_SITE,
		self::STEP_FINISH,
	];
	private ?array $asyncRelationsCache = null;

	protected function buildMap(): array
	{
		return [
			self::STEP_INIT_CONTEXT => new Step\TaskInitAiSiteContext(),
			self::STEP_MODERATE_TOPIC => new Step\RequestModerateAiSiteTopic(),
			self::STEP_ENHANCED_INPUT => new Step\RequestEnhancedInput(),
			self::STEP_PREPARE_DATA => new Step\TaskPrepareAiSiteData(),
			self::STEP_PREVIEW_IMAGE => new Step\RequestPreviewImage(),
			self::STEP_STRUCTURE => new Step\RequestTailwindStructure(),
			self::STEP_BLOCK_HTML => new Step\RequestTailwindBlockHtml(),
			self::STEP_IMPROVE_BLOCK_HTML => new Step\RequestImproveTailwindBlockHtml(),
			self::STEP_RESOLVE_DOMAIN => new Step\TaskResolveAiSiteDomain(),
			self::STEP_CREATE_SITE => new Step\TaskCreateSite(),
			self::STEP_INIT_TAILWIND => new Step\TaskInitTailwindCssHook(),
			self::STEP_PREPARE_MARKUP => new Step\TaskPrepareHtmlBlocksMarkup(),
			self::STEP_PREPARE_CRM_FORMS => new Step\TaskPrepareHtmlBlocksCrmForms(),
			self::STEP_CREATE_BLOCKS => new Step\TaskCreateHtmlBlocks(),
			self::STEP_SYNC_FONTS_HOOK => new Step\TaskSyncAiSiteFontsHook(),
			self::STEP_PREPARE_CREATED_MARKUP => new Step\TaskPrepareCreatedHtmlBlocksMarkup(),
			self::STEP_GENERATE_IMAGES => new Step\RequestHtmlBlockImages(),
			self::STEP_PUBLISH_SITE => new Step\TaskPublishAiSite(),
			self::STEP_FINISH => new Step\Finish(),
		];
	}

	public function getStepMeta(): array
	{
		return [
			self::STEP_INIT_CONTEXT => [
				'code' => 'init',
				'label' => 'Prepare context',
			],
			self::STEP_MODERATE_TOPIC => [
				'code' => 'moderation',
				'label' => 'Moderate topic',
			],
			self::STEP_ENHANCED_INPUT => [
				'code' => 'enhance',
				'label' => 'Enhance user input',
			],
			self::STEP_PREPARE_DATA => [
				'code' => 'site_data',
				'label' => 'Prepare site data',
			],
			self::STEP_PREVIEW_IMAGE => [
				'code' => 'preview_image',
				'label' => 'Generate preview image',
			],
			self::STEP_STRUCTURE => [
				'code' => 'structure',
				'label' => 'Generate Tailwind structure',
			],
			self::STEP_BLOCK_HTML => [
				'code' => 'html',
				'label' => 'Generate HTML blocks',
			],
			self::STEP_IMPROVE_BLOCK_HTML => [
				'code' => 'improve_html',
				'label' => 'Improve HTML blocks',
			],
			self::STEP_RESOLVE_DOMAIN => [
				'code' => 'resolve_domain',
				'label' => 'Resolve site domain',
			],
			self::STEP_CREATE_SITE => [
				'code' => 'create_site',
				'label' => 'Create site and landing',
			],
			self::STEP_INIT_TAILWIND => [
				'code' => 'tailwind_assets',
				'label' => 'Initialize Tailwind runtime',
			],
			self::STEP_PREPARE_MARKUP => [
				'code' => 'prepare_markup',
				'label' => 'Prepare block markup',
			],
			self::STEP_PREPARE_CRM_FORMS => [
				'code' => 'prepare_crm_forms',
				'label' => 'Prepare CRM forms',
			],
			self::STEP_CREATE_BLOCKS => [
				'code' => 'create_blocks',
				'label' => 'Add blocks to landing',
			],
			self::STEP_SYNC_FONTS_HOOK => [
				'code' => 'sync_fonts',
				'label' => 'Sync fonts',
			],
			self::STEP_PREPARE_CREATED_MARKUP => [
				'code' => 'prepare_created_markup',
				'label' => 'Prepare created block markup',
			],
			self::STEP_GENERATE_IMAGES => [
				'code' => 'images',
				'label' => 'Generate images',
			],
			self::STEP_PUBLISH_SITE => [
				'code' => 'publish_site',
				'label' => 'Publish site',
			],
			self::STEP_FINISH => [
				'code' => 'finish',
				'label' => 'Finish',
			],
		];
	}

	public function getQuotaCalculateStep(): ?int
	{
		return self::STEP_BLOCK_HTML;
	}

	public function getAnalyticCategory(): Metrika\Categories
	{
		return Metrika\Categories::SiteGeneration;
	}

	public function getAsyncRelations(): ?array
	{
		if ($this->asyncRelationsCache !== null)
		{
			return $this->asyncRelationsCache;
		}

		$map = $this->getMap();
		$downstream = $this->getExistingStepIds($map, self::DOWNSTREAM_STEP_IDS);
		$relations = [
			self::STEP_STRUCTURE => $downstream,
		];
		$hasStep60 = array_key_exists(self::STEP_BLOCK_HTML, $map);
		$hasStep70 = array_key_exists(self::STEP_IMPROVE_BLOCK_HTML, $map);

		if ($hasStep70)
		{
			$relations[self::STEP_STRUCTURE] = $this->getExistingStepIds(
				$map,
				[self::STEP_BLOCK_HTML, self::STEP_IMPROVE_BLOCK_HTML, ...$downstream],
			);
			$relations[self::STEP_BLOCK_HTML] =
				$this->getExistingStepIds($map, [self::STEP_IMPROVE_BLOCK_HTML, ...$downstream]);
			$relations[self::STEP_IMPROVE_BLOCK_HTML] = $downstream;
		}
		elseif ($hasStep60)
		{
			$relations[self::STEP_STRUCTURE] = $this->getExistingStepIds($map, [self::STEP_BLOCK_HTML, ...$downstream]);
			$relations[self::STEP_BLOCK_HTML] = $downstream;
		}

		if (array_key_exists(self::STEP_GENERATE_IMAGES, $map))
		{
			$relations[self::STEP_GENERATE_IMAGES] = $this->getExistingStepIds($map, [
				self::STEP_PUBLISH_SITE,
				self::STEP_FINISH,
			]);
		}

		$this->asyncRelationsCache = $relations;

		return $this->asyncRelationsCache;
	}

	/**
	 * @param array<int, Step\Base\IStep> $map
	 * @param list<int> $stepIds
	 * @return list<int>
	 */
	private function getExistingStepIds(array $map, array $stepIds): array
	{
		return array_values(array_filter(
			$stepIds,
			static fn(int $stepId): bool => array_key_exists($stepId, $map),
		));
	}

	public function onFinish(Generation $generation): void
	{
	}
}
