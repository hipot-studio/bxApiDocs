<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\Landing\Vibe\VibeRepository;
use Bitrix\Landing\Vibe\VibeRenderOptions;
use Bitrix\Landing\Vibe\Vibe;

class CrmAutomatedSolutionStartSettingsComponent extends \Bitrix\Crm\Component\Base
{
	public function executeComponent(): void
	{
		if (!Feature::enabled(AutomatedSolutionVibe::class) || !VibeRepository::isVibeAvailable())
		{
			return;
		}

		$this->init();

		$automatedSolutionId = (int)($this->arParams['automatedSolutionId'] ?? 0);
		if ($automatedSolutionId === 0)
		{
			return;
		}
		if (!$this->userPermissions->automatedSolution()->canEdit($automatedSolutionId))
		{
			return;
		}

		$automatedSolution
			= \Bitrix\Crm\Service\Container::getInstance()
				->getAutomatedSolutionManager()
				->getAutomatedSolution($automatedSolutionId)
		;

		if (
			empty($automatedSolution)
			|| (int)($automatedSolution['VIBE_ID'] ?? 0) <= 0
		)
		{
			return;
		}

		$vibe = \Bitrix\Crm\Service\Container::getInstance()
			->getVibeRepository()
			?->getVibeById((int)$automatedSolution['VIBE_ID'])
		;

		$this->renderVibeSettings($vibe);
	}

	private function renderVibeSettings(?array $vibe): void
	{
		if (empty($vibe) || !isset($vibe['EMBED_ID']))
		{
			return;
		}

		\Bitrix\Landing\Vibe\Renderer::renderSettingsGroup(
			[
				new Vibe('crm', $vibe['EMBED_ID']),
			],
			new VibeRenderOptions(
				canShowPublishButton: false,
				canShowWithdrawButton: false,
				canRedirectToVibeAfterEdit: false,
			),
		);
	}
}
