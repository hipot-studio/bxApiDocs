<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\ConnectionsSlider\Page;

use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Page;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Section\ViewChannel;
use Bitrix\MessageService\Public\UI\SenderCode;
use Bitrix\MessageService\Sender\SmsManager;

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
					&& $vc->getBackend()->getSenderCode() === SenderCode::SMS_PROVIDER
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
		return (string)Loc::getMessage('MESSAGESERVICE_PAGE_SMS_TITLE');
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
