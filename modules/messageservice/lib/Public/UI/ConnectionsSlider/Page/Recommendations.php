<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\ConnectionsSlider\Page;

use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Page;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Section\ViewChannel;
use Bitrix\MessageService\Public\UI\SenderCode;

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
				return $vc->isPromo() || $vc->getBackend()->getSenderCode() === SenderCode::BITRIX24;
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
		return (string)Loc::getMessage('MESSAGESERVICE_PAGE_RECOMMENDATIONS_TITLE_MSGVER_1');
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
