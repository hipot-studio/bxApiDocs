<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User;

use Bitrix\Intranet;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Intranet\User\Widget\Content\Main;
use Bitrix\Intranet\User\Widget\Content\Tool;
use Bitrix\Intranet\User\Widget\Content\Tool\PerformanUserProfile;
use Bitrix\Intranet\User\Widget\Content\ToolsContentAssembler;
use Bitrix\Intranet\User\Widget\Content\Tool\AccountChanger;
use Bitrix\Intranet\User\Widget\Content\Tool\Administration;
use Bitrix\Intranet\User\Widget\Content\Tool\Theme;
use Bitrix\Intranet\User\Widget\ContentCollection;
use Bitrix\Main\ArgumentException;

class Widget
{
	private Intranet\User $user;
	private const SKELETON_ITEM_HEIGHT = 36;

	public function __construct()
	{
		$this->user = new Intranet\User();
	}

	/**
	 * @throws ArgumentException
	 */
	public function getContentCollection(): ContentCollection
	{
		$contentCollection = new ContentCollection();
		$contentClasses = [
			Main::class,
		];

		/**
		 * @var class-string<BaseContent> $contentClass
		 */
		foreach ($contentClasses as $contentClass)
		{
			if ($contentClass::isAvailable())
			{
				$contentCollection->add(new $contentClass($this->user));
			}
		}

		$toolsSectionBuilder = new ToolsContentAssembler($this->user);
		$toolsSectionBuilder->addToContentCollection($contentCollection);

		return $contentCollection;
	}

	public function getSkeletonConfiguration(): array
	{
		$items = [];
		$visibilityService = new Intranet\Internal\Service\AnnualSummary\Visibility($this->user->getId());
		if ($visibilityService->canShow())
		{
			$items[] = ['type' => 'item', 'height' => self::SKELETON_ITEM_HEIGHT];
		}

		$items[] = ['type' => 'splitColumn', 'height' => 72, 'count' => 2];

		$toolsCount = 0;

		if ($this->user->isIntranet())
		{
			/** @var Tool\BaseTool[] $tools */
			$tools = [
				Tool\MyDocuments::class,
				Tool\Security::class,
				Tool\SalaryVacation::class,
				Tool\Extension::class,
			];

			foreach ($tools as $tool)
			{
				if ($tool::isAvailable($this->user))
				{
					$toolsCount++;
				}
			}
		}

		$secondaryToolsCount = 0;

		if (Theme::isAvailable($this->user))
		{
			$secondaryToolsCount++;
		}

		if (AccountChanger::isAvailable($this->user))
		{
			$secondaryToolsCount++;
		}

		if (Administration::isAvailable($this->user))
		{
			$secondaryToolsCount++;
		}

		if (PerformanUserProfile::isAvailable($this->user))
		{
			$secondaryToolsCount++;
		}

		if ($secondaryToolsCount === 1)
		{
			$items[] = ['type' => 'item', 'height' => self::SKELETON_ITEM_HEIGHT];
		}
		elseif ($secondaryToolsCount > 1)
		{
			$items[] = ['type' => 'splitColumn', 'height' => self::SKELETON_ITEM_HEIGHT * $secondaryToolsCount, 'count' => $secondaryToolsCount];
		}

		if (!$this->user->isIntranet() && $this->user->isExtranet())
		{
			$items[] = ['type' => 'splitColumn', 'height' => self::SKELETON_ITEM_HEIGHT * 2, 'count' => 2];
		}

		$hasTimeman = Intranet\Internal\Integration\Timeman\WorkTime::canUse();

		return [
			'header' => false,
			'avatarWidgetHeader' => ['hasTimeman' => $hasTimeman, 'toolsCount' => $toolsCount, 'isAdmin' => $this->user->isAdmin()],
			'footer' => ['height' => 10],
			'items' => $items,
		];
	}
}
