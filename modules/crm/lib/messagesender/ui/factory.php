<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\SenderRepository;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;
use Bitrix\Crm\MessageSender\UI\Editor\Context;
use Bitrix\Crm\MessageSender\UI\Editor\Preferences;
use Bitrix\Crm\MessageSender\UI\Editor\Scene;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Factory\Registry;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;

final class Factory
{
	use Singleton;

	private Registry $registry;

	private function __construct()
	{
		$this->registry = Registry::getInstance();
	}

	public function createConnectionsSlider(?int $userId = null): ConnectionsSlider
	{
		$userId ??= Container::getInstance()->getContext()->getUserId();

		$channels = $this->getAllGenericChannels($userId);
		$sections = $this->getAllConnectionsSliderSections($channels);
		$pages = $this->getAllConnectionsSliderPages($sections);

		return new ConnectionsSlider($pages);
	}

	/**
	 * Creates a fully loaded and ready to use Editor instance. You'll need to define only the renderTo element.
	 *
	 * @param Scene $scene Where the editor will be used
	 * @param Context $context
	 *
	 * @return Editor
	 */
	public function createEditor(Scene $scene, Context $context): Editor
	{
		if ($context->getItemIdentifier())
		{
			$repo = Channel\ChannelRepository::createWithPermissions(
				$context->getItemIdentifier(),
				$context->getUserId(),
			);

			$channels = $repo->getAll();
		}
		else
		{
			$channels = $this->getAllGenericChannels($context->getUserId());
		}

		$viewChannels = $this->getAllEditorViewChannels($channels);

		if ($context->getEntityTypeId() !== null && !Container::getInstance()->getFactory($context->getEntityTypeId())?->isDocumentGenerationSupported())
		{
			$viewChannels = array_filter(
				$viewChannels,
				static fn(ViewChannel $vc) => !$vc->getBackend()->isTemplatesBased(),
			);
		}

		$sceneViewChannels = $scene->filterViewChannels($viewChannels);

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
			->setContentProviders($this->getAllContentProviders($context))
			->setPreferences($this->getPreferences($scene, $context))
		;
	}

	public function getScene(string $sceneId): ?Scene
	{
		foreach ($this->registry->getScenes() as $scene)
		{
			if ($scene->getId() === $sceneId)
			{
				return $scene;
			}
		}

		return null;
	}

	/**
	 * @param Channel[] $channels
	 * @return Section[]
	 */
	private function getAllConnectionsSliderSections(array $channels): array
	{
		$allSections = [];

		$remainingChannels = $channels;
		foreach ($this->registry->getProviders() as $provider)
		{
			if (empty($remainingChannels))
			{
				break;
			}

			[$sections, $usedChannels] = $provider->createConnectionsSliderSections($remainingChannels);

			$allSections = [...$allSections, ...$sections];
			$remainingChannels = array_filter(
				$remainingChannels,
				static fn(Channel $channel) => !in_array($channel, $usedChannels, true),
			);
		}

		return $allSections;
	}

	/**
	 * @return Channel[]
	 */
	private function getAllGenericChannels(int $userId): array
	{
		$channels = [];
		foreach (SenderRepository::getAllImplementationsList() as $sender)
		{
			// todo hack? what if sender checks that To is not empty?
			$channels = [
				...$channels,
				...$sender::getChannelsList([], $userId),
			];
		}

		return $channels;
	}

	/**
	 * @param Channel[] $channels
	 * @return ViewChannel[]
	 */
	private function getAllEditorViewChannels(array $channels): array
	{
		$allViewChannels = [];

		$remainingChannels = $channels;
		foreach ($this->registry->getProviders() as $provider)
		{
			if (empty($remainingChannels))
			{
				break;
			}

			[$viewChannels, $usedChannels] = $provider->createEditorViewChannels($remainingChannels);

			$allViewChannels = [...$allViewChannels, ...$viewChannels];
			$remainingChannels = array_filter(
				$remainingChannels,
				static fn(Channel $channel) => !in_array($channel, $usedChannels, true),
			);
		}

		return $allViewChannels;
	}

	/**
	 * @param ViewChannel[] $viewChannels
	 *
	 * @return bool
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

	private function getAllEditorPromoBanners(array $viewChannels): array
	{
		$promoBanners = [];
		foreach ($this->registry->getProviders() as $handler)
		{
			$promoBanners = [
				...$promoBanners,
				...$handler->createEditorPromoBanners($viewChannels),
			];
		}

		return $promoBanners;
	}

	private function getAllConnectionsSliderPages(array $sections): array
	{
		$pages = [];
		foreach ($this->registry->getPageImplementations() as $pageClass)
		{
			/** @var class-string<ConnectionsSlider\Page> $pageClass */
			$page = $pageClass::create($sections);
			if ($page !== null)
			{
				$pages[] = $page;
			}
		}

		return $pages;
	}

	/**
	 * @return array<ContentProvider>
	 */
	private function getAllContentProviders(Context $context): array
	{
		$providers = [];
		foreach ($this->registry->getContentProviderImplementations() as $class)
		{
			$providers[] = new $class($context);
		}

		return $providers;
	}

	private function getPreferences(Scene $scene, Context $context): ?Preferences
	{
		$preferences = \CUserOptions::GetOption('crm', 'crm.messagesender.editor', false, $context->getUserId());
		if (empty($preferences) || !is_array($preferences))
		{
			return null;
		}

		$json = $preferences[$scene->getId()] ?? null;
		if (!is_string($json))
		{
			return null;
		}

		try
		{
			$fields = \Bitrix\Main\Web\Json::decode($json);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return null;
		}

		if (!is_array($fields))
		{
			return null;
		}

		$preferencesObject = new Preferences($fields);
		if ($preferencesObject->hasValidationErrors())
		{
			return null;
		}

		return $preferencesObject;
	}
}
