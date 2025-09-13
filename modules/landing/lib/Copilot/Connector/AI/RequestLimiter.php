<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\AI\Cloud;
use Bitrix\AI\Context;
use Bitrix\AI\Limiter\Enums\TypeLimit;
use Bitrix\AI\Limiter\LimitControlService;
use Bitrix\AI\Limiter\LimitControlBoxService;
use Bitrix\AI\Limiter\Usage;
use Bitrix\AI\Limiter\Enums\ErrorLimit;
use Bitrix\Baas\Baas;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\UI\Util;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class RequestLimiter
 *
 * Handles checking and messaging for request quotas to AI services (CoPilot) within the Landing module.
 * Supports both cloud (Bitrix24 module present) and box environments.
 * Determines if request limits are exceeded and returns localized error messages or null if within limits.
 */
class RequestLimiter
{
	/** @see \Bitrix\AI\Engine::ERRORS (key 'LIMIT_IS_EXCEEDED') */
	protected const ERROR_CODE_LIMIT_CLOUD = 'LIMIT_IS_EXCEEDED';
	/** @see \Bitrix\AI\Engine\Cloud\CloudEngine::ERROR_CODE_LIMIT_BAAS */
	protected const ERROR_CODE_LIMIT_BAAS_CLOUD = 'LIMIT_IS_EXCEEDED_BAAS';
	protected const ERROR_CODE_RATE_LIMIT = 'RATE_LIMIT';
	protected const ERROR_CODE_DAILY = 'LIMIT_IS_EXCEEDED_DAILY';
	protected const ERROR_CODE_MONTHLY = 'LIMIT_IS_EXCEEDED_MONTHLY';

	/**
	 * Slider feature promoter codes for various limit messages
	 * @var string[]
	 */
	protected const SLIDER_CODES = [
		'BOOST_COPILOT' => 'limit_boost_copilot',
		'DAILY' => 'limit_copilot_max_number_daily_requests',
		'MONTHLY' => 'limit_copilot_requests',
		'BOOST_COPILOT_BOX' => 'limit_boost_copilot_box',
		'REQUEST_BOX' => 'limit_copilot_requests_box',
		'BOX' => 'limit_copilot_box',
	];

	/**
	 * Helpdesk article codes for support links
	 * @var string[]
	 */
	protected const HELPDESK_CODES = [
		'RATE' => '24736310',
	];

	/**
	 * Promo limit codes matching Usage::PERIODS values
	 * @var string[]
	 *
	 * @see Usage::PERIODS
	 */
	protected const PROMO_LIMIT_CODES = [
		'DAILY' => 'Daily',
		'MONTHLY' => 'Monthly',
	];

