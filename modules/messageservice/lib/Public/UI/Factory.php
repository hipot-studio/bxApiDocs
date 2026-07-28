<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\MessageService\Internal\UI\Provider;
use Bitrix\MessageService\Public\UI\ChannelSelector\Selector;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\ConnectionsSlider;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Section;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Editor;
use Bitrix\MessageService\Public\UI\MessageEditor\Preferences;
use Bitrix\MessageService\Public\UI\MessageEditor\PromoBanner;
use Bitrix\MessageService\Public\UI\MessageEditor\Scene;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Sender\Base;
use Bitrix\MessageService\Sender\SmsManager;

final class Factory
{
	/** @var Provider\Provider[] */
	private array $providers;

	/** @var array<class-string<\Bitrix\MessageService\Public\UI\ConnectionsSlider\Page>> */
	private array $connectionsSliderPages;

	/** @var ViewChannel[]|null */
	private ?array $cachedEditorViewChannels = null;

	public function __construct()
	{
		$this->providers = [
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
		];

		$this->connectionsSliderPages = [
			\Bitrix\MessageService\Public\UI\ConnectionsSlider\Page\Recommendations::class,
			\Bitrix\MessageService\Public\UI\ConnectionsSlider\Page\Sms::class,
		];
	}

	public function createConnectionsSlider(): ConnectionsSlider
	{
		$providers = $this->providers;

		$senders = $this->getAllSenders();
		$sections = $this->getAllConnectionsSliderSections($senders, $providers);
		$pages = $this->getAllConnectionsSliderPages($sections);

		return new ConnectionsSlider($pages);
	}

	public function createEditor(Scene $scene, Context $context): Editor
	{
		$senders = $this->getAllSenders();
		$viewChannels = $this->getAllEditorViewChannels($senders);

		$sceneViewChannels = $scene->filterViewChannels($viewChannels, $context);

		$promoBanners = null;
		if ($this->shouldShowPromoInEditor($viewChannels))
		{
			$promoBanners = $this->getAllEditorPromoBanners($sceneViewChannels);
		}

		$onlyConnectedViewChannels = array_filter(
			$sceneViewChannels,
			static fn(ViewChannel $vc) => $vc->isConnected(),
		);

		return (new Editor($scene, $context))
			->setDynamicLoad(false)
			->setViewChannels(array_values($onlyConnectedViewChannels))
			->setPromoBanners($promoBanners)
			->setPreferences($this->getPreferences($scene, $context))
		;
	}

	public function createSelector(Scene $scene, Context $context): Selector
	{
		$editor = $this->createEditor($scene, $context);

		return Selector::fromEditor($editor);
	}

	/**
	 * @param Base[] $senders
	 * @param Provider\Provider[] $providers
	 * @return Section[]
	 */
	private function getAllConnectionsSliderSections(array $senders, array $providers): array
	{
		$allSections = [];

		$remainingSenders = $senders;
		foreach ($providers as $provider)
		{
			[$sections, $usedSenders] = $provider->createConnectionsSliderSections($remainingSenders);

			$allSections = [...$allSections, ...$sections];
			$remainingSenders = array_filter(
				$remainingSenders,
				static fn(Base $sender) => !in_array($sender, $usedSenders, true),
			);
		}

		return $allSections;
	}

	/**
	 * @return Base[]
	 */
	private function getAllSenders(): array
	{
		return SmsManager::getSenders();
	}

	/**
	 * @param Base[] $senders
	 * @return ViewChannel[]
	 */
	private function getAllEditorViewChannels(array $senders): array
	{
		if (is_array($this->cachedEditorViewChannels))
		{
			return $this->cachedEditorViewChannels;
		}

		$allViewChannels = [];

		$remainingSenders = $senders;
		foreach ($this->providers as $provider)
		{
			[$viewChannels, $usedSenders] = $provider->createEditorViewChannels($remainingSenders);

			$allViewChannels = [...$allViewChannels, ...$viewChannels];
			$remainingSenders = array_filter(
				$remainingSenders,
				static fn(Base $sender) => !in_array($sender, $usedSenders, true),
			);
		}

		$this->cachedEditorViewChannels = $allViewChannels;

		return $this->cachedEditorViewChannels;
	}

	/**
	 * @param ViewChannel[] $viewChannels
	 */
	private function shouldShowPromoInEditor(array $viewChannels): bool
	{
		foreach ($viewChannels as $viewChannel)
		{
			if ($viewChannel->isPromo() && $viewChannel->isConnected())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param ViewChannel[] $viewChannels
	 *
	 * @return PromoBanner[]
	 */
	private function getAllEditorPromoBanners(array $viewChannels): array
	{
		$promoBanners = [];
		foreach ($this->providers as $handler)
		{
			$promoBanners = [
				...$promoBanners,
				...$handler->createEditorPromoBanners($viewChannels),
			];
		}

		return $promoBanners;
	}

	/**
	 * @param Section[] $sections
	 * @return array<\Bitrix\MessageService\Public\UI\ConnectionsSlider\Page>
	 */
	private function getAllConnectionsSliderPages(array $sections): array
	{
		$pages = [];
		foreach ($this->connectionsSliderPages as $pageClass)
		{
			/** @var class-string<\Bitrix\MessageService\Public\UI\ConnectionsSlider\Page> $pageClass */
			$page = $pageClass::create($sections);
			if ($page !== null)
			{
				$pages[] = $page;
			}
		}

		return $pages;
	}

	private function getPreferences(Scene $scene, Context $context): ?Preferences
	{
		$array = \CUserOptions::GetOption('messageservice.message.editor', $scene->getId(), false, $context->getUserId());
		if (empty($array) || !is_array($array))
		{
			return null;
		}

		$preferences = Preferences::fromArray($array);

		$validation = ServiceLocator::getInstance()->get('main.validation.service');
		$result = $validation->validate($preferences);
		if (!$result->isSuccess())
		{
			return null;
		}

		return $preferences;
	}
}
