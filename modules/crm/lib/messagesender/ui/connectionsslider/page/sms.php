<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Page;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Page;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Main\Localization\Loc;

final class Sms extends Page
{
	/**
	 * @inheritDoc
	 */
	public static function create(array $allSections): ?self
	{
		$sections = [];
		foreach ($allSections as $section)
		{
			$filtered = $section->filterViewChannels(static function (ViewChannel $vc): bool {
				return !$vc->isPromo()
					&& Taxonomy::isSmsSender($vc->getBackend())
					&& SmsManager::getSenderById($vc->getBackend()->getId())?->isConfigurable() === true;
			});

			if (!empty($filtered->getViewChannels()))
			{
				$sections[] = $filtered;
			}
		}

		if (empty($sections))
		{
			return null;
		}

		return new self($sections);
	}

	private function __construct(
		private readonly array $sections,
	)
	{
	}

	public function getTitle(): string
	{
		return (string)Loc::getMessage('CRM_MESSAGESENDER_PAGE_SMS_TITLE');
	}

	/**
	 * @inheritDoc
	 */
	public function getSections(): array
	{
		return $this->sections;
	}

	public function getId(): string
	{
		return 'sms';
	}
}
