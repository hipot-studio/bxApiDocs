<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\EmbeddingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\EmbeddingSelectionDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\EmbeddingSelectionDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\EmbeddingWriteRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\PhoneVerificationRequiredException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\GetEmbeddingRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\UpdateEmbeddingRequest;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingChangeSet;
use Bitrix\Crm\V2\Internal\Exception\WebForm;
use Bitrix\Crm\V2\Internal\Service\WebForm\EmbeddingReader;
use Bitrix\Crm\V2\Internal\Service\WebForm\EmbeddingSelectionReader;
use Bitrix\Crm\V2\Internal\Service\WebForm\EmbeddingWriter;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;

#[DtoType(EmbeddingDto::class)]
final class Embedding extends RestController
{
	use TranslatesWriteRefusals;

	private ?EmbeddingReader $reader = null;
	private ?EmbeddingSelectionReader $selectionReader = null;
	private ?EmbeddingWriter $writer = null;
	private ?FormRequestMapper $requestMapper = null;
	private ?EmbeddingWriteRequestMapper $writeRequestMapper = null;

	public function __construct(
		?Request $request = null,
		?EmbeddingReader $reader = null,
		?EmbeddingWriter $writer = null,
		?EmbeddingSelectionReader $selectionReader = null,
	)
	{
		parent::__construct($request);

		$this->reader = $reader;
		$this->writer = $writer;
		$this->selectionReader = $selectionReader;
	}

	public function getAction(GetEmbeddingRequest $request): GetResponse
	{
		if (($request->variant ?? null) !== null)
		{
			return $this->getSelectedEmbedding($request);
		}

		try
		{
			$embedding = $this->getReader()->getEmbedding(
				(int)$this->getCurrentUser()->getId(),
				$request->id,
			);
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (WebForm\PhoneNotVerifiedException)
		{
			throw new PhoneVerificationRequiredException();
		}
		catch (WebForm\FormNotFoundException)
		{
			throw new FormNotFoundException($request->id);
		}

		/** @var EmbeddingDto $dto */
		$dto = $this->getDtoMapper()->mapOne($embedding);

		return new GetResponse($dto);
	}

	private function getSelectedEmbedding(GetEmbeddingRequest $request): GetResponse
	{
		try
		{
			$selection = $this->getSelectionReader()->get(
				(int)$this->getCurrentUser()->getId(),
				$request->id,
				$request->variant,
				$request->buttonText ?? null,
				$request->buttonType ?? null,
				$request->delay ?? null,
			);
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (WebForm\PhoneNotVerifiedException)
		{
			throw new PhoneVerificationRequiredException();
		}
		catch (WebForm\FormNotFoundException)
		{
			throw new FormNotFoundException($request->id);
		}
		catch (WebForm\EmbeddingSelectionException $exception)
		{
			throw new RequestValidationException([
				new Error($exception->getMessage(), $exception->fieldName),
			]);
		}

		/** @var EmbeddingSelectionDto $dto */
		$dto = (new EmbeddingSelectionDtoMapper())->mapOne($selection);

		return new GetResponse($dto);
	}

	/**
	 * `crm.form.embedding.update`: answers with the plain result flag; the settings of the variants
	 * the request does not carry stay as they are, and empty `fields` is a valid request asking for
	 * no write. A request that wrote something rebuilds the published package of the form: the
	 * package carries these settings as they were when it was built.
	 *
	 * The expected version is read before the right to write is asked about - unlike the fields
	 * ({@see mapChangeSet()}), and on purpose: it is a property of the request itself, refused the
	 * same way the framework refuses a malformed `id`, before the request reaches the operation.
	 */
	public function updateAction(UpdateEmbeddingRequest $request): UpdateResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();
		$expectedVersion = $this->getRequestMapper()->mapExpectedVersion($request->updatedTime);

		$this->runWrite($request->id, function () use ($request, $userId, $expectedVersion): void {
			$this->getWriter()->updateEmbedding(
				$userId,
				$request->id,
				$this->mapChangeSet($userId, $request->fields),
				$expectedVersion,
			);
		});

		return new UpdateResponse();
	}

	/**
	 * The change set the request describes, read once the user is known to be allowed to write.
	 *
	 * The right comes first, the input second - the order the change of the form itself is read in
	 * ({@see Form::mapChangeSet()}): read the other way round, a request of a user without the
	 * right would be answered with what is wrong with it.
	 *
	 * @throws WebForm\FormAccessDeniedException
	 * @throws WebForm\FormValidationException
	 */
	private function mapChangeSet(int $userId, FieldsStructure $fields): EmbeddingChangeSet
	{
		$this->getWriter()->assertCanEdit($userId);

		return $this->getWriteRequestMapper()->mapChangeSet($fields);
	}

	private function getReader(): EmbeddingReader
	{
		return $this->reader ??= new EmbeddingReader();
	}

	private function getSelectionReader(): EmbeddingSelectionReader
	{
		return $this->selectionReader ??= new EmbeddingSelectionReader();
	}

	private function getWriter(): EmbeddingWriter
	{
		return $this->writer ??= new EmbeddingWriter();
	}

	private function getRequestMapper(): FormRequestMapper
	{
		return $this->requestMapper ??= new FormRequestMapper();
	}

	private function getWriteRequestMapper(): EmbeddingWriteRequestMapper
	{
		return $this->writeRequestMapper ??= new EmbeddingWriteRequestMapper();
	}
}
