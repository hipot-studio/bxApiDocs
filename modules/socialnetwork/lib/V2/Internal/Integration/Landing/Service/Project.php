<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Landing\Service;

use Bitrix\Bitrix24\Feature;
use Bitrix\Landing\Connector\SocialNetwork;
use Bitrix\Landing\Rights;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class Project
{
	private const KNOWLEDGE_FEATURE_CODE = 'landing_knowledge_group';
	private const KNOWLEDGE_RESTRICTION_CODE = 'limit_crm_free_knowledge_base_project';

	// synced with \Bitrix\Landing\Connector\SocialNetwork::SETTINGS_CODE_SHORT
	private const KNOWLEDGE_DEEP_LINK_TAB = 'knowledge';

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

	public function processKnowledgeDeepLink(int $projectId): void
	{
		if (!$this->isKnowledgeDeepLinkHit() || !$this->isAvailable())
		{
			return;
		}

		SocialNetwork::processGroupKnowledgeDeepLink($projectId);
	}

	private function isKnowledgeDeepLinkHit(): bool
	{
		$request = Application::getInstance()->getContext()->getRequest();

		return $request->get('tab') === self::KNOWLEDGE_DEEP_LINK_TAB;
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
