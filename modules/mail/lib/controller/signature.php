<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Dto\SharedSignatureDto;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Service\SharedSignature\AssignmentResolver;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Mail\Service\SharedSignature\SignatureChoiceStorage;
use Bitrix\Mail\Service\SharedSignature\SignatureContextService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * REST controller for signatures of every scope — the single set of operations over the
 * unified model. Scope: api — available as mail.api.signature.*
 *
 * API-01 contract:
 *   list   GET  {scope?, limit?, offset?}                       → {items: SignatureDto[], total: int}
 *   get    GET  {id}                                            → {item}
 *   add    POST {signature, scope, assignments:[...]}           → {item}
 *   update POST {id, signature?, scope?, assignments?, assignmentsProvided?} → {item}
 *   delete POST {id}                                            → {ok: true}
 *   getAvailableForSenders POST {}                              -> {signatures, senders}
 *   setChoice POST {senderKey, signatureId}                     -> {senderKey, selectedSignatureId}
 *   setSenderChoice POST {email, name?, signatureId?}            -> {senderKey, selectedSignatureId}
 *   addOwner POST {text, senderKey?, senderKeys?}                 -> {item: MobileSignature}
 *   deleteOwner POST {id}                                       -> {ok: true}
 *
 * Access is not gated wholesale: an owner-scope signature belongs to its owner, a shared one
 * needs the tariff and the mailbox-management right. Both checks live in SharedSignatureService.
 *
 * The four mobile actions — getAvailableForSenders, setChoice, addOwner, deleteOwner — are closed
 * by the mailmobile signatures feature flag. The web actions are not: they serve the unified model
 * for the desktop client, setSenderChoice among them.
 */
class Signature extends Base
{
	private const DEFAULT_PAGE_SIZE = 50;

