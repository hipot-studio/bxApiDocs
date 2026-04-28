<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Entities\Application\Scope;

use Bitrix\Market\Internal\Integration\Ui\CopilotNameProxy;

class AiAdminScope extends BasicScope
{
	public function getTitle(): string
	{
		if ($this->title === null)
		{
			$this->title = (new CopilotNameProxy())->getCopilotName();
		}

		return $this->title ?? parent::getTitle();
	}
}
