<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Im\V2\Message\BlocksBuilder\Factory\BuilderFactory;
use Bitrix\Im\V2\Message\BlocksBuilder\Validation\ValidationService;
use Bitrix\Main\Localization\Loc;

class BuilderService
{
	protected const MAX_JSON_LENGTH = 60000;

	public function __construct(
		protected readonly BuilderFactory $builderFactory,
		protected readonly ValidationService $validationService,
	)
	{}

	public function create(array $builderData, Chat $chat): BuilderResult
	{
		$builderResult = new BuilderResult();

		$result = $this->validationService->validateNew($builderData, $chat);
		if (!$result->isSuccess())
		{
			return $builderResult->addError($result->getError());
		}

		$builder = $this->builderFactory->create($result->getResult());
		if (mb_strlen($builder->getJson()) >= self::MAX_JSON_LENGTH)
		{
			return $builderResult->addError(new BuilderError(BuilderError::BLOCK_LENGTH_EXCEEDED));
		}

		return $builderResult->setBlocksBuilder($builder);
	}

	public function get(array $builderData): BuilderResult
	{
		$builderResult = new BuilderResult();

		$result = $this->validationService->validateExisting($builderData);
		if (!$result->isSuccess())
		{
			return $builderResult->addError($result->getError());
		}

		$builder = $this->builderFactory->create($result->getResult());
		if (mb_strlen($builder->getJson()) >= self::MAX_JSON_LENGTH)
		{
			return $builderResult->addError(new BuilderError(BuilderError::BLOCK_LENGTH_EXCEEDED));
		}

		return $builderResult->setBlocksBuilder($builder);
	}

	public function updateFileIds(BlocksBuilder $builder, array $fileIds, Chat $chat): BuilderResult
	{
		$builderData = $builder->toArray();

		foreach ($builderData[Field::Elements->value] as $key => $element)
		{
			if ($element[Field::Type->value] === BlockType::Gallery->value)
			{
				$builderData[Field::Elements->value][$key][Field::FileIds->value] = $fileIds;
			}
		}

		return $this->create($builderData, $chat);
	}

	public static function getPlaceholder(): string
	{
		return Loc::getMessage('IM_MESSAGE_BUILDER_PLACEHOLDER') ?? '';
	}
}
