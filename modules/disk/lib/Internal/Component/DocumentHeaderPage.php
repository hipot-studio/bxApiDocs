<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Component;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContext;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\DocumentPresence\PresenceAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;

/**
 * The shared header and the live presence of a page opened by a session of a document. Both belong to
 * one and the same opening, so the shell describes it once and answers both from that description.
 *
 * Kept in one place for every shell that draws them: the access type below is the key the roster is
 * stored under, and shells deciding it apart would register the live roster under one key and read the
 * starting one from another.
 */
trait DocumentHeaderPage
{
	/**
	 * The session keeps no context of its own, so every question asked of it apart decodes the CONTEXT
	 * anew and reads the rows behind it again {@see DocumentSession::getContext()}.
	 */
	protected function buildOpeningContext(
		DocumentSession $documentSession,
		string $downloadUrl = '',
	): ?OpeningContext
	{
		return ServiceLocator::getInstance()
			->get(OpeningContextFactory::class)
			->fromDocumentSession(
				documentSession: $documentSession,
				userId: $this->getHeaderUserId(),
				downloadUrl: $downloadUrl,
				hasUnifiedLinkProof: $this->hasUnifiedLinkProof($documentSession),
			)
		;
	}

	/**
	 * The description is told whether the visitor holds the unified link of the object, and the page
	 * knows it two ways: the unified link drew the page itself - the handler reaches the shell only
	 * after the chain answered for the uniqueCode it was given - or the request carries the signature of
	 * the object, which is how the transition from the viewer arrives
	 * {@see \Bitrix\Disk\Controller\DocumentService::addUnifiedLinkSignatureToUrl()}.
	 */
	protected function hasUnifiedLinkProof(DocumentSession $documentSession): bool
	{
		if ($this->arParams['UNIFIED_LINK_MODE'] ?? false)
		{
			return true;
		}

		$objectId = (int)$documentSession->getObjectId();
		if ($objectId <= 0)
		{
			return false;
		}

		$locator = ServiceLocator::getInstance();
		$uls = $locator->get(UnifiedLinkUrl::class)->getUlsFromRequest(Context::getCurrent()->getRequest());

		return $uls !== null
			&& $locator->get(UnifiedLinkAccessService::class)->validateUlsForObjectId($objectId, $uls)
		;
	}

	/**
	 * An absent description is not an error of the page: the header then asks the controller for it.
	 */
	protected function buildHeaderComposition(?OpeningContext $openingContext): ?array
	{
		return ServiceLocator::getInstance()
			->get(HeaderCompositionService::class)
			->composeForPage($openingContext, $this->getHeaderUserId())
		;
	}

	/**
	 * Presence is never initialized for a public ExternalLink, even if its session context is malformed.
	 *
	 * @return array<string, mixed>
	 */
	protected function buildPresenceConfig(
		DocumentSession $documentSession,
		?OpeningContext $openingContext,
	): array
	{
		if ($this->arResult['EXTERNAL_LINK_MODE'] ?? false)
		{
			return ['enabled' => false];
		}

		$locator = ServiceLocator::getInstance();

		return $locator->get(PresenceAccessService::class)->createConfig(
			$documentSession,
			$this->getHeaderUserId(),
			// A session naming no object is described by nothing, and only then is it asked itself.
			$openingContext?->accessType()
				?? $locator->get(OpeningContextFactory::class)->resolveAccessType($documentSession),
		);
	}

	private function getHeaderUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
