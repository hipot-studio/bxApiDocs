<?php

namespace Bitrix\Im\V2\Message\Delete\Strategy;

class NoneDeletionStrategy extends DeletionStrategy
{
	protected function execute(): void {}

	protected function onBeforeDelete(): void {}

	protected function onAfterDelete(): void {}
}