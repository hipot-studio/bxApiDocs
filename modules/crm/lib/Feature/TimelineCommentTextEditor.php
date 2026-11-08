<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\Activities;
use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Main\Localization\Loc;

final class TimelineCommentTextEditor extends AbstractCrmFlag
{
	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_FEATURE_TIMELINE_COMMENT_TEXT_EDITOR_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Activities::getInstance();
	}

	public function enabledByDefault(): bool
	{
		return false;
	}
}
