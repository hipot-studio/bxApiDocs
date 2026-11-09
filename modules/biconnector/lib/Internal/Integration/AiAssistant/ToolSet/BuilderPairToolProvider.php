<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\ToolDescriptionDto;
use Bitrix\AiAssistant\Definition\Tool\Contract\SubjectAwareToolContract;
use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\ObjectNotFoundException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BaseBiTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BaseSubjectAwareBiTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BiArrayExecutor;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\Main\DI\ServiceLocator;

trait BuilderPairToolProvider
{
	public function getRegistryToolNames(): array
	{
		return [];
	}

	public function getProviderToolDefinitionsGenerator(
		int $userId,
		?RequestSubjectDto $subject = null,
	): \Generator
	{
		yield from [];
	}

	public function getProviderToolDescription(
		string $toolName,
		int $userId,
		?RequestSubjectDto $subject = null,
	): ?ToolDescriptionDto
	{
		$target = $this->getBuilderPairTarget($toolName);
		if ($target === null)
		{
			return null;
		}

		$canList = $target instanceof BaseSubjectAwareBiTool
			? $target->canList($userId, $subject)
			: $target->canList($userId);

		return $canList ? $target->getFullDescription() : null;
	}

	public function getProviderToolExecutor(
		string $toolName,
		int $userId,
		?RequestSubjectDto $subject = null,
	): ToolContract|SubjectAwareToolContract
	{
		$target = $this->getBuilderPairTarget($toolName);
		if ($target === null)
		{
			throw new ObjectNotFoundException('Builder tool not found');
		}

		return new BiArrayExecutor(ServiceLocator::getInstance()->get(TracedLogger::class), $target);
	}

	private function getBuilderPairTarget(string $toolName): BaseBiTool|BaseSubjectAwareBiTool|null
	{
		if (BuilderArgumentContract::methodForTool($toolName) === null)
		{
			return null;
		}

		$class = $this->getToolMap()[$toolName] ?? null;
		if ($class === null)
		{
			return null;
		}

		$target = ServiceLocator::getInstance()->get($class);

		return $target instanceof BaseBiTool || $target instanceof BaseSubjectAwareBiTool ? $target : null;
	}
}
