<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content\Tool;

use Bitrix\Intranet\User;
use Bitrix\Intranet\Internal\Service;
use Bitrix\Main\Localization\Loc;

class AnnualSummary extends BaseTool
{
	public static function isAvailable(User $user): bool
	{
		return (new Service\AnnualSummary\Visibility($user->getId()))->canShow();
	}

	public function getConfiguration(): array
	{
		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_ANNUAL_SUMMARY_TITLE'),
		];
	}

	public function getName(): string
	{
		return 'annualSummary';
	}
}
