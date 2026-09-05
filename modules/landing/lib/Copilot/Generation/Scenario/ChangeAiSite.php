<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Step;
use Bitrix\Landing\Copilot\Services\GenerationErrorStatusMapper;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Site\Type;

class ChangeAiSite extends BaseScenario implements IStepMetaProvider
{
	protected const EVENT_FINISH = 'onChangeAiSiteFinish';
	private const STEP_INIT_CONTEXT = 10;
	private const STEP_COLLECT_STYLE_CONTEXT = 20;
	private const STEP_MODERATE_TOPIC = 25;
	private const STEP_SELECT_BLOCKS = 30;
	private const STEP_INIT_TARGET_BLOCKS = 40;
	private const STEP_GENERATE_BLOCK_HTML = 50;
	private const STEP_IMPROVE_BLOCK_HTML = 60;
	private const STEP_PREPARE_MARKUP = 70;
	private const STEP_PREPARE_CRM_FORMS = 80;
	private const STEP_PRESAVE_HISTORY = 90;
	private const STEP_INIT_TAILWIND_RUNTIME = 100;
	private const STEP_REPLACE_BLOCK_HTML = 110;
	private const STEP_SYNC_FONTS_HOOK = 120;
	private const STEP_GENERATE_IMAGES = 140;
	private const STEP_SAVE_HISTORY = 150;
	private const STEP_FINISH = 1000;

	/**
	 * Site type of the landing of this generation, read from the base once per instance.
	 */
	private ?string $siteType = null;

	/**
	 * Key of the generation data that keeps the fact of the sent edit event across the runs of the
	 * generation, so a resume of a generation that has already reported this edit sends nothing again.
	 */
	private const BLOCK_EDIT_ANALYTICS_SENT_KEY = 'block_edit_analytics_sent';

	/**
	 * The event of the edit has already been sent by this instance of the scenario.
	 */
	private bool $blockEditAnalyticsSent = false;

