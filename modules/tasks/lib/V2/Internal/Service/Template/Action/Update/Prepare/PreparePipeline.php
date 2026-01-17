<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

class PreparePipeline
{
	public function __construct(
		private readonly array $preparersClasses
	)
	{

	}

	public function __invoke(array $fields, array $fullTemplateData): array
	{
		foreach ($this->preparersClasses as $class)
		{
			if (!is_subclass_of($class, PrepareFieldInterface::class))
			{
				continue;
			}

			$fields = (new $class())($fields, $fullTemplateData);
		}

		return $fields;
	}
}
