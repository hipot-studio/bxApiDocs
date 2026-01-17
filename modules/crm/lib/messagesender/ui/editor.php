<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;
use Bitrix\Crm\MessageSender\UI\Editor\Context;
use Bitrix\Crm\MessageSender\UI\Editor\Layout;
use Bitrix\Crm\MessageSender\UI\Editor\NotificationTemplate;
use Bitrix\Crm\MessageSender\UI\Editor\Preferences;
use Bitrix\Crm\MessageSender\UI\Editor\PromoBanner;
use Bitrix\Crm\MessageSender\UI\Editor\Scene;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ORM\Objectify\EntityObject;

final class Editor implements \JsonSerializable
{
	private ?string $renderTo = null;
	/** @var ViewChannel[]|null */
	private ?array $viewChannels = null;
	/** @var ViewChannel[]|null */
	private ?array $promoBanners = null;
	private bool $dynamicLoad = false;
	/** @var ContentProvider[]|null */
	private ?array $contentProviders = null;
	private ?NotificationTemplate $notificationTemplate = null;
	private ?string $messageText = null;
	private Layout $layout;
	private ?Preferences $preferences = null;
	private array $analytics = [];

	public function __construct(
		private readonly Scene $scene,
		private readonly Context $context,
	)
	{
		$this->layout = new Layout();
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
			$filtered = $this->scene->filterViewChannels($viewChannels);
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
	 * @return ContentProvider[]|null
	 */
	public function getContentProviders(): ?array
	{
		return $this->contentProviders;
	}

	/**
	 * @param ContentProvider[]|null $contentProviders
	 * @return $this
	 */
	public function setContentProviders(?array $contentProviders): self
	{
		if (is_array($contentProviders))
		{
			$filtered = $this->scene->filterContentProviders($contentProviders);

			$this->contentProviders = [];
			foreach ($filtered as $provider)
			{
				$this->contentProviders[$provider->getKey()] = $provider;
			}
		}
		else
		{
			$this->contentProviders = $contentProviders;
		}

		return $this;
	}

	public function getNotificationTemplate(): ?NotificationTemplate
	{
		return $this->notificationTemplate;
	}

	/**
	 * WARNING! Don't put untrusted data in notification template! You should fully control template code and placeholders
	 */
	public function setNotificationTemplate(?NotificationTemplate $notificationTemplate): self
	{
		$this->notificationTemplate = $notificationTemplate;

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

	public function jsonSerialize(): array
	{
		return [
			'scene' => $this->getScene(),
			'context' => $this->getContext(),
			'renderTo' => $this->getRenderTo(),
			'channels' => $this->jsonSerializeViewChannels(),
			'promoBanners' => $this->getPromoBanners(),
			'dynamicLoad' => $this->isDynamicLoad(),
			'contentProviders' => $this->getContentProviders(),
			'notificationTemplate' => $this->getNotificationTemplate(),
			'message' => [
				'text' => $this->getMessageText(),
			],
			'layout' => $this->getLayout(),
			'preferences' => $this->getPreferences(),
			'analytics' => $this->getAnalytics(),
		];
	}

	// todo configure in channel repo to fetch address source data optionally and move it there
	private function jsonSerializeViewChannels(): ?array
	{
		if (empty($this->getViewChannels()))
		{
			return $this->getViewChannels();
		}

		$addressSourcesData = $this->fetchAddressSourcesData();

		$viewChannelsJson = $this->jsonSerializeRecursive($this->getViewChannels());

		foreach ($viewChannelsJson as &$singleChannelJson)
		{
			foreach ($singleChannelJson['toList'] as &$to)
			{
				$addressSource = ItemIdentifier::createFromArray($to['addressSource']);
				if (!$addressSource)
				{
					continue;
				}

				$data = $addressSourcesData[$addressSource->getEntityTypeId()][$addressSource->getEntityId()] ?? null;
				$to['addressSourceData'] = $data;
			}
		}

		return $viewChannelsJson;
	}

	private function fetchAddressSourcesData(): array
	{
		$addressSources = [];
		foreach ($this->getViewChannels() as $viewChannel)
		{
			foreach ($viewChannel->getToList() as $to)
			{
				$source = $to->getAddressSource();
				$addressSources[$source->getEntityTypeId()][$source->getEntityId()] = $source->getEntityId();
			}
		}

		$addressSourcesData = [];
		foreach ($addressSources as $entityTypeId => $entityIds)
		{
			$broker = Container::getInstance()->getEntityBroker($entityTypeId);
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if (!$broker || !$factory || !\CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
			{
				continue;
			}

			foreach ($broker->getBunchByIds($entityIds) as $item)
			{
				if ($item instanceof EntityObject)
				{
					$item = $factory->getItemByEntityObject($item);
				}
				if (!($item instanceof Item))
				{
					continue;
				}

				$addressSourcesData[$entityTypeId][$item->getId()] = [
					'title' => $item->getHeading(),
				];
			}
		}

		return $addressSourcesData;
	}

	private function jsonSerializeRecursive(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map($this->jsonSerializeRecursive(...), $value);
		}

		if ($value instanceof \JsonSerializable)
		{
			$jsonValue = $value->jsonSerialize();
			return $this->jsonSerializeRecursive($jsonValue);
		}

		return $value;
	}
}
