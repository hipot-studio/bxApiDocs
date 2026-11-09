<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\BaseConnector;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\GenerationAbortedException;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\GenerationTimeSliceException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Step\Request\AbstractAiImageRequest;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Markup\Image\GenerationProgress;
use Bitrix\Landing\Markup\Image\ImagePlan;
use Bitrix\Landing\Markup\Image\Placeholder;
use Bitrix\Landing\Markup\Intake;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Main\Config\Option;

/**
 * Generation of the pictures declared by a markup package (ADR 3.2, 3.4): the step of the creation
 * and of the edit that stands between the topic moderation and the build of the version.
 *
 * The work list is the generate declarations that inherit nothing from the current version
 * (ImagePlan); an empty list ends the step without a request. A batch of requests is in flight at
 * most, the next ones leave as answers come, the whole step expires by one timeout from its start;
 * the course lives in the generation state (GenerationProgress), not in the entity table, and
 * nothing is written to a block - the intake copies the generated files when the package is applied.
 *
 * A failure of the model fails its picture only: the error of an answer, the expiry of a request,
 * the deadline of the step; the unavailable model and the spent AI limit fail the rest. The step
 * blocks the scenario until every picture has its outcome and fails only on an own technical
 * failure, recorded as the neutral text of the markup steps.
 */
class RequestMarkupPackageImages extends AbstractAiImageRequest
{
	public const OPTION_BATCH = 'landing_markup_image_batch';
	public const OPTION_TIMEOUT = 'landing_markup_image_timeout';

	private const LOG_DATA_TYPE = 'AI request: markup package image';

	private ?GenerationProgress $progress = null;
	private ?MarkupSiteState $state = null;
	private ?Log $log = null;

	/** @var array<string, array{token: string, action: string, prompt: string, aspect: string}>|null */
	private ?array $planByToken = null;

	public function __construct()
	{
		parent::__construct();
		$this->initializeConnector();
	}

	public static function getConnectorClass(): string
	{
		return AI\Image::class;
	}

