<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\ActiveRun;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\DocumentSource;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\PrepareRequest;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\RunForm;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\StartRequest;
use Bitrix\Bizproc\Public\Service\TestRun\ErrorCode;
use Bitrix\Bizproc\Public\Service\TestRun\TestRunService;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * A test run of a template from one of its trigger nodes. The sequence itself lives in {@see TestRunService};
 * the link to the document of a run is an address of the interface and so is left to the controller.
 */
class TestRun extends JsonController
{
	private const DOCUMENT_ID_PARTS = 3;

	private ?TestRunService $testRunService = null;
	private ?TemplateAccessService $accessService = null;

	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	public function configureActions(): array
	{
		$postOnly = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return [
			'status' => $postOnly,
			'prepare' => $postOnly,
			'start' => $postOnly,
			'finish' => $postOnly,
		];
	}

	public function statusAction(int $templateId): ?array
	{
		if ($this->refuseForbidden($templateId))
		{
			return null;
		}

		$result = $this->testRunService()->status($this->getUserId(), $templateId);
		if (!$result->isSuccess())
		{
			$this->addServiceErrors($result);

			return null;
		}

		$activeRun = $result->getData()['activeRun'] ?? null;

		return [
			'activeRun' => $activeRun instanceof ActiveRun ? $this->withDocumentUrl($activeRun->toArray()) : null,
		];
	}

	public function prepareAction(int $templateId, string $blockId): ?array
	{
		if ($this->refuseForbidden($templateId))
		{
			return null;
		}

		$result = $this->testRunService()->prepare(
			new PrepareRequest($this->getUserId(), $templateId, $blockId),
		);
		if (!$result->isSuccess())
		{
			$this->addServiceErrors($result);

			return null;
		}

		/** @var RunForm $form */
		$form = $result->getData()['form'];

		return ['form' => $form->toArray()];
	}

	/**
	 * @param array $document `{source?, documentId}` to run on an existing document, `{source?, fields}` to create one,
	 *     empty for the document-less type
	 */
	public function startAction(
		int $templateId,
		string $blockId,
		int $draftId,
		int $draftRevision,
		array $values = [],
		array $document = [],
	): ?array
	{
		if ($this->refuseForbidden($templateId))
		{
			return null;
		}

		$documentId = $document['documentId'] ?? null;
		$documentFields = is_array($document['fields'] ?? null) ? $document['fields'] : [];

		$result = $this->testRunService()->start(new StartRequest(
			userId: $this->getUserId(),
			templateId: $templateId,
			blockId: $blockId,
			draftId: $draftId,
			draftRevision: $draftRevision,
			values: $values,
			documentSource: $this->documentSourceOf($document),
			documentFields: $documentFields,
			documentId: is_scalar($documentId) ? (string)$documentId : null,
		));
		if (!$result->isSuccess())
		{
			$this->addServiceErrors($result);

			return null;
		}

		return $this->withDocumentUrl($result->getData());
	}

	/** A run is addressed by its session, so ownership of the session replaces the right to publish here. */
	public function finishAction(int $sessionId): ?array
	{
		if ($this->refuseForbidden(null))
		{
			return null;
		}

		$result = $this->testRunService()->finish($this->getUserId(), $sessionId);
		if (!$result->isSuccess())
		{
			$this->addServiceErrors($result);

			return null;
		}

		return [];
	}

	/**
	 * A disabled feature and a missing right answer the same way, so neither the rollout of the feature nor
	 * the existence of the template can be read from the answer.
	 *
	 * @param int|null $templateId null where the action addresses a session and not a template
	 */
	private function refuseForbidden(?int $templateId): bool
	{
		$allowed = Feature::instance()->isTestRunAvailable()
			&& ($templateId === null || $this->accessService()->canPublish($templateId, $this->getUserId()))
		;

		if ($allowed)
		{
			return false;
		}

		$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

		return true;
	}

	private function documentSourceOf(array $document): string
	{
		if (is_string($document['source'] ?? null))
		{
			return $document['source'];
		}

		if (isset($document['documentId']))
		{
			return DocumentSource::SELECT;
		}

		return isset($document['fields']) ? DocumentSource::CREATE : DocumentSource::NONE;
	}

	private function addServiceErrors(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			if (!is_array($customData))
			{
				$this->addError($error);

				continue;
			}

			if ($error->getCode() === ErrorCode::ActiveRunExists->value)
			{
				$this->addError(new Error($error->getMessage(), $error->getCode(), [
					'activeRun' => $this->withDocumentUrl($customData),
				]));

				continue;
			}

			if ($error->getCode() === ErrorCode::StartFailed->value)
			{
				$this->addError(
					new Error($error->getMessage(), $error->getCode(), $this->withDocumentUrl($customData)),
				);

				continue;
			}

			$this->addError($error);
		}
	}

	private function withDocumentUrl(array $run): array
	{
		$run['documentUrl'] = $this->documentUrl($run['documentId'] ?? null);

		return $run;
	}

	private function documentUrl(mixed $documentId): ?string
	{
		if (!is_array($documentId) || count($documentId) !== self::DOCUMENT_ID_PARTS)
		{
			return null;
		}

		foreach ($documentId as $part)
		{
			if (\CBPHelper::isEmptyValue($part))
			{
				return null;
			}
		}

		$url = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentAdminPage($documentId);

		return $url === '' ? null : $url;
	}

	private function testRunService(): TestRunService
	{
		return $this->testRunService ??= new TestRunService();
	}

	private function accessService(): TemplateAccessService
	{
		return $this->accessService ??= new TemplateAccessService();
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
