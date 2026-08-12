<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider\Showable;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;

Loader::requireModule('messageservice');

abstract class BaseContentProvider extends ContentProvider implements Showable
{
	public function __construct(
		private readonly Context $context,
	)
	{
	}

	final protected function getEntityTypeId(): ?int
	{
		return $this->context->getCustomDataInt('entityTypeId');
	}

	final protected function getEntityId(): ?int
	{
		return $this->context->getCustomDataInt('entityId');
	}

	final protected function getCategoryId(): ?int
	{
		return $this->context->getCustomDataInt('categoryId');
	}
}