	protected function buildMap(): array
	{
		return [
			self::STEP_INIT_CONTEXT => new Step\TaskInitChangeAiSiteContext(),
			self::STEP_COLLECT_STYLE_CONTEXT => new Step\TaskCollectChangeAiSiteStyleContext(),
			self::STEP_MODERATE_TOPIC => new Step\RequestModerateChangeAiSiteTopic(),
			self::STEP_SELECT_BLOCKS => new Step\RequestSelectChangeAiSiteBlocks(),
			self::STEP_INIT_TARGET_BLOCKS => new Step\TaskInitChangeAiSiteTargetBlocks(),
			self::STEP_GENERATE_BLOCK_HTML => new Step\RequestChangeAiSiteBlockHtml(),
			self::STEP_IMPROVE_BLOCK_HTML => new Step\RequestImproveChangeAiSiteBlockHtml(),
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
				'label' => 'Prepare change context',
			],
			self::STEP_COLLECT_STYLE_CONTEXT => [
				'code' => 'style_context',
				'label' => 'Collect page style context',
			],
			self::STEP_MODERATE_TOPIC => [
				'code' => 'moderation',
				'label' => 'Moderate topic',
			],
			self::STEP_SELECT_BLOCKS => [
				'code' => 'select_blocks',
				'label' => 'Select blocks to change',
			],
			self::STEP_INIT_TARGET_BLOCKS => [
				'code' => 'init_target_blocks',
				'label' => 'Prepare target blocks',
			],
			self::STEP_GENERATE_BLOCK_HTML => [
				'code' => 'html',
				'label' => 'Generate HTML by blocks',
			],
			self::STEP_IMPROVE_BLOCK_HTML => [
				'code' => 'improve_html',
				'label' => 'Improve generated HTML by blocks',
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
				'label' => 'Replace HTML in target blocks',
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
		return self::STEP_GENERATE_BLOCK_HTML;
	}

	public function getAnalyticCategory(): Metrika\Categories
	{
		return Metrika\Categories::SiteGeneration;
	}

	public function isAnalyticStartEnabled(): bool
	{
		return false;
	}

	public function getAsyncRelations(): ?array
	{
		$map = $this->getMap();
		$relations = [];

		if (array_key_exists(self::STEP_GENERATE_BLOCK_HTML, $map))
		{
			$relations[self::STEP_GENERATE_BLOCK_HTML] = $this->getExistingStepIds(
				$map,
				[
					self::STEP_IMPROVE_BLOCK_HTML,
					self::STEP_PREPARE_MARKUP,
					self::STEP_PREPARE_CRM_FORMS,
					self::STEP_PRESAVE_HISTORY,
					self::STEP_INIT_TAILWIND_RUNTIME,
					self::STEP_REPLACE_BLOCK_HTML,
					self::STEP_SYNC_FONTS_HOOK,
					self::STEP_GENERATE_IMAGES,
					self::STEP_SAVE_HISTORY,
					self::STEP_FINISH,
				],
			);
		}

		if (array_key_exists(self::STEP_IMPROVE_BLOCK_HTML, $map))
		{
			$relations[self::STEP_IMPROVE_BLOCK_HTML] = $this->getExistingStepIds(
				$map,
				[
					self::STEP_PREPARE_MARKUP,
					self::STEP_PREPARE_CRM_FORMS,
					self::STEP_PRESAVE_HISTORY,
					self::STEP_INIT_TAILWIND_RUNTIME,
					self::STEP_REPLACE_BLOCK_HTML,
					self::STEP_SYNC_FONTS_HOOK,
					self::STEP_GENERATE_IMAGES,
					self::STEP_SAVE_HISTORY,
					self::STEP_FINISH,
				],
			);
		}

		if (array_key_exists(self::STEP_GENERATE_IMAGES, $map))
		{
			$relations[self::STEP_GENERATE_IMAGES] = $this->getExistingStepIds(
				$map,
				[
					self::STEP_SAVE_HISTORY,
					self::STEP_FINISH,
				],
			);
		}

		return $relations;
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
		// the analytics goes first and is guarded on its own: the pull event below is not, and a throw
		// from it would take the mark of a successful edit out of the funnel - and a GenerationException
		// thrown there would turn the successful edit into a mark of an error
		$this->sendBlockEditAnalytics($generation);
		$this->sendFinishEvent($generation);
	}

	/**
	 * @inheritdoc
	 */
	public function onGenerationError(Generation $generation, GenerationException $e): void
	{
		$this->sendBlockEditAnalytics(
			$generation,
			GenerationErrorStatusMapper::resolve($e),
			$e->getErrorCode()->name,
		);
	}

	/**
	 * One user edit makes at most one event, whatever amount of blocks the change has touched.
	 * The first sent outcome, successful or not, is remembered both in the instance and in the data of
	 * the generation, so neither a repeated execute() of the same run nor a resume of a generation that
	 * has already reported this edit - a new instance of the scenario, where the instance flag is empty -
	 * sends a second event. The finish before this run still guards a re-run of an already finished
	 * generation. The step that applies the generated HTML never sends the event.
	 */
	private function sendBlockEditAnalytics(
		Generation $generation,
		?Metrika\Statuses $status = null,
		?string $errorCode = null,
	): void
	{
		if ($this->isBlockEditAnalyticsSent($generation) || $this->isFinishedBeforeThisRun($generation))
		{
			return;
		}

		$this->markBlockEditAnalyticsSent($generation);

		try
		{
			(new Metrika\BlockEditMetrikaSender())->sendAiEdit(
				$this->resolveSiteType($generation),
				$status,
				$errorCode,
			);
		}
		catch (\Throwable)
		{
			// analytics must never break the outcome of the generation
		}
	}

	private function isBlockEditAnalyticsSent(Generation $generation): bool
	{
		return $this->blockEditAnalyticsSent
			|| $generation->getData(self::BLOCK_EDIT_ANALYTICS_SENT_KEY) === true;
	}

	/**
	 * Marks the edit event as sent both for this run and for the resumes of the generation. The
	 * persisted mark reaches the base with the next save of the generation - on the error path the
	 * generation is saved right after this hook, so a later resume of the errored generation sees it.
	 */
	private function markBlockEditAnalyticsSent(Generation $generation): void
	{
		$this->blockEditAnalyticsSent = true;
		$generation->setData(self::BLOCK_EDIT_ANALYTICS_SENT_KEY, true);
	}

	/**
	 * Tells a repeated run of an already finished generation from the run that has finished it.
	 * The mark of the finish is persisted by the generation after the hooks of this scenario are
	 * called, so on the run that really finishes the generation the mark loaded with the generation
	 * is still empty, and on every run after it - a public action of the controller, a job of the
	 * queue - it is filled.
	 */
	private function isFinishedBeforeThisRun(Generation $generation): bool
	{
		return $generation->isFinishedPersisted();
	}

	private function resolveSiteType(Generation $generation): string
	{
		if ($this->siteType !== null)
		{
			return $this->siteType;
		}

		$landingId = ChangeAiSiteState::resolveLandingId($generation);
		if ($landingId <= 0)
		{
			return Type::SCOPE_CODE_DEFAULT;
		}

		$landing = Landing::getList([
			'select' => [
				'SITE_TYPE' => 'SITE.TYPE',
			],
			'filter' => [
				'=ID' => $landingId,
				// the generation runs in the background, where the rights are not the ones of the author:
				// the type of the site is not a secret and only tells which tool the event belongs to
				'CHECK_PERMISSIONS' => 'N',
			],
		])->fetch();

		$this->siteType = (string)($landing['SITE_TYPE'] ?? Type::SCOPE_CODE_DEFAULT);

		return $this->siteType;
	}

	private function sendFinishEvent(Generation $generation): void
	{
		$landingId = ChangeAiSiteState::resolveLandingId($generation);
		if ($landingId <= 0)
		{
			return;
		}

		$actions = $this->buildFinishActionsPayload($generation);
		$fonts = ChangeAiSiteState::getSyncedFonts($generation);
		if ($actions === [] && $fonts === [])
		{
			return;
		}

		$params = [];
		if ($actions !== [])
		{
			$params['actions'] = $actions;
		}
		if ($fonts !== [])
		{
			$params['fonts'] = $fonts;
		}

		$generation->getEvent()
			->setLandingId($landingId)
			->send(
				self::EVENT_FINISH,
				$params,
			)
		;
	}

	private function buildFinishActionsPayload(Generation $generation): array
	{
		$appliedActions = ChangeAiSiteState::getPublicAppliedOperations($generation, true);
		if ($appliedActions !== [])
		{
			return $this->normalizeFinishAppliedActionsPayload($appliedActions);
		}

		$result = ChangeAiSiteState::getResult($generation);
		$updatedIds = array_fill_keys($this->normalizeIds($result['updatedIds'] ?? []), true);
		$createdIds = array_fill_keys($this->normalizeIds($result['createdIds'] ?? []), true);
		$deletedIds = array_fill_keys($this->normalizeIds($result['deletedIds'] ?? []), true);
		$movedIds = array_fill_keys($this->normalizeIds($result['movedIds'] ?? []), true);
		$moveResults = is_array($result['moveResults'] ?? null) ? $result['moveResults'] : [];
		$htmlBlocks = ChangeAiSiteState::getHtmlBlocks($generation);
		$htmlBlocksByActionId = [];
		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$actionId = trim((string)($item['actionId'] ?? ''));
			if ($actionId !== '')
			{
				$htmlBlocksByActionId[$actionId] = $item;
			}
		}

		$actions = [];
		$used = [];
		foreach (ChangeAiSiteState::getActions($generation) as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = trim((string)($action['type'] ?? ''));
			$actionId = trim((string)($action['actionId'] ?? ''));
			$htmlBlock = $actionId !== '' ? ($htmlBlocksByActionId[$actionId] ?? null) : null;
			$payload = $this->buildFinishActionPayload(
				$type,
				$action,
				$htmlBlock,
				$updatedIds,
				$createdIds,
				$deletedIds,
				$movedIds,
				$moveResults,
			);
			if ($payload === null)
			{
				continue;
			}

			$key = $payload['type'] . ':' . $payload['blockId'];
			if (isset($used[$key]))
			{
				continue;
			}

			$actions[] = $payload;
			$used[$key] = true;
		}

		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$payload = $this->buildFinishActionPayload(
				trim((string)($item['actionType'] ?? '')),
				[],
				$item,
				$updatedIds,
				$createdIds,
				$deletedIds,
				$movedIds,
				$moveResults,
			);
			if ($payload === null)
			{
				continue;
			}

			$key = $payload['type'] . ':' . $payload['blockId'];
			if (isset($used[$key]))
			{
				continue;
			}

			$actions[] = $payload;
			$used[$key] = true;
		}

