<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class BitrixAnswersFeature extends FeatureFlag
{
	use MyCompanySectionElement;

	private const OPTION_CODE = 'feature_bitrix_answers_enabled';

	protected function isOptionEnabled(): bool
	{
		return $this->isOptionOn('mobile', self::OPTION_CODE, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}
}
