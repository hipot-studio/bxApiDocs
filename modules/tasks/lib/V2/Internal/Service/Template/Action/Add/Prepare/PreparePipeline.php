<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

class PreparePipeline
{
	public function __construct(
		private readonly array $preparers
	)
	{

	}

	public function __invoke(array $fields): array
	{
		foreach ($this->preparers as $preparer)
		{
			if (is_string($preparer))
			{
				if (!is_subclass_of($preparer, PrepareFieldInterface::class))
				{
					continue;
				}

				$preparer = new $preparer();
			}

			if (!$preparer instanceof PrepareFieldInterface)
			{
				continue;
			}

			$fields = $preparer($fields);
		}

		return $fields;
	}
}
