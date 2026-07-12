<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class UpdateProjectTagsCommand extends AbstractCommand
{
	/**
	 * @param string[] $tags
	 */
	public function __construct(
		#[PositiveNumber]
		public readonly int $projectId,
		public readonly array $tags,
	)
	{
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->getUpdateProjectTagsHandler();

		return $handler($this);
	}
}