	/**
	 * Checks whether an AI service error represents a quota exceed.
	 *
	 * @param Error $error Error instance returned from the AI service.
	 *
	 * @return string|null Localized error message if limit exceeded, or null otherwise.
	 */
	public function getTextFromError(Error $error): ?string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return $this->getTextFromLimitCloudError($error);
		}

		return $this->getTextFromLimitBoxError($error);
	}

	/**
	 * Handles cloud-specific AI error codes for quota limits.
	 *
	 * @param Error $error Error object from the cloud AI engine.
	 *
	 * @return string|null Localized message for BAAS, daily, monthly or promo limits, or null if not a quota error.
	 */
	protected function getTextFromLimitCloudError(Error $error): ?string
	{
		$errorCode = $error->getCode();

		//right 2 in board
		if ($errorCode === self::ERROR_CODE_LIMIT_BAAS_CLOUD)
		{
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_BAAS',
				self::SLIDER_CODES['BOOST_COPILOT'],
			);
		}

		if (str_starts_with($errorCode, self::ERROR_CODE_LIMIT_CLOUD))
		{
			//right 1 in board
			if (Loader::includeModule('baas') && Baas::getInstance()->isAvailable())
			{
				return self::getLimitMessage(
					'LANDING_REQUEST_LIMITER_ERROR_PROMO',
					self::SLIDER_CODES['BOOST_COPILOT'],
				);
			}

			//left 1 in board
			if ($errorCode === self::ERROR_CODE_DAILY)
			{
				return self::getLimitMessage(
					'LANDING_REQUEST_LIMITER_ERROR_DAILY',
					self::SLIDER_CODES['DAILY'],
				);
			}

			//left 2 in board
			if ($errorCode === self::ERROR_CODE_MONTHLY)
			{
				return self::getLimitMessage(
					'LANDING_REQUEST_LIMITER_ERROR_MONTHLY',
					self::SLIDER_CODES['MONTHLY'],
				);
			}
		}

		return null;
	}

	/**
	 * Handles box AI error codes for quota limits.
	 *
	 * @param Error $error Error object containing code and optional custom data.
	 *
	 * @return string Localized message for rate, BAAS, monthly or promo limits.
	 */
	protected function getTextFromLimitBoxError(Error $error): string
	{
		$customData = $error->getCustomData();
		$errorCode = $error->getCode();

		if ($errorCode === self::ERROR_CODE_RATE_LIMIT)
		{
			//top in board
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_RATE',
				null,
				self::HELPDESK_CODES['RATE'],
			);
		}

		if (
			 isset($customData['showSliderWithMsg'])
			&& $errorCode === self::ERROR_CODE_LIMIT_BAAS_CLOUD
		)
		{
			//right 2 in board
			if ($customData['showSliderWithMsg'] === true)
			{
				return self::getLimitMessage(
					'LANDING_REQUEST_LIMITER_ERROR_BAAS',
					self::SLIDER_CODES['BOOST_COPILOT_BOX'],
				);
			}

			//left 1 in board
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_MONTHLY',
				self::SLIDER_CODES['REQUEST_BOX'],
			);
		}

		//right 1 in board
		return self::getLimitMessage(
			'LANDING_REQUEST_LIMITER_ERROR_PROMO',
			self::SLIDER_CODES['BOOST_COPILOT_BOX'],
		);
	}

	/**
	 * Checks if a batch of AI requests can be sent without exceeding quotas.
	 *
	 * @param int $requestCount Number of AI requests planned.
	 *
	 * @return string|null Localized error message if quota would be exceeded, or null if allowed.
	 */
	public function getTextFromCheckLimit(int $requestCount): ?string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return $this->getTextFromCheckCloudLimit($requestCount);
		}

		return $this->getTextFromCheckBoxLimit($requestCount);
	}

	/**
	 * Cloud-side reservation of request quota.
	 *
	 * @param int $requestCount Number of requests to reserve.
	 *
	 * @return string|null Localized message for BAAS, promo, daily or monthly limits, or null if reserved.
	 */
	protected function getTextFromCheckCloudLimit(int $requestCount): ?string
	{
		$reservedRequest = (new LimitControlService())->reserveRequest(
			new Usage(Context::getFake()),
			$requestCount
		);

		if ($reservedRequest->isSuccess())
		{
			return null;
		}

		$typeLimit = $reservedRequest->getTypeLimit();
		//right 2 in board
		if ($typeLimit === TypeLimit::BAAS)
		{
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_BAAS',
				self::SLIDER_CODES['BOOST_COPILOT'],
			);
		}

		//right 1 in board
		if (Loader::includeModule('baas') && Baas::getInstance()->isAvailable())
		{
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_PROMO',
				self::SLIDER_CODES['BOOST_COPILOT'],
			);
		}

		$promoLimitCode = $reservedRequest->getPromoLimitCode();
		//left 1 in board
		if ($promoLimitCode === self::PROMO_LIMIT_CODES['DAILY'])
		{
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_DAILY',
				self::SLIDER_CODES['DAILY'],
			);
		}

		//left 2 in board
		if ($promoLimitCode === self::PROMO_LIMIT_CODES['MONTHLY'])
		{
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_MONTHLY',
				self::SLIDER_CODES['MONTHLY'],
			);
		}

		return null;
	}

	/**
	 * On-premise (box) reservation of request quota.
	 *
	 * @param int $requestCount Number of requests to reserve.
	 *
	 * @return string|null Localized message for cloud registration, rate, BAAS, monthly or promo limits, or null if reserved.
	 */
	protected function getTextFromCheckBoxLimit(int $requestCount): ?string
	{
		$cloudConfiguration = new Cloud\Configuration();
		$registrationDto = $cloudConfiguration->getCloudRegistrationData();
		if (!$registrationDto)
		{
			//top in board
			return self::getLimitMessage('LANDING_REQUEST_LIMITER_ERROR_CLOUD_REGISTRATION',);
		}

		try
		{
			$reservedBoxRequest = (new LimitControlBoxService())->isAllowedQuery($requestCount);
		}
		catch (ArgumentException | ObjectNotFoundException | NotFoundExceptionInterface $e)
		{
			throw new GenerationException(GenerationErrors::notSendRequest);
		}

		if (!$reservedBoxRequest)
		{
			throw new GenerationException(GenerationErrors::notSendRequest);
		}

		if ($reservedBoxRequest->isSuccess())
		{
			return null;
		}

		$limitError = $reservedBoxRequest->getErrorByLimit();

		if ($limitError === ErrorLimit::RATE_LIMIT)
		{
			//top in board
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_RATE',
				null,
				self::HELPDESK_CODES['RATE'],
			);
		}

		if ($limitError === ErrorLimit::BAAS_LIMIT)
		{
			if (Loader::includeModule('baas') && Baas::getInstance()->isAvailable())
			{
				//right 1 in board
				return self::getLimitMessage(
					'LANDING_REQUEST_LIMITER_ERROR_PROMO',
					self::SLIDER_CODES['BOOST_COPILOT_BOX'],
				);
			}

			//right 3 in board
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_PROMO',
				self::SLIDER_CODES['BOX'],
			);
		}

		$typeLimit = $reservedBoxRequest->getTypeLimit();
		if ($typeLimit === TypeLimit::BAAS)
		{
			//right 2 in board
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_BAAS',
				self::SLIDER_CODES['BOOST_COPILOT_BOX'],
			);
		}

		if ($limitError === ErrorLimit::PROMO_LIMIT)
		{
			//left 1 in board
			return self::getLimitMessage(
				'LANDING_REQUEST_LIMITER_ERROR_MONTHLY',
				self::SLIDER_CODES['REQUEST_BOX'],
			);
		}

		return null;
	}

	/**
	 * Returns the final text phrase with substituted links
	 *
	 * @param string $phraseCode Localization phrase code
	 * @param string|null $featurePromoterCode Feature promoter code (FEATURE_PROMOTER)
	 * @param string|null $helpdeskCode Helpdesk promoter code
	 *
	 * @return string
	 */
	private static function getLimitMessage(
		string $phraseCode,
		?string $featurePromoterCode = null,
		?string $helpdeskCode = null,
	): string
	{
		if ($featurePromoterCode !== null)
		{
			return Loc::getMessage($phraseCode, [
				'#LINK#' => "[url=/?FEATURE_PROMOTER={$featurePromoterCode}]",
				'#/LINK#' => '[/url]'
			]);
		}

		if ($helpdeskCode !== null)
		{
			$helpUrl = Util::getArticleUrlByCode($helpdeskCode);

			return Loc::getMessage($phraseCode, [
				'#HELP#' => "[url=$helpUrl]",
				'#/HELP#' => '[/url]'
			]);
		}

		return Loc::getMessage($phraseCode);
	}
}