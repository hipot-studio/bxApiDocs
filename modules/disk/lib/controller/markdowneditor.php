<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\MarkdownEditor\AccessResolver;
use Bitrix\Disk\Internal\Service\MarkdownEditor\EditorIdentity;
use Bitrix\Disk\Internal\Service\MarkdownEditor\PreviewPolicy;
use Bitrix\Disk\Internal\Service\MarkdownEditor\PreviewService;
use Bitrix\Disk\Internal\Service\MarkdownEditor\ReadinessService;
use Bitrix\Disk\Internal\Service\MarkdownEditor\SessionService;
use Bitrix\Disk\Internal\Service\MarkdownEditor\WriteService;
use Bitrix\Disk\Internal\Service\MarkdownViewerPageService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The actions of editing a Markdown file on its tab page (disk.markdownEditor.*): entering editing, the pulse of
 * the editing tab, the write of a version and leaving editing, the last one also sent by a closing page as a form;
 * the read-only question of a page waiting for the file to be let go of, whether it may be edited now; and the
 * preview of the unsaved text, rendered by {@see PreviewService} without an md session and without logging in.
 *
 * Holds no rules of its own: who edits and whether they may is answered by {@see AccessResolver} in every action
 * (and once more under the lock by the services), the md session by {@see SessionService}, the write by
 * {@see WriteService}, the texts of the refusals by {@see ReadinessService}. A guest of a public link with the
 * right to edit is logged in as the editor user of documents when entering editing.
 */
class MarkdownEditor extends Engine\Controller
{
	private ?SessionService $sessionService = null;
	private ?ReadinessService $readinessService = null;

	public function configureActions(): array
	{
		$configuration = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];

