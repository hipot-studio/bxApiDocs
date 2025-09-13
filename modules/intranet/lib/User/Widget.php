<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User;

use Bitrix\Intranet;
use Bitrix\Intranet\User\Widget\Content\Desktop;
use Bitrix\Intranet\User\Widget\Content\Extension;
use Bitrix\Intranet\User\Widget\Content\HcmlinkSalaryVacation;
use Bitrix\Intranet\User\Widget\Content\Main;
use Bitrix\Intranet\User\Widget\Content\PerfReview;
use Bitrix\Intranet\User\Widget\Content\SignDocuments;
use Bitrix\Intranet\User\Widget\Content\Otp;
use Bitrix\Intranet\User\Widget\Content\QrAuth;
use Bitrix\Intranet\User\Widget\Content\Theme;
use Bitrix\Intranet\User\Widget\ContentCollection;
use Bitrix\Intranet\User\Widget\Content\Logout;
use Bitrix\Main\ArgumentException;

class Widget
{
	private const CONTENT_LIST = [
		Main::class,
		SignDocuments::class,
		HcmlinkSalaryVacation::class,
		PerfReview::class,
		Desktop::class,
		QrAuth::class,
		Otp::class,
		Extension::class,
		Theme::class,
		Logout::class,
	];

	/**
	 * @throws ArgumentException
	 */
	public function getContentCollection(): ContentCollection
	{
		$contentCollection = new ContentCollection();
		$user = new Intranet\User();

		foreach (self::CONTENT_LIST as $contentClass)
		{
			$contentCollection->add(new $contentClass($user));
		}

		return $contentCollection;
	}

	public function getSkeletonConfiguration(): array
	{
		$items = [
			[
				'type' => 'item', 'height' => Main::isTimemanSectionAvailable() ? 98 : 48,
			],
		];

		if (SignDocuments::isSignDocumentAvailable())
		{
			$items[] = ['type' => 'item', 'height' => 46];
		}

		if (HcmlinkSalaryVacation::isAvailable())
		{
			$items[] = ['type' => 'item', 'height' => 40];
		}

		if (PerfReview::isAvailable())
		{
			$items[] = ['type' => 'item', 'height' => 40];
		}

		if (Otp::isAvailable())
		{
			$items[] = ['type' => 'split', 'height' => 80];
			$items[] = ['type' => 'split', 'height' => 80];
		}
		else
		{
			$items[] = ['type' => 'split', 'height' => 88];
		}

		if (Extension::isAvailable())
		{
			$items[] = ['type' => 'item', 'height' => 40];
		}

		$items[] = ['type' => 'split', 'height' => 40];

		return [
			'header' => false,
			'footer' => false,
			'items' => $items,
		];
	}
}
