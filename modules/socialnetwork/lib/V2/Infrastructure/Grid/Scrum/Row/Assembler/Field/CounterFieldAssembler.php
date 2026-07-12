<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler\Field;

use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Public\Provider\Grid\ScrumCounterProvider;

class CounterFieldAssembler extends Shared\Row\Assembler\Field\CounterFieldAssembler
{
	public function __construct(array $columnIds, int $userId)
	{
		$container = Container::getInstance();

		parent::__construct(
			$columnIds,
			$container->get(ScrumCounterProvider::class),
			$container->get(Shared\Counter\SingleCounterFormatter::class),
			$userId,
		);
	}
}
