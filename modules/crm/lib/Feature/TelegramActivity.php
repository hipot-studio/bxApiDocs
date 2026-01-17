<?php

declare(strict_types=1);

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\Activities;
use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Main\Localization\Loc;

final class TelegramActivity extends BaseFeature
{
	public function isEnabled(): bool
	{
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_TELEGRAM_ACTIVITY_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Activities::getInstance();
	}
}
