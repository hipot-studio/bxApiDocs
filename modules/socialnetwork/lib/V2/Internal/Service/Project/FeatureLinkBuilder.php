<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Collab\Integration\Note\CollabKnowledgeService;
use Bitrix\Socialnetwork\Helper\Path;
use Bitrix\Socialnetwork\V2\Internal\Integration;

class FeatureLinkBuilder
{
	public function __construct(
		private readonly Integration\Landing\Service\Project $landingProjectService,
		private readonly Integration\Tasks\Flow\Service\Path $flowPathService,
		private readonly CollabKnowledgeService $collabKnowledgeService,
	)
	{
	}

	public function getUrlTemplate(string $featureId, int $projectId, int $userId): ?string
	{
		if ($featureId === FeatureDictionary::LandingKnowledge->value)
		{
			return $this->buildLandingKnowledgeUrl($projectId);
		}

		if ($featureId === FeatureDictionary::Flows->value)
		{
			return $this->buildFlowsUrlTemplate($userId);
		}

		$template = FeatureDictionary::getUrlTemplateById($featureId);
		if ($template === null)
		{
			return null;
		}

		if (FeatureDictionary::isPlacementFeature($featureId))
		{
			return str_replace('#placement_id#', FeatureDictionary::getPlacementId($featureId), $template);
		}

		return $template;
	}

	public function build(string $featureId, int $projectId, int $userId): ?string
	{
		if ($featureId === FeatureDictionary::LandingKnowledge->value)
		{
			return $this->buildLandingKnowledgeUrl($projectId);
		}

		if ($featureId === FeatureDictionary::Flows->value)
		{
			return $this->buildFlowsUrl($projectId, $userId);
		}

		if ($featureId === FeatureDictionary::Note->value)
		{
			return $this->buildNoteUrl($projectId, $userId);
		}

		if ($featureId === FeatureDictionary::Files->value)
		{
			return \CComponentEngine::makePathFromTemplate(
				Path::get('group_files_path_template'),
				[
					'group_id' => $projectId,
					'PATH' => '',
				],
			);
		}

		if ($featureId === FeatureDictionary::Blog->value)
		{
			return \CComponentEngine::makePathFromTemplate(
				Path::get('group_path_template') . 'general/',
				['group_id' => $projectId],
			);
		}

		$template = $this->getUrlTemplate($featureId, $projectId, $userId);
		if ($template === null || $template === '')
		{
			return null;
		}

		return \CComponentEngine::makePathFromTemplate(
			$template,
			['group_id' => $projectId],
		);
	}

	private function buildLandingKnowledgeUrl(int $projectId): ?string
	{
		return $this->landingProjectService->getKnowledgeUrl($projectId);
	}

	private function buildNoteUrl(int $projectId, int $userId): ?string
	{
		$section = $this->collabKnowledgeService->resolveSection($projectId, $userId);

		$collectionId = $section['collectionId'];
		if ($section['available'] !== true || $section['canView'] !== true || $collectionId === null)
		{
			return null;
		}

		// Q-1: scoped-section URL until note exposes a single-collection launch contract.
		// note's SPA is history-mode (base /note/, route /workspace/{id}/), so the path resolves
		// without a hash; the catch-all /note/* page renders the SPA.
		return '/note/workspace/' . $collectionId . '/';
	}

	private function buildFlowsUrl(int $projectId, int $userId): ?string
	{
		if ($userId <= 0)
		{
			return null;
		}

		$url = $this->flowPathService->get($userId, $projectId);

		return ($url !== '') ? $url : null;
	}

	private function buildFlowsUrlTemplate(int $userId): ?string
	{
		if ($userId <= 0)
		{
			return null;
		}

		$url = $this->flowPathService->getTemplate($userId);

		return ($url !== '') ? $url : null;
	}
}
