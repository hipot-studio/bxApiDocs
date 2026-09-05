<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Controller\ActionFilter\SharedSignatureAccess;
use Bitrix\Mail\Dto\SharedSignatureDto;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Service\SharedSignature\AssignmentResolver;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * REST controller for signatures of the shared scope.
 * Scope: api — available as mail.api.sharedsignature.*
 *
 * Since [P5.T4] these are thin wrappers over the unified model: they pin the scope to
 * 'shared' and keep the previous request and response shapes for the clients that already
 * call them. New callers use mail.api.signature.* (API-01).
 *
 * Preserved contract:
 *   list   GET  {}                                              → {items: SharedSignatureDTO[]}
 *   get    GET  {id}                                            → {item}
 *   add    POST {signature, assignments:[...]}                  → {item}
 *   update POST {id, signature?, assignments?, assignmentsProvided?} → {item}
 *   delete POST {id}                                            → {ok:true}
 *   assign POST {id, assignments:[...]}                         → {item}
 */
class SharedSignature extends Base
{
	public function configureActions(): array
	{
		$gate = ['+prefilters' => [new SharedSignatureAccess()]];
		$gatePost = ['+prefilters' => [new SharedSignatureAccess(), new HttpMethod([HttpMethod::METHOD_POST])]];

		return [
			'list' => $gate,
			'get' => $gate,
			'add' => $gatePost,
			'update' => $gatePost,
			'delete' => $gatePost,
			'assign' => $gatePost,
		];
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	/**
	 * GET {} → {items: SharedSignatureDTO[]}
	 */
	public function listAction(): array
	{
		$service = new SharedSignatureService();
		$resolver = new AssignmentResolver();
		$items = [];

		foreach ($service->getList(null, null, self::sharedScopeFilter()) as $entry)
		{
			$items[] = SharedSignatureDto::fromRows(
				$entry['signature']->collectValues(),
				$entry['assignments'],
				$resolver,
			);
		}

		return ['items' => $items];
	}

	/**
	 * GET {id} → {item}
	 */
	public function getAction(int $id): array|false
	{
		$entry = $this->loadSharedEntry($id);
		if ($entry === null)
		{
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
	 * POST {signature, assignments:[{targetType,targetId,isFlat}]} → {item}
	 */
	public function addAction(?string $signature = null, array $assignments = []): array|false
	{
		// signature is HTML — must be taken raw from the request
		$rawSignature = (string)$this->getRequest()->getPostList()->getRaw('signature');
		if ($signature !== null)
		{
			$rawSignature = $rawSignature !== '' ? $rawSignature : $signature;
		}

		$sanitizedSignature = $this->sanitize($rawSignature);
		if ($sanitizedSignature === '')
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SIGNATURE_ERROR_EMPTY_BODY'), 422));

			return false;
		}

		$service = new SharedSignatureService();
		$result = $service->add(
			[
				'signature' => $sanitizedSignature,
				'scope' => SharedSignatureTable::SCOPE_SHARED,
				'ownerId' => (int)CurrentUser::get()->getId(),
			],
			$assignments,
		);

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return $this->buildItemResponse($service, (int)$result->getData()['id']);
	}

	/**
	 * POST {id, signature?, assignments?, assignmentsProvided?} → {item}
	 *
	 * An absent set of assignments leaves them as they are; `assignmentsProvided` says the caller
	 * does send the set, so that an empty one — the signature assigned to nobody — is not lost on
	 * the way here (SharedSignatureService::readAssignmentsInput()).
	 */
	public function updateAction(
		int $id,
		?string $signature = null,
		?array $assignments = null,
		?string $assignmentsProvided = null,
	): array|false
	{
		if ($this->loadSharedEntry($id) === null)
		{
			return false;
		}

		$assignments = SharedSignatureService::readAssignmentsInput($assignments, $assignmentsProvided);

		$fields = [];

		// signature is HTML — take raw from request when provided
		$rawSignature = (string)$this->getRequest()->getPostList()->getRaw('signature');
		if ($rawSignature !== '' || $signature !== null)
		{
			$sanitizedSignature = $this->sanitize($rawSignature !== '' ? $rawSignature : (string)$signature);
			if ($sanitizedSignature === '')
			{
				$this->addError(new Error(Loc::getMessage('MAIL_SIGNATURE_ERROR_EMPTY_BODY'), 422));

				return false;
			}

			$fields['signature'] = $sanitizedSignature;
		}

		$service = new SharedSignatureService();
		$result = $service->update($id, $fields, $assignments);

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return $this->buildItemResponse($service, $id);
	}

	/**
	 * POST {id} → {ok:true}
	 */
	public function deleteAction(int $id): array|false
	{
		if ($this->loadSharedEntry($id) === null)
		{
			return false;
		}

		$service = new SharedSignatureService();
		$result = $service->delete($id);

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return ['ok' => true];
	}

	/**
	 * POST {id, assignments:[{targetType,targetId,isFlat}]} → {item}
	 */
	public function assignAction(
		int $id,
		array $assignments = [],
	): array|false
	{
		if ($this->loadSharedEntry($id) === null)
		{
			return false;
		}

		$service = new SharedSignatureService();
		$result = $service->assign($id, $assignments);

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return $this->buildItemResponse($service, $id);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Loads a signature and makes sure it belongs to the shared scope: this endpoint must
	 * not become a way to reach somebody's personal signature by its identifier.
	 *
	 * @return array{signature: \Bitrix\Mail\Internals\Entity\SharedSignature, assignments: array}|null
	 */
	private function loadSharedEntry(int $id): ?array
	{
		$entry = (new SharedSignatureService())->getById($id);

		if (
			$entry === null
			|| (string)$entry['signature']->get('SCOPE') !== SharedSignatureTable::SCOPE_SHARED
		)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SIGNATURE_ERROR_NOT_FOUND'), 404));

			return null;
		}

		return $entry;
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

	/**
	 * @return array<string, string>
	 */
	private static function sharedScopeFilter(): array
	{
		return ['=SCOPE' => SharedSignatureTable::SCOPE_SHARED];
	}
}