	public function configureActions(): array
	{
		$post = ['+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])]];

		return [
			'add' => $post,
			'update' => $post,
			'delete' => $post,
			'getAvailableForSenders' => $post,
			'setChoice' => $post,
			'setSenderChoice' => $post,
			'addOwner' => $post,
			'deleteOwner' => $post,
		];
	}

	/**
	 * POST {} -> {signatures: MobileSignature[], senders: MobileSignatureSender[]}
	 */
	public function getAvailableForSendersAction(SignatureContextService $service): array|false
	{
		if ($this->isMobileSignaturesDisabled())
		{
			return false;
		}

		return $service->getContext($this->getUserId())->toArray();
	}

	/**
	 * POST {senderKey, signatureId} -> {senderKey, selectedSignatureId}
	 */
	public function setChoiceAction(
		SignatureContextService $service,
		string $senderKey,
		?int $signatureId = null,
	): array|false
	{
		if ($this->isMobileSignaturesDisabled())
		{
			return false;
		}

		$result = $service->setChoice($this->getUserId(), $senderKey, $signatureId);
		if (!$result->isSuccess())
		{
			$this->addSignatureContextErrors($result);

			return false;
		}

		return $result->getData();
	}

	/**
	 * POST {text, senderKey?, senderKeys?} -> {item: MobileSignature}
	 */
	public function addOwnerAction(
		SignatureContextService $service,
		?string $text = null,
		?string $senderKey = null,
		array $senderKeys = [],
	): array|false
	{
		if ($this->isMobileSignaturesDisabled())
		{
			return false;
		}

		$result = $service->addOwner($this->getUserId(), (string)$text, $senderKey, $senderKeys);
		if (!$result->isSuccess())
		{
			$this->addSignatureContextErrors($result);

			return false;
		}

		return $result->getData();
	}

	/**
	 * POST {id} -> {ok: true}
	 */
	public function deleteOwnerAction(SignatureContextService $service, int $id): array|false
	{
		if ($this->isMobileSignaturesDisabled())
		{
			return false;
		}

		$result = $service->deleteOwner($this->getUserId(), $id);
		if (!$result->isSuccess())
		{
			$this->addSignatureContextErrors($result);

			return false;
		}

		return $result->getData();
	}

	/**
	 * POST {email, name?, signatureId?} -> {senderKey, selectedSignatureId}
	 *
	 * The web counterpart of setChoice: the caller hands over the sender as it knows it — an
	 * address and a name — and the storage key is built here, so that the shape of the key stays
	 * inside the mail module. Unlike the mobile actions this one is not behind the mobile
	 * signatures flag, as the whole web half of the controller is not.
	 */
	public function setSenderChoiceAction(
		SignatureContextService $service,
		string $email,
		?string $name = null,
		?int $signatureId = null,
	): array|false
	{
		$senderKey = SignatureChoiceStorage::buildSenderKey($email, (string)$name);
		if ($senderKey === '')
		{
			$this->addValidationError(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SENDER_KEY'),
				SignatureContextService::ERROR_INVALID_SENDER_KEY,
			);

			return false;
		}

		$result = $service->setChoice($this->getUserId(), $senderKey, $signatureId);
		if (!$result->isSuccess())
		{
			$this->addSignatureContextErrors($result);

			return false;
		}

		return $result->getData();
	}

	/**
	 * GET {scope?, limit?, offset?} → {items: SignatureDto[], total: int}
	 */
	public function listAction(?string $scope = null, ?int $limit = null, ?int $offset = null): array|false
	{
		if ($scope !== null && !in_array($scope, SharedSignatureTable::getScopes(), true))
		{
			$this->addValidationError(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SCOPE'),
				SharedSignatureService::ERROR_INVALID_SCOPE,
			);

			return false;
		}

		$service = new SharedSignatureService();
		$filter = $service->buildVisibilityFilter($this->getUserId(), $scope);

		$resolver = new AssignmentResolver();
		$items = [];

		$pageSize = ($limit !== null && $limit > 0) ? $limit : self::DEFAULT_PAGE_SIZE;

		foreach ($service->getList($pageSize, $offset, $filter) as $entry)
		{
			$items[] = SharedSignatureDto::fromRows(
				$entry['signature']->collectValues(),
				$entry['assignments'],
				$resolver,
			);
		}

		return [
			'items' => $items,
			'total' => $service->getTotalCount($filter),
		];
	}

	/**
	 * GET {id} → {item}
	 */
	public function getAction(int $id): array|false
	{
		$service = new SharedSignatureService();
		$entry = $service->getById($id);

		if ($entry === null)
		{
			$this->addNotFoundError();

			return false;
		}

		if (!$service->canRead($entry['signature'], $this->getUserId()))
		{
			$this->addAccessDeniedError();

			return false;
		}

		return [
			'item' => SharedSignatureDto::fromRows(
				$entry['signature']->collectValues(),
				$entry['assignments'],
			),
		];
	}

	/**
	 * POST {signature, scope, assignments:[{targetType,targetId,targetValue,isFlat}]} → {item}
	 */
	public function addAction(
		?string $signature = null,
		?string $scope = null,
		array $assignments = [],
	): array|false
	{
		$scope ??= SharedSignatureTable::SCOPE_OWNER;
		if (!in_array($scope, SharedSignatureTable::getScopes(), true))
		{
			$this->addValidationError(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SCOPE'),
				SharedSignatureService::ERROR_INVALID_SCOPE,
			);

			return false;
		}

		$userId = $this->getUserId();
		$service = new SharedSignatureService();

		if (!$service->canCreate($scope, $userId, $userId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$body = $this->readSignatureBody($signature);
		if ($body === null)
		{
			return false;
		}

		$result = $service->add(
			[
				'signature' => $body,
				'scope' => $scope,
				'ownerId' => $userId,
			],
			$assignments,
		);

		if (!$result->isSuccess())
		{
			$this->applyServiceErrors($result->getErrors());

			return false;
		}

		return $this->buildItemResponse($service, (int)$result->getData()['id']);
	}

	/**
	 * POST {id, signature?, scope?, assignments?, assignmentsProvided?} → {item}
	 *
	 * An absent set of assignments leaves them as they are; `assignmentsProvided` says the caller
	 * does send the set, so that an empty one — the signature assigned to nobody — is not lost on
	 * the way here (SharedSignatureService::readAssignmentsInput()).
	 */
	public function updateAction(
		int $id,
		?string $signature = null,
		?string $scope = null,
		?array $assignments = null,
		?string $assignmentsProvided = null,
	): array|false
	{
		$assignments = SharedSignatureService::readAssignmentsInput($assignments, $assignmentsProvided);

		$service = new SharedSignatureService();
		$entry = $service->getById($id);

		if ($entry === null)
		{
			$this->addNotFoundError();

			return false;
		}

		$userId = $this->getUserId();
		if (!$service->canModify($entry['signature'], $userId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$fields = [];

		if ($scope !== null)
		{
			if (!in_array($scope, SharedSignatureTable::getScopes(), true))
			{
				$this->addValidationError(
					Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SCOPE'),
					SharedSignatureService::ERROR_INVALID_SCOPE,
				);

				return false;
			}

			// Q-1: switching the scope is allowed, but the new scope must be one the user
			// is entitled to — otherwise a personal signature could be published portal-wide.
			$targetOwnerId = $scope === SharedSignatureTable::SCOPE_OWNER
				? $userId
				: (int)$entry['signature']->get('OWNER_ID');
			if (!$service->canCreate($scope, $targetOwnerId, $userId))
			{
				$this->addAccessDeniedError();

				return false;
			}

			$fields['scope'] = $scope;
			if (
				$scope === SharedSignatureTable::SCOPE_OWNER
				&& (string)$entry['signature']->get('SCOPE') === SharedSignatureTable::SCOPE_SHARED
			)
			{
				$fields['ownerId'] = $userId;
			}
		}

		if ($signature !== null || $this->hasRawSignature())
		{
			$body = $this->readSignatureBody($signature);
			if ($body === null)
			{
				return false;
			}

			$fields['signature'] = $body;
		}

		$result = $service->update($id, $fields, $assignments);

		if (!$result->isSuccess())
		{
			$this->applyServiceErrors($result->getErrors());

			return false;
		}

		return $this->buildItemResponse($service, $id);
	}

	/**
	 * POST {id} → {ok: true}
	 */
	public function deleteAction(int $id): array|false
	{
		$service = new SharedSignatureService();
		$entry = $service->getById($id);

		if ($entry === null)
		{
			$this->addNotFoundError();

			return false;
		}

		if (!$service->canModify($entry['signature'], $this->getUserId()))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$result = $service->delete($id);
		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return ['ok' => true];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function getUserId(): int
	{
		return (int)(CurrentUser::get()->getId() ?? 0);
	}

	/**
	 * Registers a 404 refusal when the portal keeps mobile signatures off. The status matches the
	 * error code so that a client cached before the code existed still reads it as "no such section".
	 */
	private function isMobileSignaturesDisabled(): bool
	{
		if (Feature::isMobileSignaturesAvailable())
		{
			return false;
		}

		Context::getCurrent()->getResponse()->setStatus(404);
		$this->addError(new Error(
			Loc::getMessage('MAIL_SIGNATURE_ERROR_FEATURE_DISABLED'),
			SignatureContextService::ERROR_FEATURE_DISABLED,
		));

		return true;
	}

	private function hasRawSignature(): bool
	{
		return (string)$this->getRequest()->getPostList()->getRaw('signature') !== '';
	}

	/**
	 * Reads and sanitizes the signature body. Returns null and registers a 422 when it is empty.
	 * The body is HTML, so it has to be taken raw from the request.
	 */
	private function readSignatureBody(?string $signature): ?string
	{
		$raw = (string)$this->getRequest()->getPostList()->getRaw('signature');
		if ($raw === '' && $signature !== null)
		{
			$raw = $signature;
		}

		$sanitized = $this->sanitizeSignature($raw);
		if ($sanitized === '')
		{
			$this->addValidationError(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_EMPTY_BODY'),
				SharedSignatureService::ERROR_EMPTY_BODY,
			);

			return null;
		}

		return $sanitized;
	}

	private function buildItemResponse(SharedSignatureService $service, int $id): array
	{
		$entry = $service->getById($id);

		return [
			'item' => SharedSignatureDto::fromRows(
				$entry['signature']->collectValues(),
				$entry['assignments'],
			),
		];
	}

	private function addAccessDeniedError(): void
	{
		Context::getCurrent()->getResponse()->setStatus(403);
		$this->addError(SharedSignatureService::accessDeniedError());
	}

	private function addNotFoundError(): void
	{
		Context::getCurrent()->getResponse()->setStatus(404);
		$this->addError(new Error(
			Loc::getMessage('MAIL_SIGNATURE_ERROR_NOT_FOUND'),
			SharedSignatureService::ERROR_NOT_FOUND,
		));
	}

	private function addValidationError(?string $message, string $code): void
	{
		Context::getCurrent()->getResponse()->setStatus(422);
		$this->addError(new Error((string)$message, $code));
	}

	/**
	 * @param Error[] $errors
	 */
	private function applyServiceErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			if (SharedSignatureService::isValidationError($error))
			{
				Context::getCurrent()->getResponse()->setStatus(422);

				break;
			}
		}

		$this->addErrors($errors);
	}

	private function addSignatureContextErrors(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			[$status, $message] = match ($error->getCode())
			{
				SignatureContextService::ERROR_INVALID_SIGNATURE_ID => [
					422,
					Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_ID'),
				],
				SignatureContextService::ERROR_INVALID_SENDER_KEY => [
					422,
					Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SENDER_KEY'),
				],
				SignatureContextService::ERROR_SENDER_NOT_AVAILABLE => [
					403,
					Loc::getMessage('MAIL_SIGNATURE_ERROR_SENDER_NOT_AVAILABLE'),
				],
				SignatureContextService::ERROR_SIGNATURE_NOT_AVAILABLE => [
					404,
					Loc::getMessage('MAIL_SIGNATURE_ERROR_NOT_FOUND'),
				],
				SignatureContextService::ERROR_EMPTY_TEXT => [
					422,
					Loc::getMessage('MAIL_SIGNATURE_ERROR_EMPTY_BODY'),
				],
				SignatureContextService::ERROR_LIMIT_REACHED => [
					422,
					Loc::getMessage('MAIL_SIGNATURE_ERROR_LIMIT_REACHED'),
				],
				default => [500, Loc::getMessage('MAIL_SIGNATURE_ERROR_OPERATION_FAILED')],
			};

			Context::getCurrent()->getResponse()->setStatus($status);
			$this->addError(new Error((string)$message, $error->getCode()));
		}
	}
}
