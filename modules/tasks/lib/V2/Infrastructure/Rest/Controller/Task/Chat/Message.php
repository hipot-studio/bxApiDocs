<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller\Task\Chat;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Controller\ValidateDtoTrait;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task\Chat\MessageDtoMapper;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\Chat\MessageDto;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\Chat\SendMessageCommand;

#[DtoType(MessageDto::class)]
class Message extends RestController
{
	use ValidateDtoTrait;

	protected ?Context $context = null;

	protected int $userId;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();
		$this->context = new Context($this->userId);

		parent::init();
	}

	public function sendAction(AddRequest $request): BooleanResponse
	{
		/** @var MessageDto $sendDto */
		$sendDto = $request->fields->getAsDto();
		if (!$this->validateDto($sendDto, 'send'))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$task = Entity\Task::mapFromId($sendDto->taskId);
		$accessProvider = new Read();
		if (!$accessProvider->check($task, $this->context))
		{
			throw new AccessDeniedException();
		}

		$mapper = new MessageDtoMapper();
		$message = $mapper->getMessageByDto($sendDto);

		try
		{
			$result = (new SendMessageCommand(
				taskId: $sendDto->taskId,
				userId: $this->userId,
				message: $message,
			))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new BooleanResponse();
	}
}
