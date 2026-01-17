<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Page;

use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Page;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Main\Localization\Loc;

final class Recommendations extends Page
{
	/**
	 * @inheritDoc
	 */
	public static function create(array $allSections): ?self
	{
		$recommendedSections = [];
		foreach ($allSections as $section)
		{
			$filtered = $section->filterViewChannels(static function (ViewChannel $vc): bool {
				return $vc->isPromo() || Taxonomy::isNotifications($vc->getBackend());
			});

			if (!empty($filtered->getViewChannels()))
			{
				$recommendedSections[] = $filtered;
			}
		}

		if (empty($recommendedSections))
		{
			return null;
		}

		return new self($recommendedSections);
	}

	private function __construct(
		private readonly array $sections,
	)
	{
	}

	public function getTitle(): string
	{
		return (string)Loc::getMessage('CRM_MESSAGESENDER_PAGE_RECOMMENDATIONS_TITLE');
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
		return 'recommendations';
	}
}