		foreach (array_keys($deletedIds) as $blockId)
		{
			$key = 'delete_block:' . $blockId;
			if (isset($used[$key]))
			{
				continue;
			}

			$actions[] = [
				'type' => 'delete_block',
				'blockId' => $blockId,
			];
		}

		return $actions;
	}

	private function normalizeFinishAppliedActionsPayload(array $appliedActions): array
	{
		$actions = [];
		$used = [];
		foreach ($appliedActions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = trim((string)($action['type'] ?? ''));
			$blockId = (int)($action['blockId'] ?? 0);
			if (
				$blockId > 0
				&& in_array($type, ['update_block', 'add_block', 'delete_block'], true)
			)
			{
				$key = $type . ':' . $blockId;
				if (isset($used[$key]))
				{
					continue;
				}

				$used[$key] = true;
			}

			$actions[] = $action;
		}

		return $actions;
	}

	private function buildFinishActionPayload(
		string $type,
		array $action,
		?array $htmlBlock,
		array $updatedIds,
		array $createdIds,
		array $deletedIds,
		array $movedIds,
		array $moveResults,
	): ?array
	{
		if ($type === 'update_block')
		{
			$blockId = (int)($htmlBlock['blockId'] ?? 0);
			if ($blockId <= 0 || !isset($updatedIds[$blockId]))
			{
				return null;
			}

			return [
				'type' => $type,
				'blockId' => $blockId,
			];
		}

		if ($type === 'add_block')
		{
			$blockId = (int)($htmlBlock['blockId'] ?? 0);
			if ($blockId <= 0 || !isset($createdIds[$blockId]))
			{
				return null;
			}

			return [
				'type' => $type,
				'blockId' => $blockId,
				'placement' => $this->normalizePlacementPayload($action['placement'] ?? ($htmlBlock['placement'] ?? [])),
			];
		}

		if ($type === 'delete_block')
		{
			$blockId = (int)($action['blockId'] ?? 0);
			if ($blockId <= 0 || !isset($deletedIds[$blockId]))
			{
				return null;
			}

			return [
				'type' => $type,
				'blockId' => $blockId,
			];
		}

		if ($type === 'move_block')
		{
			$blockId = (int)($action['blockId'] ?? 0);
			if ($blockId <= 0 || !isset($movedIds[$blockId]))
			{
				return null;
			}

			$appliedActionId = trim((string)($moveResults[$blockId]['actionId'] ?? ''));
			$currentActionId = trim((string)($action['actionId'] ?? ''));
			if ($appliedActionId !== '' && $currentActionId !== '' && $currentActionId !== $appliedActionId)
			{
				return null;
			}

			return [
				'type' => $type,
				'blockId' => $blockId,
				'placement' => $this->normalizePlacementPayload(
					$moveResults[$blockId]['placement'] ?? ($action['placement'] ?? []),
				),
			];
		}

		return null;
	}

	private function normalizePlacementPayload(mixed $placement): array
	{
		$placement = is_array($placement) ? $placement : [];
		$mode = trim((string)($placement['mode'] ?? 'append'));
		if (!in_array($mode, ['append', 'prepend', 'after', 'before'], true))
		{
			$mode = 'append';
		}

		return [
			'mode' => $mode,
			'blockId' => max(0, (int)($placement['blockId'] ?? 0)),
		];
	}

	private function normalizeIds(array $ids): array
	{
		$ids = array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($ids));
	}
}
