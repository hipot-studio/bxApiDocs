<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Message\BlocksBuilder\Factory\BuilderFactory;
use Bitrix\Im\V2\Message\BlocksBuilder\Validation\ValidationService;
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

		if (!Features::isMessageBuilderAvailable())
		{
			return $builderResult->addError(new BuilderError(BuilderError::BUILDER_NOT_AVAILABLE));
		}

		$result = $this->validationService->validate($builderData);
		if (!$result->isSuccess())
		{
			return $builderResult->addError($result->getError());
		}

		$builder = $this->builderFactory->create($result->getResult());

		return $builderResult->setBlocksBuilder($builder);
	}

	public static function getPlaceholder(): string
	{
		return Loc::getMessage('IM_MESSAGE_BUILDER_PLACEHOLDER') ?? '';
	}
}
