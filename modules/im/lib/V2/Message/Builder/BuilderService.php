<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder;

use Bitrix\Im\V2\Message\Builder\Factory\BuilderFactory;
use Bitrix\Im\V2\Message\Builder\Validation\ValidationService;
use Bitrix\Main\Localization\Loc;

class BuilderService
{
	public function __construct(
		protected readonly BuilderFactory $builderFactory,
		protected readonly ValidationService $validationService,
	)
	{}

	public function create(array $builderData): BuilderResult
	{
		$builderResult = new BuilderResult();

		$result = $this->validationService->validate($builderData);
		if (!$result->isSuccess())
		{
			return $builderResult->addError($result->getError());
		}

		$builder = $this->builderFactory->create($result->getResult());

		return $builderResult->setBuilder($builder);
	}

	public static function getPlaceholder(): string
	{
		return Loc::getMessage('IM_MESSAGE_BUILDER_PLACEHOLDER') ?? '';
	}
}
