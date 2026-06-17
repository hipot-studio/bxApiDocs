<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\ToolExecutionNotImplementedException;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Exception\BadToolDtoClassException;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Exception\ToolExecuteException;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Throwable;

Loader::requireModule('aiassistant');

abstract class AbstractTool extends ToolContract
{
	public function getName(): string
	{
		return $this->getDefinition()->getName();
	}

	public function getDescription(): string
	{
		return $this->getDefinition()->getDescription();
	}

	public function getInputSchema(): array
	{
		return $this->getDefinition()->getInputScheme();
	}

	abstract protected function getDefinition(): ToolDefinition;

	/**
	 * @throws ToolExecutionNotImplementedException
	 */
	final protected function execute(int $userId, ...$args): string
	{
		return parent::execute($userId, ...$args);
	}

	/**
	 * @throws SystemException
	 */
	final protected function executeStructured(int $userId, ...$args): array
	{
		$argsDtoClass = $this->getArgsDtoClass();
		if (!is_subclass_of($argsDtoClass, AbstractToolDto::class))
		{
			throw new BadToolDtoClassException(
				message: strtr(
					'Tool {tool} expects DTO class to be a subclass of {expected}, but {actual} was given.',
					[
						'{tool}' => static::class,
						'{expected}' => AbstractToolDto::class,
						'{actual}' => $argsDtoClass,
					]
				)
			);
		}

		try {
			$argsDto = new $argsDtoClass($userId, $args);
		}
		catch (Throwable $e)
		{
			throw new BadToolDtoClassException(
				message: strtr(
					'Failed to instantiate DTO {dtoClass} for tool {tool}. Original error: {error}',
					[
						'{tool}' => static::class,
						'{dtoClass}' => $argsDtoClass,
						'{error}' => $e->getMessage(),
					]
				),
				previous: $e,
			);
		}

		if ($argsDto->hasValidationErrors())
		{
			throw ToolExecuteException::createFromErrors($this, $argsDto->getValidationErrors());
		}

		$result = $this->internalExecute($argsDto);
		if (!$result->isSuccess())
		{
			throw new ToolExecuteException($this, $result);
		}

		return $result->getData();
	}

	abstract protected function internalExecute(AbstractToolDto $args): ToolResult;

	abstract protected function getArgsDtoClass(): string;
}
