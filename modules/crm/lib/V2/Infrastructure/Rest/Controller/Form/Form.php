<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\FormDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormWriteRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ActivateFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\DeactivateFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\DeleteFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\GetFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ListFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\UpdateFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ValidateFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Response\Form\FormStateResponse;
use Bitrix\Crm\V2\Infrastructure\Rest\Response\Validation\ValidationResponse;
use Bitrix\Crm\V2\Internal\Entity;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormWriteContext;
use Bitrix\Crm\V2\Internal\Exception\WebForm;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormActivator;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormChecker;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormReader;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormStorageRefusal;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormWriter;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use UnexpectedValueException;

#[DtoType(FormDto::class)]
final class Form extends RestController
{
	use TranslatesWriteRefusals;

	private const LOGGER_ID = 'Default';

	private ?FormReader $reader = null;
	private ?FormWriter $writer = null;
	private ?FormChecker $checker = null;
	private ?FormActivator $activator = null;
	private ?FormRequestMapper $requestMapper = null;
	private ?FormWriteRequestMapper $writeRequestMapper = null;

	public function getAction(GetFormRequest $request): GetResponse
	{
		$requestMapper = $this->getRequestMapper();
		$select = $requestMapper->mapSelect($request);

		try
		{
			$form = $this->getReader()->get(
				(int)$this->getCurrentUser()->getId(),
				$request->id,
				$select,
			);
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (WebForm\FormNotFoundException)
		{
			throw new FormNotFoundException($request->id);
		}

		/** @var FormDto $dto */
		$dto = $this->getDtoMapper()->mapCollection(
			[$form],
			$requestMapper->mapStructuredFields($select),
		)->first();

		return new GetResponse($dto);
	}

	public function listAction(ListFormRequest $request): ListResponse
	{
		$requestMapper = $this->getRequestMapper();
		$select = $requestMapper->mapSelect($request);
		$pager = $requestMapper->mapPager($request->pagination);

		try
		{
			$forms = $this->getReader()->list(
				(int)$this->getCurrentUser()->getId(),
				$requestMapper->mapFilter($request->filter),
				$requestMapper->mapOrder($request->order),
				$pager,
				$select,
			);
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		return new ListResponse(
			$this->getDtoMapper()->mapCollection(
				$forms->getAll(),
				$requestMapper->mapStructuredFields($select),
			),
		);
	}

	/**
	 * `crm.form.add`: answers with the id of the created form.
	 *
	 * The standard request of the framework carries what a creation takes: `fields` is demanded
	 * rather than optional, because a form is made of what it carries and a creation with nothing in
	 * it says nothing. Which of the published fields may be written here is the business of
	 * {@see FormWriteRequestMapper}, answering for the context this action stands in: a creation
	 * carries the activity of the form and a change does not. Which of them a creation cannot do
	 * without - the name and the CRM scheme - is the business of the domain validation, the only
	 * side that knows what a form already holds.
	 *
	 * A form carrying no activity of its own is created working: that is what a form is made for,
	 * and the client that wants it otherwise says so.
	 */
	public function addAction(AddRequest $request): AddResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();

		$formId = $this->runWrite(null, fn (): int => $this->getWriter()->add(
			$userId,
			$this->mapChangeSet($userId, $request->fields, WebFormWriteContext::Creation),
		));

		return new AddResponse($formId);
	}

	/**
	 * `crm.form.update`: answers with the plain result flag; what the request did not carry stays
	 * as it is, and empty `fields` is a valid request asking for no write.
	 *
	 * The expected version is read before the right to write is asked about - unlike the fields
	 * ({@see mapChangeSet()}), and on purpose: it is a property of the request itself, refused the
	 * same way the framework refuses a malformed `id`, before the request reaches the operation.
	 *
	 * The activity of the form is not part of the document a change carries: a stored form is
	 * started and stopped by `crm.form.activate` and `crm.form.deactivate`, so a document naming it
	 * here is refused as the write of a property published for reading.
	 */
	public function updateAction(UpdateFormRequest $request): UpdateResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();
		$expectedVersion = $this->getRequestMapper()->mapExpectedVersion($request->updatedTime);

		$this->runWrite($request->id, function () use ($request, $userId, $expectedVersion): void {
			$this->getWriter()->update(
				$userId,
				$request->id,
				$this->mapChangeSet($userId, $request->fields, WebFormWriteContext::Change),
				$expectedVersion,
			);
		});

		return new UpdateResponse();
	}

	/**
	 * `crm.form.delete`: answers with the plain result flag; a repeated delete answers as an absent
	 * form does.
	 *
	 * The expected version is read here the way the change reads it, and by the same mapper: two
	 * methods carrying one property of the contract answer a malformed value with one refusal. It is
	 * optional here, so a request carrying none asks for a removal that compares no versions, and
	 * the confirmation of the removal travels as the request carries it - what it means for a
	 * working form is the business of the scenario.
	 */
	public function deleteAction(DeleteFormRequest $request): DeleteResponse
	{
		$expectedVersion = $request->updatedTime === null
			? null
			: $this->getRequestMapper()->mapExpectedVersion($request->updatedTime)
		;

		$this->runWrite($request->id, function () use ($request, $expectedVersion): void {
			$this->getWriter()->delete(
				(int)$this->getCurrentUser()->getId(),
				$request->id,
				$expectedVersion,
				$request->confirmed,
			);
		});

		return new DeleteResponse();
	}

