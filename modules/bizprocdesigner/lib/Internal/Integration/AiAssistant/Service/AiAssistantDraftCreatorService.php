<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\BizprocDesignerPullManager;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\BizprocDesignerPullEvent;
use Bitrix\BizprocDesigner\Internal\Service\Container;

class AiAssistantDraftCreatorService
{
	private readonly BizprocDesignerPullManager $pullManager;

	public function __construct(
		?BizprocDesignerPullManager $pullManager = null,
	)
	{
		$this->pullManager = $pullManager ?? Container::getPullManager();
	}

	public function pushDraft(Draft $draft): bool
	{
		if (!$draft->userId)
		{
			return false;
		}

		return $this->pullManager->sendEvent(
			$draft->userId,
			BizprocDesignerPullEvent::AiDraftUpdated,
			$draft->toArray(),
		);
	}
}
