<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Mobile\Internal\Services\BannerService;

class Banner extends Controller
{
	/**
	 * @restMethod mobile.Banner.getStatus
	 */
	public function getStatusAction(string $code): array
	{
		return [
			'isVisible' => $this->getService()->isVisible($code),
		];
	}

	/**
	 * @restMethod mobile.Banner.dismiss
	 */
	public function dismissAction(string $code): void
	{
		$this->getService()->dismiss($code);
	}

	private function getService(): BannerService
	{
		return new BannerService();
	}
}