	/**
	 * `crm.form.validate`: answers whether a write would take the form, and what it would object to.
	 *
	 * Nothing is written and no version moves, yet the refusals travel through {@see runWrite()}
	 * with those of the writes. A check is a dry run of a write and raises the refusals of one - no
	 * right to change forms, no such form, a document breaking the write contract - and a refusal
	 * means the same wherever it was raised. A table of its own here would let one refusal reach a
	 * client as two different answers, which is what that single table exists to prevent.
	 *
	 * Violations of the rules are not refusals and do not come through there: they are the answer
	 * itself, carried in the result and put into the response whatever the verdict.
	 *
	 * The write the document is read as is the write it is a dry run of: a document naming no form
	 * is the document of a creation that has not happened yet, and one sent on top of a stored form
	 * is the document of a change. Read in the other context, the check would take what the write it
	 * stands for refuses, or refuse what that write takes.
	 */
	public function validateAction(ValidateFormRequest $request): ValidationResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();
		$context = $request->id === null ? WebFormWriteContext::Creation : WebFormWriteContext::Change;

		$verdict = $this->runWrite($request->id, fn (): Result => $this->getChecker()->check(
			$userId,
			$request->id,
			$request->fields === null ? null : $this->mapChangeSet($userId, $request->fields, $context),
		));

		return new ValidationResponse(...$verdict->getErrors());
	}

	/**
	 * `crm.form.activate`: makes the form work and answers with the state it is in afterwards.
	 *
	 * A form is turned on only while a write of its own configuration would be taken and only while
	 * the plan of the portal has room for one more working form; both are the business of the
	 * scenario, and both reach the client as the refusals of a write do.
	 */
	public function activateAction(ActivateFormRequest $request): FormStateResponse
	{
		return $this->switchActivity($request->id, true, $request->updatedTime);
	}

	/**
	 * `crm.form.deactivate`: stops the form and answers with the state it is in afterwards.
	 *
	 * Neither fitness nor the limit is asked about here: stopping a form grows neither the number of
	 * working forms nor what the visitors of a page are shown, so a form that may no longer be
	 * turned on can still be turned off.
	 */
	public function deactivateAction(DeactivateFormRequest $request): FormStateResponse
	{
		return $this->switchActivity($request->id, false, $request->updatedTime);
	}

	/**
	 * The one body of both switches: the two actions differ in the state they ask for and in nothing
	 * else. What each direction takes is decided by the scenario, which owns the operation, so a
	 * second body here would be a second place for those rules to drift apart in.
	 *
	 * The expected version is read the way the change and the removal read it, and by the same
	 * mapper: every method of the family carrying that property answers a malformed value with one
	 * refusal. It is optional here, so a request carrying none switches the form comparing no
	 * versions.
	 *
	 * The projection the scenario answers with is read rather than assumed: it carries the state and
	 * the version by contract, and one that lost either would otherwise answer the client with a
	 * state that is not the state of the form.
	 */
	private function switchActivity(int $formId, bool $isActive, ?string $updatedTime): FormStateResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();
		$expectedVersion = $updatedTime === null
			? null
			: $this->getRequestMapper()->mapExpectedVersion($updatedTime)
		;

		$state = $this->runWrite(
			$formId,
			fn (): Entity\WebForm\WebForm => $this->getActivator()->setActive(
				$userId,
				$formId,
				$isActive,
				$expectedVersion,
			),
		);

		if ($state->active === null || !$state->modifiedTime instanceof DateTime)
		{
			throw new UnexpectedValueException(
				sprintf('Activity of CRM form `%d` was switched and answered no state or no version.', $formId),
			);
		}

		return new FormStateResponse($state->active, $state->modifiedTime);
	}

	/**
	 * The change set the request describes, read once the user is known to be allowed to write.
	 *
	 * The right comes first, the input second. Read the other way round, a request of a user
	 * without the right would be answered with what is wrong with it - the paths, the types, the
	 * properties of a contract they may not use at all.
	 *
	 * @throws WebForm\FormAccessDeniedException
	 * @throws WebForm\FormValidationException
	 */
	private function mapChangeSet(
		int $userId,
		FieldsStructure $fields,
		WebFormWriteContext $context,
	): WebFormChangeSet
	{
		$this->getWriter()->assertCanEdit($userId);

		return $this->getWriteRequestMapper()->mapChangeSet($fields, $context);
	}

	private function getReader(): FormReader
	{
		return $this->reader ??= new FormReader();
	}

	private function getWriter(): FormWriter
	{
		return $this->writer ??= new FormWriter(permissionChecker: self::permissionChecker());
	}

	/**
	 * The dry run of a write, assembled here rather than by itself: the scenario answers about a
	 * user, and who that user is and where the refusals of the portal are written down are known to
	 * this side alone.
	 */
	private function getChecker(): FormChecker
	{
		return $this->checker ??= new FormChecker(self::permissionChecker());
	}

	/**
	 * The switch of the activity, given the very check `crm.form.validate` is answered by: one
	 * verdict over one stored form, because both are the same object here.
	 */
	private function getActivator(): FormActivator
	{
		$checker = $this->getChecker();

		return $this->activator ??= new FormActivator(
			permissionChecker: self::permissionChecker(),
			formChecker: static fn (int $userId, int $formId): Result => $checker->check($userId, $formId),
			storageRefusal: new FormStorageRefusal(Container::getInstance()->getLogger(self::LOGGER_ID)),
		);
	}

	/** The right to change CRM forms, asked the way every scenario of the family asks it. */
	private static function permissionChecker(): \Closure
	{
		return static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canEdit()
		;
	}

	private function getRequestMapper(): FormRequestMapper
	{
		return $this->requestMapper ??= new FormRequestMapper();
	}

	private function getWriteRequestMapper(): FormWriteRequestMapper
	{
		return $this->writeRequestMapper ??= new FormWriteRequestMapper();
	}
}
