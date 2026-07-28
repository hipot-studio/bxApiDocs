<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

use Bitrix\Main\Type\Collection;
use Bitrix\MessageService\Integration\Notifications;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\To;

final class Editor implements \JsonSerializable
{
	private ?string $renderTo = null;
	/** @var ViewChannel[]|null */
	private ?array $viewChannels = null;
	/** @var PromoBanner[]|null */
	private ?array $promoBanners = null;
	private bool $dynamicLoad = false;
	/** @var ContentProvider[] */
	private array $contentProviders = [];
	/** @var NotificationTemplate[] */
	private array $notificationTemplates = [];
	private ?string $messageText = null;
	private Layout $layout;
	private ?Preferences $preferences = null;
	private array $analytics = [];
	/** @var To[] */
	private array $toList = [];

	public function __construct(
		private readonly Scene $scene,
		private Context $context,
	)
	{
		$this->layout = new Layout();
	}

	public function __clone()
	{
		$this->context = clone $this->context;
		$this->layout = clone $this->layout;
		$this->notificationTemplates = Collection::clone($this->notificationTemplates);
		$this->toList = Collection::clone($this->toList);

		if ($this->preferences !== null)
		{
			$this->preferences = clone $this->preferences;
		}
	}

	public function getContext(): Context
	{
		return $this->context;
	}

	/**
	 * @return string|null
	 */
	public function getRenderTo(): ?string
	{
		return $this->renderTo;
	}

	public function setRenderTo(?string $renderTo): self
	{
		$this->renderTo = $renderTo;

		return $this;
	}

	/**
	 * @return ViewChannel[]|null
	 */
	public function getViewChannels(): ?array
	{
		return $this->viewChannels;
	}

	/**
	 * @param ViewChannel[]|null $viewChannels
	 * @return $this
	 */
	public function setViewChannels(?array $viewChannels): self
	{
		if (is_array($viewChannels))
		{
			$filtered = $this->scene->filterViewChannels($viewChannels, $this->context);
			$this->viewChannels = array_values($filtered);
		}
		else
		{
			$this->viewChannels = $viewChannels;
		}

		return $this;
	}

	/**
	 * @return PromoBanner[]|null
	 */
	public function getPromoBanners(): ?array
	{
		return $this->promoBanners;
	}

	public function setPromoBanners(?array $promoBanners): self
	{
		$this->promoBanners = $promoBanners;

		return $this;
	}

	public function getScene(): Scene
	{
		return $this->scene;
	}

	public function setDynamicLoad(bool $dynamicLoad): self
	{
		$this->dynamicLoad = $dynamicLoad;

		return $this;
	}

	public function isDynamicLoad(): bool
	{
		return $this->dynamicLoad;
	}

	/**
	 * @return ContentProvider[]
	 */
	public function getContentProviders(): array
	{
		return $this->contentProviders;
	}

	/**
	 * @param ContentProvider[] $contentProviders
	 * @return $this
	 */
	public function setContentProviders(array $contentProviders): self
	{
		$filtered = $this->scene->filterContentProviders($contentProviders);

		$this->contentProviders = [];
		foreach ($filtered as $provider)
		{
			if ($provider instanceof ContentProvider\Showable && !$provider->isShown())
			{
				continue;
			}

			$this->contentProviders[$provider->getId()] = $provider;
		}

		return $this;
	}

	/**
	 * @return NotificationTemplate[]
	 */
	public function getNotificationTemplates(): array
	{
		return $this->notificationTemplates;
	}

	/**
	 * WARNING! Don't put untrusted data in notification templates! You should fully control template codes and placeholders
	 *
	 * @param NotificationTemplate[] $notificationTemplates
	 */
	public function setNotificationTemplates(array $notificationTemplates): self
	{
		$this->notificationTemplates = array_values(
			array_filter($notificationTemplates, static fn($t): bool => $t instanceof NotificationTemplate),
		);

		return $this;
	}

	public function getMessageText(): ?string
	{
		return $this->messageText;
	}

	public function setMessageText(?string $messageText): self
	{
		$this->messageText = $messageText;

		return $this;
	}

	public function getLayout(): Layout
	{
		return $this->layout;
	}

	public function setPreferences(?Preferences $preferences): self
	{
		$this->preferences = $preferences;

		return $this;
	}

	public function getPreferences(): ?Preferences
	{
		return $this->preferences;
	}

	public function setAnalytics(array $analytics): self
	{
		$this->analytics = $analytics;

		return $this;
	}

	public function getAnalytics(): array
	{
		return $this->analytics;
	}

	public function getToList(): array
	{
		return $this->toList;
	}

	/**
	 * @param To[] $toList
	 */
	public function setToList(array $toList): self
	{
		$this->toList = array_values(
			array_filter($toList, static fn($to): bool => $to instanceof To),
		);

		return $this;
	}

	public function jsonSerialize(): array
	{
		$this->preloadNotificationTranslations();

		return [
			'scene' => $this->getScene(),
			'context' => $this->getContext(),
			'renderTo' => $this->getRenderTo(),
			'channels' => $this->getViewChannels(),
			'toList' => $this->getToList(),
			'promoBanners' => $this->getPromoBanners(),
			'dynamicLoad' => $this->isDynamicLoad(),
			'contentProviders' => $this->getContentProviders(),
			'notificationTemplates' => $this->getNotificationTemplates(),
			'message' => [
				'text' => $this->getMessageText(),
			],
			'layout' => $this->getLayout(),
			'preferences' => $this->getPreferences(),
			'analytics' => $this->getAnalytics(),
		];
	}

	private function preloadNotificationTranslations(): void
	{
		if (empty($this->notificationTemplates))
		{
			return;
		}

		$codes = array_map(
			static fn(NotificationTemplate $t): string => $t->getCode(),
			$this->notificationTemplates,
		);

		$translations = Notifications::getMultipleTemplateTranslations($codes);

		foreach ($this->notificationTemplates as $template)
		{
			$translation = $translations[$template->getCode()] ?? null;
			if ($translation !== null)
			{
				$template->setTranslation($translation);
			}
		}
	}
}
