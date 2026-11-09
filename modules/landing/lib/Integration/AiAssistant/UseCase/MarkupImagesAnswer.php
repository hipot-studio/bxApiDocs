<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Markup\Image\GenerationProgress;

/**
 * The pictures of a markup package in the answers of the submission and the status (ADR 3.4): a
 * completed job names how many pictures it got, inherited and failed to get, with the token and the
 * reason of every failed one, so the agent tells the user; a job on the step of the pictures names
 * its progress; a spent AI limit travels with its slider, worded by the helper of the ordinary limit
 * refusal. A package without pictures has no field at all.
 *
 * Reading state only, as every answer builder of the markup path.
 */
trait MarkupImagesAnswer
{
	/**
	 * @return array{images?: array} The images field of the answer, nothing when there is nothing to say.
	 */
	protected function buildImagesFields(Generation $generation): array
	{
		$state = new MarkupSiteState($generation);
		$progress = GenerationProgress::fromState($state->getImageProgress());

		$images = $generation->isFinished()
			? $this->buildImagesSummary($state->getImageSummary())
			: $this->buildImagesProgress($generation, $progress);
		if ($images === null)
		{
			return [];
		}

		$limit = $progress->getLimit();
		if ($limit !== null)
		{
			$normalized = AiSiteGenerationHelper::normalizeLimitError([
				'type' => 'limit',
				'text' => $limit['text'] ?? null,
				'sliderCode' => $limit['sliderCode'] ?? null,
			]);
			$images['limit'] = ['sliderCode' => $normalized['sliderCode'], 'text' => $normalized['text']];
		}

		return ['images' => $images];
	}

	/**
	 * @return array{total: int, generated: int, reused: int, failed: list<array{token: string, reason: string}>}|null
	 */
	private function buildImagesSummary(?array $summary): ?array
	{
		$total = (int)($summary['total'] ?? 0);
		if ($summary === null || $total <= 0)
		{
			return null;
		}

		$failed = [];
		foreach ((array)($summary['failed'] ?? []) as $item)
		{
			if (is_array($item))
			{
				$failed[] = ['token' => (string)($item['token'] ?? ''), 'reason' => (string)($item['reason'] ?? '')];
			}
		}

		return [
			'total' => $total,
			'generated' => (int)($summary['generated'] ?? 0),
			'reused' => (int)($summary['reused'] ?? 0),
			'failed' => $failed,
		];
	}

	/**
	 * The progress on the step of the pictures: how many are asked for and how many have their outcome.
	 * @return array{total: int, done: int}|null
	 */
	private function buildImagesProgress(Generation $generation, GenerationProgress $progress): ?array
	{
		if (!$this->isOnImagesStep($generation) || !$progress->isStarted())
		{
			return null;
		}

		$total = $progress->countItems();
		if ($total <= 0)
		{
			return null;
		}

		return ['total' => $total, 'done' => count($progress->getOutcomes())];
	}

	private function isOnImagesStep(Generation $generation): bool
	{
		$scenario = $generation->getScenario();
		if (!$scenario instanceof CreateMarkupSite)
		{
			return false;
		}

		$meta = $scenario->getStepMeta()[(int)($generation->getStep() ?? 0)] ?? null;

		return is_array($meta) && (string)($meta['code'] ?? '') === 'generate_images';
	}
}
