<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Application\Navigation;

use Bitrix\Im\V2\Common\Event\BaseEvent;

class NavigationMenuBuildEvent extends BaseEvent
{
	public function __construct(MenuItemCollection $collection)
	{
		parent::__construct('OnAfterNavigationMenuBuild', ['collection' => $collection]);
	}

	public function getCollection(): MenuItemCollection
	{
		return $this->parameters['collection'];
	}
}
