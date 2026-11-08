<?php

namespace Bitrix\Crm\Integration\Landing\Vibe;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\Intranet\SystemPageProvider\StartPage;
use Bitrix\Crm\Service\Container;
use Bitrix\Landing\Vibe\Provider\AbstractVibeProvider;
use Bitrix\Main\Localization\Loc;

final class VibeProvider extends AbstractVibeProvider
{
	public function getTitle(): string
	{
		return Loc::getMessage(
			'CRM_INTEGRATION_LANDING_AUTOMATED_SOLUTION_VIBE_TITLE',
			[
				'#PAGE_NAME#' => $this->getAutomatedSolutionTitle(),
			],
		);
	}

	public function getUrlPublic(): string
	{
		$automatedSolution = $this->getAutomatedSolution();
		$code = $automatedSolution['CODE'] ?? null;
		if (!is_string($code) || $code === '')
		{
			return '';
		}

		return '/page/' . $code . '/' . StartPage::CODE . '/';
	}

	public function isPublished(): bool
	{
		return AutomatedSolutionVibe::isEnabledForAutomatedSolution($this->getAutomatedSolution() ?? []);
	}

	public function onPublish(): void
	{
		$this->synchronizePublication(true);
	}

	public function onWithdraw(): void
	{
		$this->synchronizePublication(false);
	}

	private function synchronizePublication(bool $isVibeEnabled): void
	{
		if (!Feature::enabled(AutomatedSolutionVibe::class))
		{
			return;
		}

		$automatedSolutionId = $this->getAutomatedSolutionId();
		if ($automatedSolutionId === null)
		{
			return;
		}

		Container::getInstance()
			->getAutomatedSolutionVibeLifecycleService()
			->synchronizePublication($automatedSolutionId, $isVibeEnabled)
		;
	}

	private function getEmbedId(): string
	{
		return $this->getContext()->getEmbedId();
	}

	private function getAutomatedSolutionTitle(): string
	{
		return $this->getAutomatedSolution()['TITLE'] ?? '';
	}

	private function getAutomatedSolution(): ?array
	{
		$automatedSolutionId = $this->getAutomatedSolutionId();
		if ($automatedSolutionId === null)
		{
			return null;
		}

		return Container::getInstance()
			->getAutomatedSolutionManager()
			->getAutomatedSolution($automatedSolutionId)
		;
	}

	private function getAutomatedSolutionId(): ?int
	{
		if (!preg_match('/^crm_automated_solution_(\\d+)$/', $this->getEmbedId(), $matches))
		{
			return null;
		}

		return (int)$matches[1];
	}
}
