<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Mail\Service\SharedSignature\SignatureMigrator;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * REST controller for personal signatures.
 * Scope: api — available as mail.api.usersignature.*
 *
 * A thin wrapper over the unified model: a personal signature is a signature of the owner scope,
 * its binding to a sender is an assignment with a string target, and an empty binding means no
 * assignments at all. The previous request and response shapes are kept for the clients that
 * already call these methods — the signature editor and the personal signature grid; new callers
 * use mail.api.signature.* (API-01). The previous table is never written.
 *
 * Every modifying action migrates the signatures of its author first (P7.T2): the write goes to
 * the unified model and the compatibility adapter reads it as soon as the user has a single row
 * there, so without the migration the first save would hide everything not copied yet.
 *
 * The single userSignatureId parameter may carry a number of either sequence while the migration is
 * running, and the two overlap. Which one it is depends on the caller, hence the two lookup orders:
 * the signature editor names the row of the previous table it opened, so update resolves the legacy
 * link first, while everything else — and inside the product that means an outdated page, since the
 * signature list deletes through mail.api.signature.* — names a row of the unified model and is
 * answered by it first. Either order keeps the other lookup as a fallback, so both readings stay
 * reachable for an external client of the previous API.
 *
 * Preserved contract:
 *   add    POST {fields: {signature, sender}}                    → {userSignature: {id, userId, sender, signature}}
 *   update POST {userSignatureId, fields: {signature?, sender?}} → {userSignature: {...}}
 *   get    GET  {userSignatureId}                                → {userSignature: {...}}
 *   delete POST {userSignatureId}                                → true
 */
class UserSignature extends Base
{
	const USER_SIGNATURES_LIMIT = 100;

