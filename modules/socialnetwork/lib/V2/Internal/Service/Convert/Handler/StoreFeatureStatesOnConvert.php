<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureStatesOnConvertService;

class StoreFeatureStatesOnConvert implements HandlerInterface
{
	public function __invoke(Workgroup $group): void
	{
		$this->getFeatureStatesOnConvertService()->storeCurrentStates($group->getId());
	}

	protected function getFeatureStatesOnConvertService(): FeatureStatesOnConvertService
	{
		return Container::getInstance()->getFeatureStatesOnConvertService();
	}
}
