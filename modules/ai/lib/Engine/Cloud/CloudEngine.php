<?php
declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Engine;
use Bitrix\AI\Engine\Service\RuleService;
use Bitrix\AI\Cache\EngineResultCache;
use Bitrix\AI\Cloud;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Facade\Analytics;
use Bitrix\AI\Payload\Prompt;
use Bitrix\AI\Payload\Text;
use Bitrix\AI\QueueJob;
use Bitrix\AI\Result;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\AI\Engine\Cloud\EngineCloudError\Service\ExceededLimitService;
use function call_user_func;
use function is_callable;

abstract class CloudEngine extends Engine\Engine implements IEngine
{
	public const CLOUD_REGISTRATION_NOT_FOUND = 'CLOUD_REGISTRATION_DATA_NOT_FOUND';

	protected const AGREEMENT_CODE = 'AI_BOX_AGREEMENT';

	protected const ERROR_CODE_FORCE = 'ERROR_CODE_FORCE';

	abstract protected function getDefaultModel(): string;

	public static function getEngineCodeProvider(): string
	{
		return static::ENGINE_CODE;
	}

	public function checkLimits(): bool
	{
		return false;
	}

	final protected function exportPromptData(): array
	{
		$data = [
			'moduleId' => $this->getContext()->getModuleId(),
			'contextId' => $this->getContext()->getContextId(),
			'userId' => $this->getContext()->getUserId(),
		];
		try
		{
			$payload = $this->getPayload();
			if ($payload instanceof Text)
			{
				$role = $payload->getRole();
				if ($role)
				{
					$data['role'] = $role->getCode();
				}
			}
			if ($payload instanceof Prompt)
			{
				$data['promptCode'] = $payload->getPromptCode();
				$data['promptCategory'] = $payload->getPromptCategory();
			}
		}
		catch (SystemException)
		{
		}

		return $data;
	}

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	final public function completionsInQueue(): void
	{
		if ($this->payload->shouldUseCache())
		{
			$this->setCache(true);
		}
		$params = $this->makeRequestParams();
		$this->queueJob = QueueJob::createWithinFromEngine($this)->register();

		$cacheManager = new EngineResultCache($this->queueJob->getCacheHash());

		if ($this->isCache() && ($response = $cacheManager->getExists()))
		{
			$this->onResponseSuccess($response, $cacheManager, true);

			return;
		}

		$cloudConfiguration = new Cloud\Configuration();
		$registrationDto = $cloudConfiguration->getCloudRegistrationData();
		if (!$registrationDto)
		{
			$this->queueJob->cancel();

			call_user_func(
				$this->onErrorCallback,
				new Error(
					Loc::getMessage('AI_CLOUD_ENGINE_ERROR_CLOUD_REGISTRATION'),
					self::CLOUD_REGISTRATION_NOT_FOUND
				)
			);

			return;
		}

		$sendQuery = new Cloud\SendQuery($registrationDto->serverHost);
		$responseResult = $sendQuery->queue([
			'provider' => $this->getCode(),
			'moduleId' => $this->getContext()->getModuleId(),
			'promptData' => $this->exportPromptData(),

			'callbackUrl' => $this->queueJob->getCallbackUrl(),
			'errorCallbackUrl' => $this->queueJob->getErrorCallbackUrl(),
			'url' => $this->getCompletionsUrl(),
			'params' => $params,
		]);

		$this->updateSettingsByRules($responseResult);

		$errorCollection = $responseResult->getErrorCollection();

		$forceError = $this->getForceErrorFromCollection($errorCollection);
		if (!empty($forceError))
		{
			$this->queueJob->cancel();
			$this->callErrorCallback($forceError);
			return;
		}

		if ($responseResult->isSuccess())
		{
			if (is_callable($this->onSuccessCallback))
			{
				call_user_func(
					$this->onSuccessCallback,
					new Result(true, ''),
					$this->queueJob->getHash(),
				);
			}

			return;
		}
		if (!is_callable($this->onErrorCallback))
		{
			return;
		}

		/** @see \Bitrix\AiProxy\Service\Queue\QueueService::ERROR_CODE_EXCEEDED_LIMIT */
		$errorLimit = $errorCollection->getErrorByCode('exceeded_limit');
		if ($errorLimit !== null)
		{
			$this->initErrorLimit($errorLimit);
			return;
		}

		$error = $errorCollection[0];
		$this->onResponseError($error->getMessage(), (string)$error->getCode());
	}

	protected function callErrorCallback(array $forceError): void
	{
		if (is_callable($this->onErrorCallback))
		{
			call_user_func(
				$this->onErrorCallback,
				new Error(
					$forceError['msgPlainText'] ?? '',
					static::ERROR_CODE_FORCE,
					[
						'code' => (string)($forceError['code'] ?? ''),
						'msgPlainText' => (string)($forceError['msgPlainText'] ?? ''),
						'msgHtml' => (string)($forceError['msgHtml'] ?? ''),
						'sliderCode' => (string)($forceError['sliderCode'] ?? ''),
						'msgBBCode' => (string)($forceError['msgBBCode'] ?? ''),
					]
				)
			);
		}
	}

	protected function getForceErrorFromCollection(ErrorCollection $errorCollection): array
	{
		if ($errorCollection->isEmpty())
		{
			return [];
		}

		$forceError = [];
		foreach ($errorCollection->toArray() as $error)
		{
			if (!($error instanceof Error) || empty($error->getCustomData()))
			{
				continue;
			}

			$forceErrorData = $error->getCustomData();

			/** @see \Bitrix\AiProxy\Service\Queue\QueueService::ERROR_CODE_FORCE_ERROR */
			if (!empty($forceErrorData['forceError']))
			{
				/** @see \Bitrix\AiProxy\Dto\ForceErrorDto::toArray() */
				$forceError = $forceErrorData['forceError'];
				break;
			}
		}

		if (
			empty($forceError['msgPlainText'])
			&& empty($forceError['msgHtml'])
			&& empty($forceError['msgBBCode'])
			&& empty($forceError['sliderCode'])
		)
		{
			return [];
		}

		return $forceError;
	}

	protected function updateSettingsByRules(Main\Result $responseResult): void
	{
		$resultData = $responseResult->getData();

		$data = [];
		if (!empty($resultData['rules']) && is_array($resultData['rules']))
		{
			$data = $resultData['rules'];
		}

		if (!empty($data))
		{
			ServiceLocator::getInstance()
				->get(RuleService::class)
				->initUpdate($data)
			;
		}
	}

	final public function completions(): void
	{
		//todo Liskov substitution principle violation
		throw new SystemException('Direct completions are not supported for cloud engines');
	}

	/**
	 * Returns name of the engine.
	 * Will be used in the UI.
	 * @return string
	 */
	public function getName(): string
	{
		return $this->getModel();
	}

	protected function getModel(): string
	{
		$name = new Engine\Cloud\EngineProperty\Model(
			static::class,
			$this->getContext()->getModuleId(),
		);

		return $name->getValue() ?? $this->getDefaultModel();
	}

	/**
	 * @throws LoaderException
	 */
	protected function initErrorLimit(Error $errorLimit): void
	{
		Analytics::sendAiQueryLimitEvent(
			$errorLimit->getCustomData()['errorLimitType'] ?? $errorLimit->getCode(),
			$this->getContext()->getModuleId(),
		);

		$this->queueJob->cancel();

		call_user_func(
			$this->onErrorCallback,
			$this->getExceededLimitService()->mapExceededLimitError($errorLimit)
		);
	}

	protected function getExceededLimitService()
	{
		return ServiceLocator::getInstance()->get(ExceededLimitService::class);
	}
}
