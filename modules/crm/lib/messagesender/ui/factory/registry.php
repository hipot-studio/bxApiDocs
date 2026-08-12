<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Factory;

use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Editor\Scene\BaseScene;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class Registry
{
	private ?array $scenes = null;

	/**
	 * @return BaseScene[]
	 */
	public function getScenes(): array
	{
		if (is_array($this->scenes))
		{
			return $this->scenes;
		}

		$builtInScenes = $this->getBuiltInScenes();
		$eventScenes = $this->getScenesFromEvents();

		// dont allow override built-in scenes
		$this->scenes = array_values($builtInScenes + $eventScenes);

		return $this->scenes;
	}

	private function getScenesFromEvents(): array
	{
		$event = new Event('crm', 'onGetMessageSenderEditorScenes');
		$event->send();

		$allScenes = [];
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				continue;
			}

			$result = $eventResult->getParameters();
			if (!isset($result['scenes']) || !is_array($result['scenes']))
			{
				continue;
			}

			/** @var BaseScene[] $scenes */
			$scenes = array_filter(
				$result['scenes'],
				static fn($scene) => $scene instanceof BaseScene,
			);
			foreach ($scenes as $scene)
			{
				$allScenes[$scene->getId()] = $scene;
			}
		}

		return $allScenes;
	}

	private function getBuiltInScenes(): array
	{
		$scenes = [
			new Editor\Scene\ItemDetails(),
			new Editor\Scene\DocumentView(),
			new Editor\Scene\PaymentDetails(),
		];

		$idToScene = [];
		/** @var BaseScene $scene */
		foreach ($scenes as $scene)
		{
			$idToScene[$scene->getId()] = $scene;
		}

		return $idToScene;
	}

}
