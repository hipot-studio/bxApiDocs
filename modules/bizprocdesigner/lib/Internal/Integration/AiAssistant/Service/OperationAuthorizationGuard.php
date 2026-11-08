<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Authorises a reverse-entry authoring operation (REST validate/push and Marta) through the type-aware document
 * guard and the template ACL (ALG-02). The guard holds no access logic of its own: it forwards the already-resolved
 * subject tuple unchanged and turns both answers into an {@see AuthorizationVerdict}. It never reads the subject
 * from block content and never re-resolves userId/isUserAdmin.
 *
 * The delegate must stay canManageDocument, never AiAgentProvider::canManageLaunchedTemplate directly: the
 * requireStarted:true guarantee is wired into canManageDocument, and bypassing it would reopen the unstarted
 * launched-copy hole.
 *
 * The verdict carries no machine code: a document-access denial is told apart from schema/type/storage rejects
 * by its reason alone. A reject is additionally reported to the module diagnostic channel, so every entry keeps
 * the refusal observable without the reason ever reaching the caller: it names another user's document type and
 * template id.
 */
final class OperationAuthorizationGuard
{
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.operation_authorization';

	private readonly DocumentAccessService $documentAccessService;
	private ?LoggerInterface $logger = null;

	public function __construct(?DocumentAccessService $documentAccessService = null)
	{
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
	}

	public function authorize(
		int $userId,
		DocumentDescription $documentType,
		int $templateId,
		bool $isUserAdmin,
	): AuthorizationVerdict {
		$allowed = $this->documentAccessService->canManageDocument(
			$userId,
			$documentType,
			$templateId,
			$isUserAdmin,
		) && $this->documentAccessService->canEditTemplate($userId, $documentType, $templateId);
		if ($allowed)
		{
			return AuthorizationVerdict::allow();
		}

		$verdict = AuthorizationVerdict::reject(
			sprintf(
				'document or template access denied for document type %s, template #%d',
				implode('.', $documentType->toBizprocComplexType()),
				$templateId,
			),
		);

		$this->getLogger()->warning(
			'AI reverse entry: document access denied',
			[
				'userId' => $userId,
				'templateId' => $templateId,
				'isUserAdmin' => $isUserAdmin,
				'reason' => $verdict->reason,
			],
		);

		return $verdict;
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}
}
