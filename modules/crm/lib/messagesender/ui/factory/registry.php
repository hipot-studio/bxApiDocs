<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Factory;

use Bitrix\Crm\MessageSender\UI\ConnectionsSlider;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class Registry
{
	use Singleton;

	private ?array $scenes = null;
	private ?array $providers = null;

	/**
	 * @return Editor\Scene[]
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

			/** @var Editor\Scene[] $scenes */
			$scenes = array_filter(
				$result['scenes'],
				static fn($scene) => $scene instanceof Editor\Scene,
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
			new Editor\Scene\NullScene(),
			new Editor\Scene\DocumentView(),
			new Editor\Scene\PaymentDetails(),
		];

		$idToScene = [];
		/** @var Editor\Scene $scene */
		foreach ($scenes as $scene)
		{
			$idToScene[$scene->getId()] = $scene;
		}

		return $idToScene;
	}

	/**
	 * @return Provider[]
	 */
	public function getProviders(): array
	{
		// sorted by priority and order vendors should be displayed in slider
		$this->providers ??= [
			new Provider\Wazzup(),
			new Provider\Edna(),
			new Provider\Notifications(),
			new Provider\Sms\SmsRu(),
			new Provider\Sms\MobileMarketing(),
			new Provider\Sms\SmsAssistent(),
			new Provider\Sms\Edna(),
			new Provider\Sms\Twilio(),
			new Provider\Sms\Rest(),
			new Provider\Sms\Generic(),
			// notifications and sms covered by their own providers
			// exclude email for now (maybe forever)
			// new Provider\Generic(),
		];


		return $this->providers;
	}

	/**
	 * @return class-string<Editor\ContentProvider>[]
	 */
	public function getContentProviderImplementations(): array
	{
		return [
			Editor\ContentProvider\Files::class,
			Editor\ContentProvider\SalesCenter::class,
			Editor\ContentProvider\Documents::class,
			Editor\ContentProvider\CrmValues::class,
			Editor\ContentProvider\Copilot::class,
		];
	}

	/**
	 * @return class-string<ConnectionsSlider\Page>[]
	 */
	public function getPageImplementations(): array
	{
		return [
			ConnectionsSlider\Page\Recommendations::class,
			ConnectionsSlider\Page\Sms::class,
		];
	}
}
