<?php
declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Container;
use Bitrix\AI\Engine;
use Bitrix\AI\Cache\EngineResultCache;
use Bitrix\AI\Cloud;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Integration\Baas\BaasTokenService;
use Bitrix\AI\Payload\Prompt;
use Bitrix\AI\Payload\Text;
use Bitrix\AI\QueueJob;
use Bitrix\AI\Result;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\UI\Util;
use Bitrix\Main\Loader;
use function call_user_func;
use function is_callable;

abstract class CloudEngine extends Engine\Engine implements IEngine
{
	public const CLOUD_REGISTRATION_NOT_FOUND = 'CLOUD_REGISTRATION_DATA_NOT_FOUND';

	protected const AGREEMENT_CODE = 'AI_BOX_AGREEMENT';

	protected const SLIDER_CODE_REQUESTS = 'limit_copilot_requests_box';
	protected const SLIDER_CODE_BOOST = 'limit_boost_copilot_box';
	protected const SLIDER_CODE_BOX = 'limit_copilot_box';

	protected const ERROR_CODE_LIMIT_STANDARD = 'LIMIT_IS_EXCEEDED_MONTHLY';
	protected const ERROR_CODE_LIMIT_BAAS = 'LIMIT_IS_EXCEEDED_BAAS';
	protected const ERROR_CODE_RATE_LIMIT = 'RATE_LIMIT';

	protected const RATE_LIMIT_HELP_CODE = '24736310';

	public function checkLimits(): bool
	{
		return false;
	}

	final protected function exportPromtData(): array
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
			'promptData' => $this->exportPromtData(),

			'callbackUrl' => $this->queueJob->getCallbackUrl(),
			'errorCallbackUrl' => $this->queueJob->getErrorCallbackUrl(),
			'url' => $this->getCompletionsUrl(),
			'params' => $this->makeRequestParams(),
		]);

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

		$errorCollection = $responseResult->getErrorCollection();
		/** @see \Bitrix\AiProxy\Controller\Query::ERROR_CODE_EXCEEDED_LIMIT */
		$errorLimit = $errorCollection->getErrorByCode('exceeded_limit');
		if ($errorLimit !== null)
		{
			$this->initErrorLimit($errorLimit);
			return;
		}

		$error = $errorCollection[0];
		$this->onResponseError($error->getMessage(), (string)$error->getCode());
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
		$this->queueJob->cancel();

		[$showSliderWithMsg, $sliderCode, $errorCode, $msgForIm] = $this->getErrorsLimitRules($errorLimit);

		$error = new Error(
			$errorCode === static::ERROR_CODE_RATE_LIMIT
			? Loc::getMessage(
				'AI_ENGINE_ERROR_RATE_LIMIT_IS_EXCEEDED',
				[
					'[helpdesklink]' => '<a href="' . $this->getLinkOnHelp() . '" target="blank">',
					'[/helpdesklink]' => '</a>',
				]
			)
			: Loc::getMessage('AI_ENGINE_ERROR_LIMIT_IS_EXCEEDED'),
			$errorCode,
			[
				'sliderCode' => $sliderCode,
				'showSliderWithMsg' => $showSliderWithMsg,
				'msgForIm' => $msgForIm,
			]
		);

		call_user_func(
			$this->onErrorCallback,
			$error
		);
	}

	/**
	 * @param Error $errorLimit
	 * @return array
	 */
	protected function getErrorsLimitRules(Error $errorLimit): array
	{
		$errorData = $errorLimit->getCustomData();
		$isAvailableBaas = $this->isAvailableBaas();
		$errorCode = $this->getErrorCode($errorData, $isAvailableBaas);
		$sliderCode = static::SLIDER_CODE_REQUESTS;

		$msgForIm = Loc::getMessage(
			'AI_ENGINE_ERROR_LIMIT_IS_EXCEEDED_WITH_MORE',
			[
				'#LINK#' => '/online/?FEATURE_PROMOTER=' . $sliderCode,
			]
		);

		if (empty($errorData['baasAvailable'])) {
			return [false, $sliderCode, $errorCode, $msgForIm];
		}

		$sliderCode = $isAvailableBaas ? static::SLIDER_CODE_BOOST : static::SLIDER_CODE_BOX;

		$msgForIm = Loc::getMessage(
			'AI_ENGINE_ERROR_LIMIT_BAAS',
			[
				'#LINK#' => '/online/?FEATURE_PROMOTER=' . $sliderCode,
			]
		);

		if ($errorCode === static::ERROR_CODE_RATE_LIMIT)
		{
			$msgForIm = Loc::getMessage(
				'AI_ENGINE_ERROR_IM_RATE_LIMIT_IS_EXCEEDED',
				[
					'#LINK#' => $this->getLinkOnHelp(),
				]
			);

			$sliderCode = 'redirect=detail&code=' . static::RATE_LIMIT_HELP_CODE;
		}

		return [!$isAvailableBaas, $sliderCode, $errorCode, $msgForIm];
	}

	protected function isAvailableBaas(): bool
	{
		/** @var BaasTokenService $baasTokenService */
		$baasTokenService = Container::init()->getItem(BaasTokenService::class);

		return $baasTokenService->isAvailable();
	}

	protected function getErrorCode(array $errorData, bool $isAvailableBaas)
	{
		if (empty($errorData['errorLimitType']))
		{
			return static::ERROR_CODE_LIMIT_STANDARD;
		}

		if ($errorData['errorLimitType'] === static::ERROR_CODE_RATE_LIMIT)
		{
			return static::ERROR_CODE_RATE_LIMIT;
		}

		if ($isAvailableBaas)
		{
			return static::ERROR_CODE_LIMIT_BAAS;
		}

		return $errorData['errorLimitType'];
	}

	private function getLinkOnHelp(): string
	{
		return Loader::includeModule('ui')
			? Util::getArticleUrlByCode(static::RATE_LIMIT_HELP_CODE)
			: 'https://helpdesk.bitrix24.ru/open/' . static::RATE_LIMIT_HELP_CODE;
	}

	abstract protected function getDefaultModel(): string;
}
