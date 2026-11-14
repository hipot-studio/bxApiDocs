<?php

namespace Bitrix\Mobile\AvaMenu\Items;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Mobile\AvaMenu\AbstractMenuItem;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Tab\Preset\Service\ActiveTabProvider;
use Bitrix\SignMobile\Config\Feature;

class Signing extends AbstractMenuItem
{
	public function __construct(
		Context $context,
		private readonly ActiveTabProvider $activeTabProvider,
	)
	{
		parent::__construct($context);
	}

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	public function isAvailable(): bool
	{
		$activeTabs = $this->activeTabProvider->getActiveTabs();

		if (isset($activeTabs['sign']))
		{
			return false;
		}

		return
			Loader::includeModule('signmobile')
			&& class_exists(Feature::class)
			&& method_exists(Feature::class, 'isMyDocumentsGridAvailable')
			&& Feature::instance()->isMyDocumentsGridAvailable();
	}

	public function getData(): array
	{
		return [
			'id' => $this->getId(),
			'iconName' => $this->getIconId(),
			'customData' => $this->getEntryParams(),
		];
	}

	public function getId(): string
	{
		return 'start_signing';
	}

	public function getIconId(): string
	{
		return 'sign';
	}

	private function getEntryParams(): array
	{
		return [
			'showHint' => false,
		];
	}
}
