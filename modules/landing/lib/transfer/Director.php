<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer;

use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Script\Action\ActionConfig;
use Bitrix\Landing\Transfer\Script\Action\Closet\AppearanceMode;

class Director
{
	private Requisite\Context $context;

	private bool $isFinishRun;

	public function __construct(Requisite\Context $context)
	{
		$this->context = $context;
		$this->isFinishRun = false;
	}

	public function setFinishRun(): static
	{
		$this->isFinishRun = true;

		return $this;
	}

	/**
	 * @param Script\IScript $script
	 * @return void
	 * @throws TransferException
	 */
	public function make(Script\IScript $script): void
	{
		foreach ($script->getMap() as $actionConfig)
		{
			if (!$this->checkAppearance($actionConfig))
			{
				continue;
			}

			$action = $actionConfig->createAction($this->context);
			$action->action();

			if ($action->isEndEpisode())
			{
				return;
			}
		}
	}

	private function checkAppearance(ActionConfig $actionConfig): bool
	{
		$appearance = $actionConfig->getAppearanceMode();
		if ($appearance === AppearanceMode::Intro)
		{
			return $this->isIntroRun();
		}

		if ($appearance === AppearanceMode::Core)
		{
			return $this->isCoreRun();
		}

		if ($appearance === AppearanceMode::Finish)
		{
			return $this->isFinishRun();
		}

		if ($appearance === AppearanceMode::NonFinish)
		{
			return !$this->isFinishRun();
		}

		return true;
	}

	private function isIntroRun(): bool
	{
		return Requisite\Factory::isIntroRun($this->context);
	}

	private function isCoreRun(): bool
	{
		return !$this->isFinishRun() && !$this->isIntroRun();
	}

	private function isFinishRun(): bool
	{
		return $this->isFinishRun;
	}
}
