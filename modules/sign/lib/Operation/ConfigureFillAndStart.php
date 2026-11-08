<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Operation\Result\ConfigureResult;
use Bitrix\Sign\Operation\Result\FillFieldsResult;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\DirectorSigningGuardService;
use Bitrix\Sign\Service\Sign\DocumentAgentService;
use Bitrix\Sign\Service\Sign\PlaceholderBlockService;
use Bitrix\Sign\Type\DocumentStatus;

class ConfigureFillAndStart implements Operation
{
	/**
	 * The name the configure activity of the business process takes around its own writes. Both
	 * holders name it the same on purpose: the pair is what excludes them from each other.
	 */
	private const CONFIGURE_LOCK_NAME_PREFIX = 'sign_document_configure_';

	/**
	 * States of the signing service (its vocabulary, not the portal's) in which the service can no
	 * longer be waiting for the portal, so a rejected configuration means the two sides really have
	 * diverged. The assembly states (`new`, `uploading`, `uploaded`) and `ready` are left out: there
	 * the portal running ahead is an ordinary race, and a record about it would be noise.
	 */
	private const SERVICE_DIVERGENT_STATUSES = ['sign', 'done', 'stopped'];

	private readonly Document $document;
	private readonly DocumentRepository $documentRepository;
	private readonly PlaceholderBlockService $placeholderBlockService;
	private readonly DirectorSigningGuardService $directorSigningGuardService;
	private readonly DocumentAgentService $documentAgentService;
	private readonly Logger $logger;

	public function __construct(
		private readonly string $uid,
		?DocumentRepository $documentRepository = null,
		?PlaceholderBlockService $placeholderBlockService = null,
		?DirectorSigningGuardService $directorSigningGuardService = null,
		?DocumentAgentService $documentAgentService = null,
		?Logger $logger = null,
	)
	{
		$container = Container::instance();
		$this->logger = $logger ?? $container->getLogger();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->placeholderBlockService = $placeholderBlockService ?? $container->getPlaceholderBlockService();
		$this->directorSigningGuardService = $directorSigningGuardService
			?? $container->getDirectorSigningGuardService()
		;
		$this->documentAgentService = $documentAgentService ?? $container->getDocumentAgentService();
	}

	public function launch(): Main\Result|ConfigureResult
	{
		$document = $this->documentRepository->getByUid($this->uid);
		if ($document === null)
		{
			return (new Main\Result())
				->addError(new Main\Error("Document with id `$this->uid` doesnt exist"))
			;
		}
		$this->document = $document;

		if ($this->document->isTemplated())
		{
			// Every configuration path of the portal goes through here, and configuring is what creates
			// the members of the document in the signing service. Members on a template would take away
			// the only thing that protects it while its flag is unknown: the cleanup lets a document
			// through when the portal never said whether it is a template and the document has members.
			// No flow configures a template today, so a record here names a caller that should not exist.
			$this->logger->warning(
				'Refused to configure templated document {uid} (template {templateId})',
				[
					'uid' => $this->document->uid,
					'templateId' => $this->document->templateId,
				],
			);

			return (new Main\Result())
				->addError(new Main\Error('Can not configure templated document.'))
			;
		}

		if ($this->document->status === DocumentStatus::SIGNING)
		{
			return new ConfigureResult(true);
		}

		// Also checked in ConfigureDocument; a document already in the READY status skips it entirely.
		$directorSigningResult = $this->directorSigningGuardService->check($this->document);
		if (!$directorSigningResult->isSuccess())
		{
			return $directorSigningResult;
		}

		if ($this->document->status !== DocumentStatus::READY)
		{
			$createBlocksResult = $this->placeholderBlockService->createBlocksForDocument($this->document);

			if (!$createBlocksResult->isSuccess())
			{
				return $createBlocksResult;
			}

			return $this->configure();
		}

		$fillResult = (new \Bitrix\Sign\Operation\SigningService\FillFields($this->document))->launch();
		if ($fillResult instanceof FillFieldsResult)
		{
			return $this->startOrProgress($fillResult);
		}

		return $fillResult;
	}

