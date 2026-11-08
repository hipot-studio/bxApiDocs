<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\Landing\Vibe\VibeRepository;
use Bitrix\Landing\Vibe;

class CrmAutomatedSolutionStartComponent extends Base
{
	public function executeComponent(): void
	{
		if (!Feature::enabled(AutomatedSolutionVibe::class) || !VibeRepository::isVibeAvailable())
		{
			return;
		}

		$this->init();

		if (!$this->checkParams())
		{
			return;
		}

		$vibe = $this->arParams['vibe'] ?? [];

		$this->renderVibe($vibe['EMBED_ID']);
	}

	private function checkParams(): bool
	{
		if (empty($this->arParams['automatedSolution']) || empty($this->arParams['vibe']))
		{
			return false;
		}

		if (!is_array($this->arParams['automatedSolution']) || !is_array($this->arParams['vibe']))
		{
			return false;
		}

		if (
			(int)($this->arParams['automatedSolution']['VIBE_ID'] ?? 0) <= 0
			|| !AutomatedSolutionVibe::isEnabledForAutomatedSolution($this->arParams['automatedSolution'])
			|| !isset($this->arParams['vibe']['EMBED_ID'], $this->arParams['vibe']['ID'])
		)
		{
			return false;
		}

		return true;
	}

	private function renderVibe(string $embedId): void
	{
		$vibe = new Vibe\Vibe('crm', $embedId);

		$vibe->renderView();
	}
}
