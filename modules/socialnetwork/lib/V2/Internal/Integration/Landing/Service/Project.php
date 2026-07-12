<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Landing\Service;

use Bitrix\Bitrix24\Feature;
use Bitrix\Landing\Connector\SocialNetwork;
use Bitrix\Landing\Rights;
use Bitrix\Main\Loader;

class Project
{
	private const KNOWLEDGE_FEATURE_CODE = 'landing_knowledge_group';
	private const KNOWLEDGE_RESTRICTION_CODE = 'limit_crm_free_knowledge_base_project';

	public function getKnowledgeTitle(int $projectId): string
	{
		if (!$this->isAvailable())
		{
			return '';
		}

		return trim(SocialNetwork::getSocNetMenuTitle($projectId));
	}

	public function getKnowledgeUrl(int $projectId): ?string
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$url = SocialNetwork::getSocNetMenuUrl($projectId);

		return ($url !== '') ? $url : null;
	}

	public function canReadKnowledge(int $projectId): bool
	{
		return $this->isAvailable()
			&& SocialNetwork::canPerformOperation($projectId, Rights::ACCESS_TYPES['read']);
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('landing');
	}

	public function isAvailableByFeature(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Feature::isFeatureEnabled(self::KNOWLEDGE_FEATURE_CODE);
		}

		return true;
	}

	public function getKnowledgeRestrictionCode(): ?string
	{
		if ($this->isAvailableByFeature())
		{
			return null;
		}

		return self::KNOWLEDGE_RESTRICTION_CODE;
	}
}
