<?php
namespace Bitrix\Crm\Controller\Site;

use Bitrix\Crm\WebForm\Telemetry\Recorder;
use Bitrix\Crm\WebForm\Telemetry\RecorderInterface;
use Bitrix\Crm\WebForm\Telemetry\SubmissionClassifier;
use Bitrix\Crm\WebForm\Telemetry\SourceContextSigner;
use Bitrix\Main;
use Bitrix\Crm\WebForm;
use Throwable;

class Form extends Main\Engine\Controller
{
	/**
	 * Configure actions.
	 *
	 * @return array
	 */
	public function configureActions()
	{
		return [
			'fill' => [
				'prefilters' => [
					new Main\Engine\ActionFilter\HttpMethod(
						[
							Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
						]
					),
					new Main\Engine\ActionFilter\Csrf(false),
				]
			],
			'handleAnalytics' => [
				'prefilters' => [
					new Main\Engine\ActionFilter\HttpMethod(
						[
							Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
						]
					),
					new Main\Engine\ActionFilter\Csrf(false),
				],
				'postfilters' => [
					new Main\Engine\ActionFilter\Cors(),
				]
			],
			'get' => [
				'prefilters' => [
					new Main\Engine\ActionFilter\HttpMethod(
						[
							Main\Engine\ActionFilter\HttpMethod::METHOD_GET,
							Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
						]
					),
					new Main\Engine\ActionFilter\Csrf(false),
				]
			],
		];
	}

	/**
	 * Fill form action.
	 *
	 * @return Main\Engine\Response\Json
	 */
	public function fillAction()
	{
		try
		{
			$result = User::getJsonResponse([
				'result' => WebForm\Embed\Rest::fillForm(
					$this->getRequest()->toArray(),
					$this->getTelemetryRecorderSafely(),
				)
			]);
		}
		catch (\Exception $exception)
		{
			$result = User::getJsonResponse([
				'error' => true,
				'error_description' => $exception->getMessage(),
				'result' => []
			]);
		}

		return $result;
	}

	/**
	 * Get form details action.
	 *
	 * @return Main\Engine\Response\Json
	 */
	public function getAction()
	{
		try
		{
			$result = User::getJsonResponse([
				'result' => WebForm\Embed\Rest::getForm(Main\Context::getCurrent()->getRequest()->toArray())
			]);
		}
		catch (\Exception $exception)
		{
			$result = User::getJsonResponse([
				'error' => true,
				'error_description' => $exception->getMessage(),
				'result' => []
			]);
		}

		return $result;
	}

	/**
	 * Handle form analytics event.
	 *
	 * @param string $counter Counter Type.
	 * @param string $formId Form ID.
	 * @param string|null $attemptId Telemetry attempt ID.
	 * @param string|null $source Telemetry source.
	 * @param string|null $errorType Telemetry error type.
	 * @param string|null $sec Form security code.
	 * @param string|null $telemetrySign Signed telemetry context.
	 * @return Main\Engine\Response\Json
	 */
	public function handleAnalyticsAction(
		string $counter,
		string $formId,
		?string $attemptId = null,
		?string $source = null,
		?string $errorType = null,
		?string $sec = null,
		?string $telemetrySign = null,
	): Main\Engine\Response\Json
	{
		try
		{
			$result = new Main\Engine\Response\Json([
				'error' => false
			]);

			if ($counter === 'view')
			{
				WebForm\Form::incCounterView($formId);
			}
			elseif ($counter === 'start')
			{
				WebForm\Form::incCounterStartFill($formId);
			}

			$this->recordTelemetry($counter, $formId, $attemptId, $errorType, $sec, $telemetrySign);
		}

		catch (\Exception $exception)
		{
			$result = User::getJsonResponse([
				'error' => true,
				'error_description' => $exception->getMessage(),
				'result' => []
			]);
		}

		return $result;
	}

	protected function getTelemetryRecorder(): RecorderInterface
	{
		return new Recorder();
	}

	private function getTelemetryRecorderSafely(): ?RecorderInterface
	{
		try
		{
			return $this->getTelemetryRecorder();
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function recordTelemetry(
		string $counter,
		string $formId,
		?string $attemptId,
		?string $errorType,
		?string $securityCode,
		?string $telemetrySign,
	): void
	{
		if (!$this->isUuidV4($attemptId) || (int)$formId <= 0)
		{
			return;
		}

		try
		{
			if (!$this->isTelemetryFormAllowed((int)$formId, $securityCode))
			{
				return;
			}

			$recorder = $this->getTelemetryRecorder();
			if ($counter === 'start' || $counter === 'telemetry_start')
			{
				$recorder->start(
					$attemptId,
					(int)$formId,
					$this->getTrustedTelemetrySource($telemetrySign, (int)$formId),
					$this->getRequest()->getUserAgent(),
				);
			}
			elseif ($counter === 'error' && in_array($errorType, ['validation', 'other'], true))
			{
				$recorder->markError($attemptId, $errorType);
			}
		}
		catch (Throwable)
		{
		}
	}

	private function getTrustedTelemetrySource(?string $telemetrySign, int $formId): string
	{
		if (!is_string($telemetrySign) || $telemetrySign === '')
		{
			return SubmissionClassifier::SOURCE_UNKNOWN;
		}

		return (new SourceContextSigner())->verify($telemetrySign, $formId)
			?? SubmissionClassifier::SOURCE_UNKNOWN
		;
	}

	private function isTelemetryFormAllowed(int $formId, ?string $securityCode): bool
	{
		if ($securityCode === null || $securityCode === '')
		{
			return false;
		}

		$form = new WebForm\Form();
		if (!$form->loadOnlySecurityData($formId))
		{
			return false;
		}

		return $form->isActive() && $form->checkSecurityCode($securityCode);
	}

	private function isUuidV4(?string $attemptId): bool
	{
		return is_string($attemptId) && preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$attemptId,
		) === 1;
	}
}
