<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

final class LayoutOperationError extends \RuntimeException
{
	public function __construct(public readonly string $reason, string $message)
	{
		parent::__construct($message);
	}
}