		return [
			// A guest of a public link has no account before entering editing; the link authorizes the action.
			'acquire' => $configuration + ['-prefilters' => [ActionFilter\Authentication::class]],
			'heartbeat' => $configuration,
			'save' => $configuration,
			'release' => $configuration,
			// Asked by whoever may open the page, a guest of a public link without an account included.
			'checkReadiness' => $configuration + ['-prefilters' => [ActionFilter\Authentication::class]],
			// A guest of a public link with the right to edit previews without an account; the link authorizes it.
			'preview' => $configuration + ['-prefilters' => [ActionFilter\Authentication::class]],
		];
	}

	/**
	 * @return array{state: array, contentBase64: string, sessid: string}|null
	 */
	public function acquireAction(
		int $fileId,
		?string $uniqueCode = null,
		?string $externalLinkHash = null,
	): ?array
	{
		$identity = $this->resolveIdentity($fileId, $uniqueCode, $externalLinkHash, true);
		if ($identity === null)
		{
			return null;
		}

		$readiness = $this->getReadinessService()->assess(
			$identity->file,
			$identity->userId,
			$identity->uniqueCode,
			$identity->externalLink,
		);
		if ($readiness->refusal !== null)
		{
			$this->addError($readiness->refusal);

			return null;
		}

		$result = $this->getSessionService()->acquire($identity);
		if (!$result->isSuccess())
		{
			$this->addRefusal($result, $identity);

			return null;
		}

		return [
			'state' => $result->getData()['state']->toArray(),
			'contentBase64' => base64_encode((string)$result->getData()['content']),
			// Logging a guest in may have started another session; the page sends the next calls with this one.
			'sessid' => bitrix_sessid(),
		];
	}

	/**
	 * @return array{state: array}|null
	 */
	public function heartbeatAction(
		int $fileId,
		string $sessionHash,
		int $idleSeconds = 0,
		?string $uniqueCode = null,
		?string $externalLinkHash = null,
	): ?array
	{
		$identity = $this->resolveIdentity($fileId, $uniqueCode, $externalLinkHash);
		if ($identity === null)
		{
			return null;
		}

		$result = $this->getSessionService()->heartbeat($identity, $sessionHash, $idleSeconds);
		if (!$result->isSuccess())
		{
			$this->addRefusal($result, $identity, true);

			return null;
		}

		return ['state' => $result->getData()['state']->toArray()];
	}

	/**
	 * @return array{state: array, revision: array{id: int, contentUrl: string, downloadUrl: string}}|null
	 */
	public function saveAction(
		int $fileId,
		string $sessionHash,
		int $baseVersion,
		string $contentBase64,
		?string $uniqueCode = null,
		?string $externalLinkHash = null,
	): ?array
	{
		$identity = $this->resolveIdentity($fileId, $uniqueCode, $externalLinkHash);
		if ($identity === null)
		{
			return null;
		}

		$result = (new WriteService($this->getSessionService()))->write(
			$identity,
			$sessionHash,
			$baseVersion,
			$contentBase64,
		);
		if (!$result->isSuccess())
		{
			// The write service words its refusals itself.
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'state' => $result->getData()['state']->toArray(),
			'revision' => $result->getData()['revision'],
		];
	}

	/**
	 * @return array{released: bool, document: array, contentUrl: ?string}|null
	 */
	public function releaseAction(
		int $fileId,
		string $sessionHash,
		?string $uniqueCode = null,
		?string $externalLinkHash = null,
	): ?array
	{
		$identity = $this->resolveIdentity($fileId, $uniqueCode, $externalLinkHash);
		if ($identity === null)
		{
			return null;
		}

		$result = $this->getSessionService()->release($identity, $sessionHash);
		if (!$result->isSuccess())
		{
			$this->addRefusal($result, $identity);

			return null;
		}

		$view = $this->buildViewState($identity);

		return [
			'released' => (bool)$result->getData()['released'],
			'document' => $view['document'],
			'contentUrl' => $view['contentUrl'],
		];
	}

	/**
	 * Whether the viewer of the page may enter editing now, and why not: what the page was built with, asked
	 * again when the session that kept the file busy is let go of or is due to go out. Changes nothing.
	 *
	 * @return array{canEdit: bool, editRefusal: array{code: string, message: string, customData: ?array}|null}|null
	 */
	public function checkReadinessAction(
		int $fileId,
		?string $uniqueCode = null,
		?string $externalLinkHash = null,
	): ?array
	{
		$file = $fileId > 0 ? File::loadById($fileId) : null;
		$link = null;
		if ($externalLinkHash !== null && $externalLinkHash !== '')
		{
			$link = $this->loadExternalLink($externalLinkHash);
			if ($link === null)
			{
				$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

				return null;
			}
		}

		$userId = $this->getCurrentUserId();
		if (!$file instanceof File || !$this->canOpenPage($file, $userId, $link))
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		$readiness = $this->getReadinessService()->assess($file, $userId, $uniqueCode, $link);
		$refusal = $readiness->hasRight ? $readiness->refusal : null;

		return [
			'canEdit' => $readiness->canEdit,
			'editRefusal' => $refusal === null ? null : [
				'code' => (string)$refusal->getCode(),
				'message' => (string)$refusal->getMessage(),
				'customData' => $refusal->getCustomData(),
			],
		];
	}

	/**
	 * The render of the unsaved text for the preview column, in the form of the view (showMarkdown). Asked by
	 * whoever may edit the file now; needs no md session and logs no guest in. The codes of the refusals of the
	 * render are the contract, the client words them.
	 *
	 * @return array{html: string, diagrams: list<string>}|null
	 */
	public function previewAction(
		int $fileId,
		string $contentBase64,
		?string $uniqueCode = null,
		?string $externalLinkHash = null,
	): ?array
	{
		if (!PreviewPolicy::isEnabled())
		{
			$this->addRefusalCode(AccessResolver::ERROR_FEATURE_DISABLED);

			return null;
		}

		$identity = $this->resolvePreviewIdentity($fileId, $uniqueCode, $externalLinkHash);
		if ($identity === null)
		{
			return null;
		}

		$result = (new PreviewService())->render(
			$identity,
			$contentBase64,
			(string)$this->request->getRemoteAddress(),
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'html' => $result->getData()['html'],
			'diagrams' => $result->getData()['diagrams'],
		];
	}

	/**
	 * The page of the file is opened by those the unified link lets read it, the page of a public link by the
	 * holders of the link who passed its password; the same check answers here.
	 */
	private function canOpenPage(File $file, int $userId, ?ExternalLink $link): bool
	{
		$accessService = ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
		if ($link !== null && (int)$link->getObjectId() !== (int)$file->getId())
		{
			return false;
		}

		return $accessService->check($file, userId: $userId, externalLink: $link)->canRead();
	}

	/**
	 * The page state after leaving editing, as the page the editor came from gives it to the viewer: the page of
	 * the file, or the page of the public link the editor holds. The page of the file also gets the address of
	 * the newest revision, which somebody else may have written before the editing began; the content of the
	 * public link page is the current file anyway.
	 *
	 * @return array{document: array, contentUrl: ?string}
	 */
	protected function buildViewState(EditorIdentity $identity): array
	{
		$pageService = new MarkdownViewerPageService();
		if ($identity->externalLink === null)
		{
			$params = $pageService->buildParamsByFile($identity->file);

			return ['document' => $params['DOCUMENT_STATE'], 'contentUrl' => (string)$params['CONTENT_URL']];
		}

		// Only the state is taken: the addresses of the link page, which need its token, are not.
		$params = $pageService->buildParamsByExternalLink($identity->file, $identity->externalLink, '', null);

		return ['document' => $params['DOCUMENT_STATE'], 'contentUrl' => null];
	}

	private function resolveIdentity(
		int $fileId,
		?string $uniqueCode,
		?string $externalLinkHash,
		bool $mayLogGuestIn = false,
	): ?EditorIdentity
	{
		$file = $fileId > 0 ? File::loadById($fileId) : null;
		$link = null;
		if ($externalLinkHash !== null && $externalLinkHash !== '')
		{
			$link = $this->loadExternalLink($externalLinkHash);
			if ($link === null)
			{
				$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

				return null;
			}
		}
		if (!$file instanceof File)
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		$accessResolver = new AccessResolver();
		$result = $accessResolver->resolve($file, $this->getCurrentUserId(), $uniqueCode, $link);
		if (!$result->isSuccess())
		{
			$this->addRefusalCode((string)$result->getErrors()[0]->getCode());

			return null;
		}

		/** @var EditorIdentity $identity */
		$identity = $result->getData()['identity'];
		if ($identity->userId > 0)
		{
			return $identity;
		}

		if (!$mayLogGuestIn || !$identity->isGuest())
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		// Only a link found to grant editing, to a file that may be edited now, logs a guest in: a wrong link or a
		// refused entry makes no user.
		$readiness = $this->getReadinessService()->assess($file, 0, null, $identity->externalLink);
		if (!$readiness->canEdit)
		{
			if ($readiness->refusal !== null)
			{
				$this->addError($readiness->refusal);
			}
			else
			{
				$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);
			}

			return null;
		}

		if (!DocumentEditorUser::login(['NAME' => GuestUser::create()->getName()]))
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		$result = $accessResolver->resolve($file, $this->getCurrentUserId(), $uniqueCode, $link);
		if (!$result->isSuccess() || $result->getData()['identity']->userId <= 0)
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		return $result->getData()['identity'];
	}

	/**
	 * Who previews: the one {@see AccessResolver} lets edit the file, without the md session and the readiness of
	 * entering editing. A guest of a public link stays as they came - an anonymous one is not logged in.
	 */
	private function resolvePreviewIdentity(
		int $fileId,
		?string $uniqueCode,
		?string $externalLinkHash,
	): ?EditorIdentity
	{
		$file = $fileId > 0 ? File::loadById($fileId) : null;
		$link = null;
		if ($externalLinkHash !== null && $externalLinkHash !== '')
		{
			$link = $this->loadExternalLink($externalLinkHash);
			if ($link === null)
			{
				$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

				return null;
			}
		}
		if (!$file instanceof File)
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		$result = (new AccessResolver())->resolve($file, $this->getCurrentUserId(), $uniqueCode, $link);
		if (!$result->isSuccess())
		{
			$this->addRefusalCode((string)$result->getErrors()[0]->getCode());

			return null;
		}

		/** @var EditorIdentity $identity */
		$identity = $result->getData()['identity'];
		if ($identity->userId <= 0 && !$identity->isGuest())
		{
			$this->addRefusalCode(AccessResolver::ERROR_ACCESS_DENIED);

			return null;
		}

		return $identity;
	}

	private function loadExternalLink(string $hash): ?ExternalLink
	{
		if (!ExternalLink::isValidValueForField('HASH', $hash))
		{
			return null;
		}

		$link = ExternalLink::load(['=HASH' => $hash]);

		return $link instanceof ExternalLink ? $link : null;
	}

	/**
	 * The errors of the session service carry the code only; the words are given here from the one dictionary.
	 * An own session put out and taken by somebody else meanwhile is, for the pulse, a file changed under the
	 * editor: one message, the code still telling the lock is held.
	 */
	private function addRefusal(Result $result, EditorIdentity $identity, bool $isRetake = false): void
	{
		$code = (string)(($result->getErrors()[0] ?? null)?->getCode() ?? AccessResolver::ERROR_ACCESS_DENIED);
		if ($code !== SessionService::ERROR_SESSION_BUSY)
		{
			$this->addRefusalCode($code, $identity->userId);

			return;
		}

		$readiness = $this->getReadinessService();
		$busy = $readiness->describeRefusal(
			$code,
			$this->getSessionService()->findBlockingSession((int)$identity->file->getRealObjectId()),
			$identity->userId,
			$identity->isGuest(),
		);
		if ($isRetake)
		{
			$changed = $readiness->describeRefusal(SessionService::ERROR_CONTENT_CHANGED, null, $identity->userId);
			$busy = new Error($changed->getMessage(), $code, $busy->getCustomData());
		}

		$this->addError($busy);
	}

	private function addRefusalCode(string $code, ?int $userId = null): void
	{
		$this->addError(
			$this->getReadinessService()->describeRefusal($code, null, $userId ?? $this->getCurrentUserId()),
		);
	}

	private function getCurrentUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	private function getSessionService(): SessionService
	{
		return $this->sessionService ??= new SessionService();
	}

	private function getReadinessService(): ReadinessService
	{
		return $this->readinessService ??= new ReadinessService($this->getSessionService());
	}
}