	/**
	 * @param array $fields {signature: string, sender: string}
	 */
	public function addAction(array $fields): array|false
	{
		$userId = $this->getUserId();

		$service = new SharedSignatureService();

		if (!$service->canCreate(SharedSignatureTable::SCOPE_OWNER, $userId, $userId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$this->migrateOwnSignatures($userId);

		if (!$this->checkSignaturesLimit($service, $userId))
		{
			return false;
		}

		$result = $service->add(
			[
				'signature' => $this->readSignatureBody($fields),
				'scope' => SharedSignatureTable::SCOPE_OWNER,
				'ownerId' => $userId,
			],
			self::senderAssignments((string)($fields['sender'] ?? '')),
		);

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return $this->buildResponse($service, (int)$result->getData()['id']);
	}

	public function getAction(int $userSignatureId): array|false
	{
		$service = new SharedSignatureService();

		$entry = $this->loadOwnEntry($service, $userSignatureId);
		if ($entry === null)
		{
			return false;
		}

		if (!$service->canRead($entry['signature'], $this->getUserId()))
		{
			$this->addAccessDeniedError();

			return false;
		}

		return ['userSignature' => self::formatEntry($entry)];
	}

	/**
	 * @param array $fields {signature?: string, sender?: string} — only the given keys are applied.
	 */
	public function updateAction(int $userSignatureId, array $fields): array|false
	{
		$this->migrateOwnSignatures($this->getUserId());

		$service = new SharedSignatureService();

		$entry = $this->loadOwnEntry($service, $userSignatureId, true);
		if ($entry === null)
		{
			return false;
		}

		if (!$service->canModify($entry['signature'], $this->getUserId()))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$values = [];
		if (array_key_exists('signature', $this->readRawFields()) || array_key_exists('signature', $fields))
		{
			$values['signature'] = $this->readSignatureBody($fields);
		}

		// An absent sender key leaves the binding as it is, an empty one drops it.
		$assignments = array_key_exists('sender', $fields)
			? self::senderAssignments((string)$fields['sender'])
			: null
		;

		// The identifier the client sent may be a legacy one, so the row that was actually found
		// is the one to write.
		$signatureId = (int)$entry['signature']->getId();

		$result = $service->update($signatureId, $values, $assignments);

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return $this->buildResponse($service, $signatureId);
	}

	public function deleteAction(int $userSignatureId): bool
	{
		$this->migrateOwnSignatures($this->getUserId());

		$service = new SharedSignatureService();

		$entry = $this->loadOwnEntry($service, $userSignatureId);
		if ($entry === null)
		{
			return false;
		}

		if (!$service->canModify($entry['signature'], $this->getUserId()))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$result = $service->delete((int)$entry['signature']->getId());

		if (!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrors();

			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Loads a signature of the owner scope by its identifier in the unified model. An identifier
	 * of another scope is answered with 404: this endpoint must not become a way to reach a
	 * corporate signature.
	 *
	 * With $preferLegacyId the identifier is read as one of the legacy table first — that is what the
	 * signature editor sends after opening a migrated row. Without it the unified model answers
	 * first. The other reading stays as a fallback either way, and it is reached whenever the first
	 * one does not name a signature of the caller: a number of one sequence may well name somebody
	 * else's row in the other. Only when neither reading names a signature of his does the foreign
	 * row become the answer, so an existing signature is still refused rather than denied to exist.
	 *
	 * @return array{signature: \Bitrix\Mail\Internals\Entity\SharedSignature, assignments: array}|null
	 */
	private function loadOwnEntry(
		SharedSignatureService $service,
		int $id,
		bool $preferLegacyId = false,
	): ?array
	{
		$byUnifiedId = fn(): ?array => $this->readOwnScopeEntry($service, $id);
		$byLegacyLink = fn(): ?array => $this->readMigratedEntry($service, $id);

		$foreign = null;
		foreach ($preferLegacyId ? [$byLegacyLink, $byUnifiedId] : [$byUnifiedId, $byLegacyLink] as $read)
		{
			$entry = $read();
			if ($entry === null)
			{
				continue;
			}

			if ($service->canModify($entry['signature'], $this->getUserId()))
			{
				return $entry;
			}

			$foreign ??= $entry;
		}

		if ($foreign === null)
		{
			$this->addNotFoundError();
		}

		return $foreign;
	}

	/**
	 * The signature the given legacy row was copied into. Bounded by the caller: the link is looked
	 * up among his own rows.
	 *
	 * @return array{signature: \Bitrix\Mail\Internals\Entity\SharedSignature, assignments: array}|null
	 */
	private function readMigratedEntry(SharedSignatureService $service, int $legacyId): ?array
	{
		$migratedId = (new SignatureMigrator())->findMigratedId($legacyId, $this->getUserId());

		return $migratedId === null ? null : $this->readOwnScopeEntry($service, $migratedId);
	}

	/**
	 * @return array{signature: \Bitrix\Mail\Internals\Entity\SharedSignature, assignments: array}|null
	 */
	private function readOwnScopeEntry(SharedSignatureService $service, int $id): ?array
	{
		$entry = $service->getById($id);

		if (
			$entry === null
			|| (string)$entry['signature']->get('SCOPE') !== SharedSignatureTable::SCOPE_OWNER
		)
		{
			return null;
		}

		return $entry;
	}

	/**
	 * Copies the not yet migrated signatures of the given user into the unified model, before the
	 * action does what it was asked for (P7.T2). The migration is bounded by the rows of that user.
	 */
	private function migrateOwnSignatures(int $userId): void
	{
		if ($userId > 0)
		{
			(new SignatureMigrator())->migrateUser($userId);
		}
	}

	private function buildResponse(SharedSignatureService $service, int $id): array
	{
		return ['userSignature' => self::formatEntry($service->getById($id))];
	}

	/**
	 * The response shape the clients already speak: the field names of the previous entity
	 * in camelCase.
	 *
	 * @param array{signature: \Bitrix\Mail\Internals\Entity\SharedSignature, assignments: array} $entry
	 */
	private static function formatEntry(array $entry): array
	{
		return [
			'id' => (int)$entry['signature']->getId(),
			'userId' => (int)$entry['signature']->get('OWNER_ID'),
			'sender' => self::extractSender($entry['assignments']),
			'signature' => (string)$entry['signature']->get('SIGNATURE'),
		];
	}

	/**
	 * The sender string the signature is bound to; empty when it applies to every sender.
	 */
	private static function extractSender(array $assignments): string
	{
		foreach ($assignments as $assignment)
		{
			if ((string)$assignment['TARGET_TYPE'] === SharedSignatureAssignmentTable::TARGET_SENDER)
			{
				return (string)($assignment['TARGET_VALUE'] ?? '');
			}
		}

		return '';
	}

	/**
	 * Expresses the binding to a sender as assignments of the unified model: a string target for
	 * a bound signature, no assignments at all for the one that suits every sender.
	 *
	 * @return array<array{targetType: string, targetId: int, targetValue: string, isFlat: bool}>
	 */
	private static function senderAssignments(string $sender): array
	{
		if ($sender === '')
		{
			return [];
		}

		return [
			[
				'targetType' => SharedSignatureAssignmentTable::TARGET_SENDER,
				'targetId' => 0,
				'targetValue' => $sender,
				'isFlat' => false,
			],
		];
	}

	/**
	 * The signature body is HTML, so it is taken raw from the request; the already bound value
	 * serves the callers whose body does not come as form data.
	 */
	private function readSignatureBody(array $fields): string
	{
		$unsafeFields = $this->readRawFields();

		$body = array_key_exists('signature', $unsafeFields)
			? (string)$unsafeFields['signature']
			: (string)($fields['signature'] ?? '')
		;

		return $this->sanitize($body);
	}

	private function readRawFields(): array
	{
		return (array)$this->getRequest()->getPostList()->getRaw('fields');
	}

	private function checkSignaturesLimit(SharedSignatureService $service, int $userId): bool
	{
		$limit = (int)Option::get('mail', 'user_signatures_limit', static::USER_SIGNATURES_LIMIT);
		if ($limit <= 0)
		{
			return true;
		}

		if ($service->getTotalCountByOwner($userId, SharedSignatureTable::SCOPE_OWNER) < $limit)
		{
			return true;
		}

		$this->addError(new Error(Loc::getMessage('MAIL_USER_SIGNATURE_LIMIT')));

		return false;
	}

	private function getUserId(): int
	{
		return (int)(CurrentUser::get()->getId() ?? 0);
	}

	private function addAccessDeniedError(): void
	{
		Context::getCurrent()->getResponse()->setStatus(403);
		$this->addError(new Error(
			Loc::getMessage('MAIL_USER_SIGNATURE_ACCESS_DENIED'),
			SharedSignatureService::ERROR_ACCESS_DENIED,
		));
	}

	private function addNotFoundError(): void
	{
		Context::getCurrent()->getResponse()->setStatus(404);
		$this->addError(new Error(
			Loc::getMessage('MAIL_SIGNATURE_ERROR_NOT_FOUND'),
			SharedSignatureService::ERROR_NOT_FOUND,
		));
	}
}
