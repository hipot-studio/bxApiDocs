<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\AddAccomplicesDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\MemberService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\MemberSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class AddAccomplicesTool extends BaseTool
{
	public const ACTION_NAME = 'add_accomplices';

	public function __construct(
		private readonly MemberService $memberService,
		MemberSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Adds accomplices to the task by their user IDs.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = AddAccomplicesDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$this->memberService->addAccomplices($dto, $userId);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (NotFoundException)
		{
			return $this->createFailureResponse('The task does not exist.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}

		return "Accomplices successfully added.";
	}
}
