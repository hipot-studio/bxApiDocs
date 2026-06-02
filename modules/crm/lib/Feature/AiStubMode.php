<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Experimental;
use Bitrix\Main\Localization\Loc;

final class AiStubMode extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('AI_STUB_MODE_FEATURE_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Experimental::getInstance();
	}

	protected function getOptionName(): string
	{
		return 'dev_ai_stub_mode';
	}

	protected function getEnabledValue(): mixed
	{
		return 'Y';
	}

	protected function getDisabledValue(): mixed
	{
		return 'N';
	}
}
