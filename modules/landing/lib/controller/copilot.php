<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\IScenario;
use Bitrix\Landing\Copilot\Generation\Scenario\IStepMetaProvider;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;

class Copilot extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new Engine\ActionFilter\Authentication(),
			new ActionFilter\Extranet(),
		];
	}

	public function configureActions(): array
	{
		return [
			'magicSiteStatus' => [
				'+prefilters' => [
					new Engine\ActionFilter\Csrf(),
					new Engine\ActionFilter\HttpMethod([
						Engine\ActionFilter\HttpMethod::METHOD_POST,
					]),
				],
			],
		];
	}

	/**
	 * Try to find and execute AI generation
	 * @param int $generationId
	 * @return bool
	 */
	public static function executeGenerationAction(int $generationId): bool
	{
		if ($generationId <= 0)
		{
			return false;
		}

		$generation = new Generation();
		if (!$generation->initById($generationId))
		{
			return false;
		}

		return $generation->execute();
	}

	/**
	 * Read current state of a CreateAiSite generation by generationId.
	 *
	 * @param array $payload Expected keys: generationId (int).
	 * @return array|null
	 */
	public function magicSiteStatusAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesEnabled())
		{
			return null;
		}

		$generationId = (int)($payload['generationId'] ?? 0);
		$generation = $this->loadCreateAiSiteGeneration($generationId);
		if ($generation === null)
		{
			return null;
		}

		return $this->buildMagicSiteResponse($generation);
	}

	private function requireAiSitesEnabled(): bool
	{
		if (\Bitrix\Landing\Copilot\Manager::isAiSitesEnabled())
		{
			return true;
		}

		$this->addError(new Error('AI sites are disabled.', 'AI_SITES_DISABLED'));

		return false;
	}

	private function loadCreateAiSiteGeneration(int $generationId): ?Generation
	{
		if ($generationId <= 0)
		{
			$this->addError(new Error('Generation ID is missing.', 'GENERATION_ID_MISSING'));

			return null;
		}

		$generation = new Generation();
		if (!$generation->initById($generationId))
		{
			$this->addError(new Error('Generation was not found.', 'GENERATION_NOT_FOUND'));

			return null;
		}

		if (!$generation->getScenario() instanceof CreateAiSite)
		{
			$this->addError(new Error('Generation belongs to a different scenario.', 'WRONG_SCENARIO'));

			return null;
		}

		if ($generation->getAuthorId() !== Manager::getUserId())
		{
			$this->addError(new Error('Insufficient permissions to read generation.', 'ACCESS_DENIED'));

			return null;
		}

		return $generation;
	}

	private function buildMagicSiteResponse(Generation $generation, array $extra = []): ?array
	{
		$siteData = $generation->getSiteData();
		$siteId = (int)($siteData->getSiteId() ?? 0);
		$landingId = (int)($siteData->getLandingId() ?? 0);
		$hasSite = $siteId > 0 && $landingId > 0;
		$finished = $generation->isFinished();
		$error = $generation->isError();
		if ($finished && !$error && $hasSite && !$this->finalizeCompletedGenerationBinding($generation))
		{
			$this->addError(new Error(
				'Failed to finalize AI site binding before returning completed status.',
				'AI_SITE_BINDING_FINALIZE_FAILED'
			));

			return null;
		}

		$steps = $this->loadMagicSiteSteps(
			(int)($generation->getId() ?? 0),
			$generation->getScenario()
		);
		$currentStep = $this->resolveCurrentMagicSiteStep($steps, $generation->getStep());
		$enhanced = CreateAiSiteState::getEnhancedInput($generation);
		$structure = CreateAiSiteState::getStructure($generation);
		$htmlBlocks = CreateAiSiteState::getHtmlBlocks($generation);
		$tailwindRuntime = CreateAiSiteState::getTailwindRuntime($generation);
		$publication = $this->normalizeMagicSitePublication(CreateAiSiteState::getPublication($generation));
		$creationJournal = CreateAiSiteState::getCreationJournal($generation);
		$errorMessage = $error ? $this->buildMagicSiteErrorMessage($steps, $tailwindRuntime) : '';
		$recoverablePartial = $error
			&& !empty($creationJournal['recoverablePartial'])
			&& (int)($creationJournal['siteId'] ?? 0) > 0
		;

		$response = [
			'success' => true,
			'generationId' => (int)($generation->getId() ?? 0),
			'finished' => $finished,
			'error' => $error,
			'errorMessage' => $errorMessage,
			'message' => $this->buildMagicSiteStateMessage($finished, $error, $currentStep, $errorMessage),
			'siteId' => $siteId,
			'landingId' => $landingId,
			'hasSite' => $hasSite,
			'currentStep' => $currentStep,
			'steps' => array_values($steps),
			'input' => CreateAiSiteState::resolveInputLines($generation),
			'enhanced' => $enhanced ?: null,
			'structure' => $structure,
			'structureCount' => count($structure),
			'htmlBlocks' => $this->normalizeMagicSiteHtmlBlocks($htmlBlocks),
			'htmlReadyCount' => $this->countReadyHtmlBlocks($htmlBlocks),
			'blockCreatedCount' => $this->countCreatedBlocks($htmlBlocks),
			'tailwindRuntimeReady' => !empty($tailwindRuntime['success']),
			'tailwindRuntime' => $tailwindRuntime ?: null,
			'published' => $publication['published'],
			'publicationWarning' => $publication['warning'],
			'publication' => $publication,
			'recoverablePartial' => $recoverablePartial,
			'draftSiteId' => $recoverablePartial ? (int)$creationJournal['siteId'] : 0,
			'draftLandingId' => $recoverablePartial ? (int)$creationJournal['landingId'] : 0,
			'partialStage' => $recoverablePartial ? (string)$creationJournal['stage'] : '',
			'creationJournal' => $creationJournal,
		];

		return array_merge($response, $this->buildSiteLinks($siteId, $landingId), $extra);
	}

	private function buildSiteLinks(int $siteId, int $landingId): array
	{
		return [
			'editorUrl' => $this->buildEditorUrl($siteId, $landingId),
			'publicUrl' => $this->buildPublicUrl($siteId, $landingId),
		];
	}

	private function buildEditorUrl(int $siteId, int $landingId): string
	{
		if ($siteId <= 0 || $landingId <= 0)
		{
			return '';
		}

		return '/sites/site/' . $siteId . '/view/' . $landingId . '/';
	}

	private function buildPublicUrl(int $siteId, int $landingId): string
	{
		if ($siteId <= 0 || $landingId <= 0)
		{
			return '';
		}

		$landing = Landing::createInstance($landingId, ['skip_blocks' => true]);
		if ($landing->exist())
		{
			$url = trim((string)$landing->getPublicUrl());
			if ($url !== '')
			{
				return $url;
			}
		}

		return '/pub/site/' . $siteId . '/';
	}

	private function finalizeCompletedGenerationBinding(Generation $generation): bool
	{
		if ((new AiSiteBindingFinalizer())->finalizeCompletedGeneration($generation))
		{
			return true;
		}

		return CreateAiSiteState::getAiSiteBindingId($generation) <= 0;
	}

	private function normalizeMagicSitePublication(array $publication): array
	{
		return [
			'attempted' => !empty($publication['attempted']),
			'published' => !empty($publication['published']),
			'warning' => isset($publication['warning']) && is_scalar($publication['warning'])
				? (string)$publication['warning']
				: null,
			'errorCode' => isset($publication['errorCode']) && is_scalar($publication['errorCode'])
				? (string)$publication['errorCode']
				: null,
		];
	}

	private function loadMagicSiteSteps(int $generationId, ?IScenario $scenario = null): array
	{
		return $this->loadScenarioSteps($generationId, $scenario);
	}

	private function loadScenarioSteps(int $generationId, ?IScenario $scenario = null): array
	{
		$steps = [];
		foreach ($this->getScenarioStepMeta($scenario) as $stepId => $meta)
		{
			$steps[$stepId] = [
				'stepId' => $stepId,
				'code' => (string)($meta['code'] ?? ''),
				'label' => (string)($meta['label'] ?? ''),
				'status' => 'new',
			];
		}

		if ($generationId <= 0 || $steps === [])
		{
			return $steps;
		}

		$query = StepsTable::query()
			->setSelect(['STEP_ID', 'STATUS'])
			->where('GENERATION_ID', '=', $generationId)
			->exec()
		;

		while ($row = $query->fetch())
		{
			$stepId = (int)($row['STEP_ID'] ?? 0);
			if (!isset($steps[$stepId]))
			{
				continue;
			}

			$steps[$stepId]['status'] = $this->resolveStepStatusLabel((int)($row['STATUS'] ?? StepStatuses::New->value));
		}

		return $steps;
	}

	/**
	 * @return array<int, array{code: string, label: string}>
	 */
	private function getScenarioStepMeta(?IScenario $scenario): array
	{
		if (!$scenario)
		{
			return [];
		}

		$map = $scenario->getMap();
		$meta = $scenario instanceof IStepMetaProvider ? $scenario->getStepMeta() : [];
		$result = [];
		foreach ($map as $stepId => $_step)
		{
			$result[$stepId] = [
				'code' => isset($meta[$stepId]['code']) ? (string)$meta[$stepId]['code'] : ('step_' . $stepId),
				'label' => isset($meta[$stepId]['label']) ? (string)$meta[$stepId]['label'] : ('Step ' . $stepId),
			];
		}

		return $result;
	}

	private function resolveCurrentMagicSiteStep(array $steps, ?int $generationStepId): array
	{
		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') === 'error')
			{
				return $step;
			}
		}

		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') === 'started')
			{
				return $step;
			}
		}

		$generationStepId = (int)$generationStepId;
		if ($generationStepId > 0 && isset($steps[$generationStepId]))
		{
			return $steps[$generationStepId];
		}

		$finishedSteps = array_filter($steps, static fn(array $step): bool => ($step['status'] ?? '') === 'finished');
		if ($finishedSteps)
		{
			return end($finishedSteps) ?: reset($steps);
		}

		return reset($steps) ?: [
			'stepId' => 0,
			'code' => 'unknown',
			'label' => 'Waiting to start',
			'status' => 'new',
		];
	}

	private function resolveStepStatusLabel(int $status): string
	{
		return match (StepStatuses::tryFrom($status))
		{
			StepStatuses::Started => 'started',
			StepStatuses::Finished => 'finished',
			StepStatuses::Error => 'error',
			default => 'new',
		};
	}

	private function buildMagicSiteErrorMessage(array $steps, array $tailwindRuntime = []): string
	{
		$messagesByStepId = $this->getCreateAiSiteErrorMessages();
		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') !== 'error')
			{
				continue;
			}

			$stepId = (int)($step['stepId'] ?? 0);

			return $messagesByStepId[$stepId] ?? 'Scenario finished with an error.';
		}

		return 'Scenario finished with an error.';
	}

	/**
	 * @return array<int, string>
	 */
	private function getCreateAiSiteErrorMessages(): array
	{
		return [
			10 => 'Failed to prepare scenario input data.',
			15 => 'The requested topic is not allowed for AI site generation.',
			20 => 'Failed to enhance user input.',
			30 => 'Failed to prepare site data.',
			40 => 'Failed to generate preview image.',
			50 => 'Failed to generate Tailwind structure.',
			60 => 'Failed to generate HTML for blocks.',
			70 => 'Failed to improve block HTML.',
			75 => 'Failed to resolve site domain.',
			80 => 'Failed to create site and landing.',
			90 => 'Failed to initialize Tailwind runtime for the page.',
			100 => 'Failed to prepare block HTML markup.',
			110 => 'Failed to prepare CRM forms in block markup.',
			120 => 'Failed to add HTML blocks to landing.',
			130 => 'Failed to sync page font theme.',
			140 => 'Failed to prepare created block HTML markup.',
			150 => 'Failed to generate images for blocks.',
			160 => 'Failed to publish site.',
			1000 => 'Scenario finished with an error.',
		];
	}

	private function buildMagicSiteStateMessage(bool $finished, bool $error, array $currentStep, string $errorMessage): string
	{
		if ($error)
		{
			return $errorMessage !== '' ? $errorMessage : 'Scenario finished with an error.';
		}

		if ($finished)
		{
			return 'Site has been created.';
		}

		$label = trim((string)($currentStep['label'] ?? ''));

		return $label !== '' ? 'In progress: ' . $label . '.' : 'Scenario is running.';
	}

	private function normalizeMagicSiteHtmlBlocks(array $htmlBlocks): array
	{
		$normalized = [];
		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$normalized[] = [
				'position' => (int)($item['position'] ?? 0),
				'spec' => isset($item['spec']) && is_array($item['spec']) ? $item['spec'] : [],
				'html' => trim((string)($item['html'] ?? '')),
				'blockId' => (int)($item['blockId'] ?? 0),
				'anchor' => trim((string)($item['anchor'] ?? '')),
				'improved' => !empty($item['improved']),
			];
		}

		usort($normalized, static function (array $left, array $right): int
		{
			return ((int)($left['position'] ?? 0)) <=> ((int)($right['position'] ?? 0));
		});

		return $normalized;
	}

	private function countReadyHtmlBlocks(array $htmlBlocks): int
	{
		$count = 0;
		foreach ($htmlBlocks as $item)
		{
			if (is_array($item) && trim((string)($item['html'] ?? '')) !== '')
			{
				$count++;
			}
		}

		return $count;
	}

	private function countCreatedBlocks(array $htmlBlocks): int
	{
		$count = 0;
		foreach ($htmlBlocks as $item)
		{
			if (is_array($item) && (int)($item['blockId'] ?? 0) > 0)
			{
				$count++;
			}
		}

		return $count;
	}
}
