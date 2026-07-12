<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\AddAuditorsDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\MemberService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\MemberSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Service\TariffService;

class AddAuditorsTool extends BaseTool
{
	public const ACTION_NAME = 'add_auditors';

	public function __construct(
		private readonly TariffService $tariffService,
		private readonly MemberService $memberService,
		ToolService $toolService,
		MemberSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Adds auditors to the task by their user IDs.';
	}

	public function canRun(int $userId): bool
	{
		parent::canRun($userId);

		if (!$this->tariffService->isStakeholderAvailable())
		{
			throw new McpException(
				'The Observers and Participants feature is not available on the current Bitrix24 plan, '
				. 'so auditors cannot be added to a task. Upgrade to a suitable Bitrix24 plan to enable this',
			);
		}

		return true;
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = AddAuditorsDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$this->memberService->addAuditors($dto, $userId);
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

		return 'Auditors successfully added.';
	}
}