	/**
	 * The pictures are outside of the request quota preflight of the scenario.
	 */
	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return null;
	}

	/**
	 * The scenario waits on the step: the pointer stays until every picture has its outcome.
	 */
	public function isAsync(): bool
	{
		return false;
	}

	public function execute(): bool
	{
		if (!isset($this->generation, $this->siteData))
		{
			return false;
		}

		try
		{
			$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

			return $this->doExecute();
		}
		catch (GenerationAbortedException $exception)
		{
			// the cancel of the user is no failure of the step: its text is written by the scenario
			throw $exception;
		}
		catch (GenerationTimeSliceException $exception)
		{
			// the pause by the time budget is no failure either: the next run continues the step
			throw $exception;
		}
		catch (GenerationException $exception)
		{
			$this->getState()->setLastError($exception->getMessage());

			throw $exception;
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedFailure($exception);
			$this->getState()->setLastError(MarkupTaskStep::TECHNICAL_FAILURE_MESSAGE);

			throw new GenerationException(GenerationErrors::dataValidation, MarkupTaskStep::TECHNICAL_FAILURE_MESSAGE);
		}
	}

	public function isStarted(): bool
	{
		return isset($this->generation) && $this->getProgress()->isStarted();
	}

	public function isFinished(): bool
	{
		return isset($this->generation) && $this->getProgress()->isComplete();
	}

	private function doExecute(): bool
	{
		$progress = $this->getProgress();
		if (!$progress->isStarted())
		{
			$tokens = array_keys($this->getPlanByToken());
			$progress->start($tokens, $this->now());
			$this->saveProgress();
			$this->getLog()->add('Markup package images: ' . count($tokens) . ' picture(s) to generate');
		}
		if ($progress->isComplete())
		{
			return true;
		}

		if ($progress->expire($this->now()))
		{
			$this->saveProgress();
			$this->getLog()->add('Markup package images: the step timed out, the rest is failed');

			return true;
		}

		if (!$this->isConnectorAvailable())
		{
			$progress->failRest(GenerationProgress::REASON_UNAVAILABLE);
			$this->saveProgress();
			$this->getLog()->add('Markup package images: the model is unavailable, the rest is failed');

			return true;
		}

		return parent::execute();
	}

	/**
	 * The pictures to send now, as entities of the base step: the token is the node code.
	 * @return RequestEntityDto[]
	 */
	protected function getEntitiesToRequest(): array
	{
		$entities = [];
		$plan = $this->getPlanByToken();
		foreach ($this->getProgress()->nextToSend() as $token)
		{
			$item = $plan[$token] ?? null;
			if ($item === null)
			{
				continue;
			}
			$entities[] = new RequestEntityDto(
				null,
				null,
				$token,
				null,
				$item['prompt'],
				null,
				['format' => Placeholder::resolveAspect($item['aspect'])],
			);
		}

		return $entities;
	}

	protected function sendRequests(): bool
	{
		$progress = $this->getProgress();
		foreach ($this->getEntitiesToRequest() as $entity)
		{
			$this->assertTimerNotExceeded();
			$this->assertNotAborted();

			try
			{
				$request = $this->sendEntityRequestWithRetry(
					$entity,
					fn(): ?Request => $this->sendTokenRequest($entity),
				);
			}
			catch (GenerationException $exception)
			{
				if ($exception->getErrorCode() !== GenerationErrors::requestQuotaExceeded)
				{
					throw $exception;
				}

				$progress->failRest(GenerationProgress::REASON_LIMIT, $this->resolveLimit($exception));
				$this->saveProgress();
				$this->getLog()->add('Markup package images: the AI limit is spent, the rest is failed');

				return true;
			}

			if ($request === null)
			{
				continue;
			}

			$requestId = (int)$request->getId();
			$this->requests[$requestId] = $request;
			$progress->markSent((string)$entity->nodeCode, $requestId);
			$this->saveProgress();
		}

		return true;
	}

	/**
	 * Every answered request settles its picture: a file of the answer - generated, anything else - failed.
	 */
	protected function applyResponses(): bool
	{
		$progress = $this->getProgress();
		$changed = false;
		foreach ($this->requests as $request)
		{
			if (!$request->isReceived() || $request->isApplied())
			{
				continue;
			}

			$this->assertTimerNotExceeded();
			$this->assertNotAborted();

			$token = $progress->findTokenByRequest((int)$request->getId());
			if ($token !== null)
			{
				$aiFileId = $request->getError() === null ? $this->resolveGeneratedFileId($request) : 0;
				if ($aiFileId > 0)
				{
					$progress->markGenerated($token, $aiFileId);
				}
				else
				{
					$progress->markFailed($token, GenerationProgress::REASON_ERROR);
					$this->getLog()->add(
						'Markup package images: the picture "' . $token . '" failed: '
						. ($request->getError()?->message ?? 'no file in the answer')
					);
				}
				// the outcome goes to the state before the request is closed: a pause of the run between
				// the two would leave a closed request whose picture nothing settles any more
				$this->saveProgress();
			}
			$request->setApplied();
			$changed = true;
		}

		return $changed;
	}

	/**
	 * An expired request fails its picture by the timeout; the request itself is deleted.
	 */
	protected function handleExpiredRequest(Request $request): bool
	{
		$token = $this->getProgress()->findTokenByRequest((int)$request->getId());
		if ($token !== null)
		{
			$this->getProgress()->markFailed($token, GenerationProgress::REASON_TIMEOUT);
			$this->saveProgress();
		}
		$request->setDeleted();

		return true;
	}

	/**
	 * A picture that could not be sent is a failed one in the state: no row of the entity table.
	 */
	protected function handleEntitySendFailure(RequestEntityDto $entity, string $reason): bool
	{
		$this->getProgress()->markFailed((string)$entity->nodeCode, GenerationProgress::REASON_ERROR);
		$this->saveProgress();
		$this->getLog()->add('Markup package images: the picture "' . $entity->nodeCode . '" was not sent: ' . $reason);

		return true;
	}

	protected function isSendFailureLimitEnabled(): bool
	{
		return false;
	}

	/**
	 * The step waits for the model between the runs, and the cancel of the user (Generation::cancel())
	 * may land while a run holds the generation: before every request it sends and every answer it
	 * applies the step reads the abort back, so a cancelled job asks the model for nothing more and
	 * the run ends with the abort instead of going on to the build of the site.
	 *
	 * @throws GenerationAbortedException When the generation was cancelled during the run.
	 */
	private function assertNotAborted(): void
	{
		if ($this->generation->isAbortedOutside())
		{
			$this->getLog()->add('Markup package images: the job was cancelled, nothing more is asked');

			throw new GenerationAbortedException();
		}
	}

	protected function getState(): MarkupSiteState
	{
		return $this->state ??= new MarkupSiteState($this->generation);
	}

	protected function getLog(): Log
	{
		return $this->log ??= new Log($this->generation->getId());
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}

	protected function createRequest(): Request
	{
		return new Request((int)$this->generation->getId(), (int)$this->stepId);
	}

	/**
	 * Manifest of the current version of the edited page; null at the creation of a site.
	 */
	protected function resolveCurrentManifest(): ?Manifest
	{
		$landingId = (int)($this->siteData->getLandingId() ?? 0);
		if ($landingId <= 0)
		{
			return null;
		}

		// an unpublished block is visible to the editor only; the manifest column is read by the block itself
		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = Landing::createInstance($landingId, ['check_permissions' => false]);
			$block = $landing->exist() ? Intake::findCustomBlock($landing) : null;
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}

		return $block ? Manifest::fromJson((new Block($block->getId()))->getMarkupManifest()) : null;
	}

	protected function resolveBatch(): int
	{
		return max(1, (int)Option::get('landing', self::OPTION_BATCH, (string)GenerationProgress::DEFAULT_BATCH));
	}

	protected function resolveTimeout(): int
	{
		return max(1, (int)Option::get('landing', self::OPTION_TIMEOUT, (string)GenerationProgress::DEFAULT_TIMEOUT));
	}

	protected function now(): int
	{
		return time();
	}

	private function getProgress(): GenerationProgress
	{
		return $this->progress ??= GenerationProgress::fromState(
			$this->getState()->getImageProgress(),
			$this->resolveBatch(),
			$this->resolveTimeout(),
		);
	}

	private function saveProgress(): void
	{
		$this->getState()->setImageProgress($this->getProgress()->toState());
		$this->changed = true;
	}

	/**
	 * @return array<string, array{token: string, action: string, prompt: string, aspect: string}>
	 *     Generate items by token.
	 * @throws GenerationException When no package was submitted.
	 */
	private function getPlanByToken(): array
	{
		if ($this->planByToken !== null)
		{
			return $this->planByToken;
		}

		$package = $this->getState()->getPackageWithoutAssets();
		if ($package === null)
		{
			throw new GenerationException(GenerationErrors::dataValidation, 'No markup package was submitted.');
		}

		$this->planByToken = [];
		$plan = ImagePlan::build($package->getImageDeclaration(), $this->resolveCurrentManifest());
		foreach ($plan->getItems() as $item)
		{
			if ($item['action'] === ImagePlan::ACTION_GENERATE)
			{
				$this->planByToken[$item['token']] = $item;
			}
		}

		return $this->planByToken;
	}

	/**
	 * A substituted connector of another class is taken as available: only the engine connector tells.
	 */
	private function isConnectorAvailable(): bool
	{
		if (!isset($this->connector))
		{
			return false;
		}

		return !($this->connector instanceof BaseConnector) || $this->connector->isAvailable();
	}

	private function sendTokenRequest(RequestEntityDto $entity): ?Request
	{
		$prompt = new Prompt((string)$entity->prompt);
		$prompt->setMarkers($entity->promptMarkers ?? []);

		$request = $this->createRequest();
		$this->getLog()->addRequestPrompt(
			(int)$this->stepId,
			self::LOG_DATA_TYPE . ' "' . $entity->nodeCode . '"',
			$prompt->getCode(),
		);

		return $request->send($prompt, $this->connector) ? $request : null;
	}

	private function resolveGeneratedFileId(Request $request): int
	{
		$result = $request->getResult();
		$url = is_array($result) && $result !== [] ? trim((string)reset($result)) : '';
		if ($url === '')
		{
			return 0;
		}

		return (int)($this->resolveImageFileData($url)['id'] ?? 0);
	}

	/**
	 * The spent limit for the answer of the tools, worded by the same helper as the ordinary limit refusal.
	 * @return array{sliderCode: ?string, text: ?string}
	 */
	private function resolveLimit(GenerationException $exception): array
	{
		$text = (string)($exception->getParams()['errorText'] ?? '');
		$limit = AiSiteGenerationHelper::normalizeLimitError([
			'type' => 'limit',
			'text' => $text !== '' ? $text : $exception->getMessage(),
		]);

		return ['sliderCode' => $limit['sliderCode'], 'text' => $limit['text']];
	}

	private function logUnexpectedFailure(\Throwable $exception): void
	{
		try
		{
			$this->getLog()->add(
				'Markup step ' . (int)($this->stepId ?? 0) . ' of generation ' . (int)$this->generation->getId()
				. ' failed: ' . get_class($exception) . ': ' . $exception->getMessage()
				. ' at ' . $exception->getFile() . ':' . $exception->getLine()
			);
		}
		catch (\Throwable)
		{
			// the log loses the line, the failure is recorded anyway
		}
	}
}
