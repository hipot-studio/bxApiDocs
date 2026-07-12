<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Scrum;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;

class UpdateScrumTagsCommand extends AbstractCommand
{
	/**
	 * @param string[] $tags
	 */
	public function __construct(
		#[PositiveNumber]
		public readonly int $scrumId,
		public readonly array $tags,
	)
	{
	}

	protected function execute(): Result
	{
		$handler = new UpdateScrumTagsHandler();

		return $handler($this);
	}
}
