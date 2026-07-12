<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\Factory;

use Bitrix\Socialnetwork\V2\Internal\Exceptions\UnknownHandlerException;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\HandlerInterface;

interface HandlerFactoryInterface
{
	/**
	 * @throws UnknownHandlerException
	 */
	public function create(string $class): HandlerInterface;
}