	/**
	 * The configure of the document is what takes it out of `uploaded`, and it both reads the status
	 * and writes the next one inside a single call: a writer running beside it reads a status the
	 * call is about to change, and no check of its own can see that. The lock closes exactly that
	 * pair, and it carries the name the configure activity of the business process already takes, so
	 * the two exclude each other instead of each guarding a name of its own.
	 *
	 * A busy document is not an error: the caller is told the work is not finished, and the agent of
	 * repeats comes back to it. That is the same answer a busy signing start gives.
	 *
	 * @see \CBPSignB2EDocumentConfigureActivity::LOCK_NAME_PREFIX the other holder of the name
	 * @see \Bitrix\Sign\Service\Sign\DocumentAgentService::configureAndStart() comes back for it
	 */
	private function configure(): Main\Result|ConfigureResult
	{
		if (!$this->getConfigureLock())
		{
			return new ConfigureResult(false);
		}

		try
		{
			return $this->configureUnderLock();
		}
		finally
		{
			$this->releaseConfigureLock();
		}
	}

	private function configureUnderLock(): Main\Result|ConfigureResult
	{
		$result = (new ConfigureDocument($this->document->uid))->launch();
		if (!$result->isSuccess())
		{
			if ($this->isDocumentNotReady($result))
			{
				return new ConfigureResult(false);
			}

			return $result;
		}

		return new ConfigureResult(false);
	}

	private function isDocumentNotReady(Main\Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === 'DOCUMENT_INCORRECT_STATE')
			{
				$this->logStatusMismatch($error, $this->document);

				return true;
			}

			// The blank goes to the signing service in a background agent, so a document of a
			// freshly created batch can still be waiting for its turn. The sending is not broken by
			// it: the configure agent comes back to the document once the blank is there.
			if ($this->isWaitingForBlankUpload($error))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The suppression above covers two different things at once: a race with the background blank
	 * assembly, which is normal and stays silent, and a real divergence between the portal and the
	 * service, which is not and used to be invisible. Only the second one is written down; the
	 * decision of the caller is not affected either way.
	 *
	 * A service that reports nothing is an older one, and silence is what the portal did before it
	 * learned to ask; otherwise every race against such a service would produce a false record.
	 */
	private function logStatusMismatch(Main\Error $error, Document $document): void
	{
		$serviceStatus = $error->getCustomData()['has'] ?? null;
		if (!in_array($serviceStatus, self::SERVICE_DIVERGENT_STATUSES, true))
		{
			return;
		}

		$this->logger->error(
			'Document {uid} state diverged: portal {portalStatus}, signing service {serviceStatus}',
			[
				'uid' => $document->uid,
				'portalStatus' => $document->status,
				'serviceStatus' => $serviceStatus,
			],
		);
	}

	private function isWaitingForBlankUpload(Main\Error $error): bool
	{
		if (
			$error->getCode() !== 'SIGN_DOCUMENT_INCORRECT_STATUS'
			|| ($error->getCustomData()['has'] ?? null) !== DocumentStatus::NEW
		)
		{
			return false;
		}

		// Waiting is justified only while somebody is still sending the blanks of this group. A
		// chain that gave up removes itself, and a document outside a group never had one: the
		// sending there happens inside the request, so `new` means a failure to report. Without
		// this the configure agent would reschedule itself for good on a blank the service refuses.
		return $this->documentAgentService->hasUploadGroupBlanksChain((int)$this->document->groupId);
	}

	private function startOrProgress(FillFieldsResult $fillResult): Main\Result|ConfigureResult
	{
		if (!$fillResult->completed)
		{
			return new ConfigureResult(false);
		}

		if (!$this->getSigningStartLock())
		{
			return new ConfigureResult(false);
		}

		$startResult = (new SigningStart($this->document->uid))->launch();
		$this->releaseSigningStartLock();
		if ($startResult->isSuccess())
		{
			return new ConfigureResult(true);
		}

		return $startResult;
	}

	/**
	 * Zero timeout: a document another configure is already running for is left to that one and
	 * picked up on the next pass, instead of holding this request for the whole external exchange.
	 */
	private function getConfigureLock(): bool
	{
		return Main\Application::getConnection()->lock($this->getConfigureLockName(), 0);
	}

	private function releaseConfigureLock(): bool
	{
		return Main\Application::getConnection()->unlock($this->getConfigureLockName());
	}

	private function getConfigureLockName(): string
	{
		return self::CONFIGURE_LOCK_NAME_PREFIX . $this->document->uid;
	}

	private function getSigningStartLock(): bool
	{
		return Main\Application::getConnection()->lock($this->getLockName());
	}

	private function releaseSigningStartLock(): bool
	{
		return Main\Application::getConnection()->unlock($this->getLockName());
	}

	private function getLockName(): string
	{
		return "sign_signing_start_{$this->document->uid}";
	}
}
