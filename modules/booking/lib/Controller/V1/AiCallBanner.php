<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Service\Notifications\AiCallMassSwitcher;
use Bitrix\Booking\Internals\Service\Promotion\AiCallBanner\AiCallBannerService;
use Bitrix\Main\Engine\CurrentUser;

class AiCallBanner extends BaseController
{
	private int $userId;
	private AiCallBannerService $aiCallBannerService;
	private AiCallMassSwitcher $aiCallMassSwitcher;

	protected function init()
	{
		parent::init();

		$this->userId = (int)CurrentUser::get()->getId();
		$this->aiCallBannerService = Container::getAiCallBannerService();
		$this->aiCallMassSwitcher = Container::getAiCallMassSwitcher();
	}

	public function registerShownAction(): array|null
	{
		try
		{
			$this->aiCallBannerService->registerShown($this->userId);

			return [];
		}
		catch (Exception $e)
		{
			$this->addError(ErrorBuilder::buildFromException($e));

			return null;
		}
	}

	public function switchAllToAiCallAction(): array|null
	{
		try
		{
			$result = $this->aiCallMassSwitcher->switchAllToAiCall();
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}

			return [];
		}
		catch (Exception $e)
		{
			$this->addError(ErrorBuilder::buildFromException($e));

			return null;
		}
	}
}
