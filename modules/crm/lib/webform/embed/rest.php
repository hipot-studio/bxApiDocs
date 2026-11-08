<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage intranet
 * @copyright 2001-2019 Bitrix
 */

namespace Bitrix\Crm\WebForm\Embed;

use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Rest\RestException;
use Bitrix\Crm\WebForm;
use Bitrix\Crm\WebForm\Telemetry\Recorder;
use Bitrix\Crm\WebForm\Telemetry\RecorderInterface;
use Bitrix\Crm\WebForm\Telemetry\SourceContextSigner;
use Bitrix\Crm\WebForm\Telemetry\SubmissionClassifier;
use Bitrix\Crm\UI\Webpack;
use Bitrix\Crm\SiteButton\Guest;
use Throwable;

/**
 * Class Rest
 * @package Bitrix\Crm\WebForm\Embed
 */
class Rest
{
	/**
	 * Register bindings.
	 *
	 * @param array $bindings Rest bindings.
	 * @return void
	 */
	public static function register(array &$bindings)
	{
		$bindings['crm.site.form.fill'] = [__CLASS__, 'fillForm'];
		$bindings['crm.site.form.user.get'] = [__CLASS__, 'getUser'];
	}

	/**
	 * Fill form.
	 *
	 * @param array $query Query parameters.
	 * @param RecorderInterface|null $telemetryRecorder Telemetry recorder.
	 * @return array
	 * @throws RestException
	 */
	public static function fillForm($query, ?RecorderInterface $telemetryRecorder = null)
	{
		$formId = empty($query['id']) ? null : (int) $query['id'];
		$securityCode = empty($query['sec']) ? null : $query['sec'];
		$values = (isset($query['values']) && $query['values'])
			? $query['values']
			: null;
		$properties = (isset($query['properties']) && $query['properties'])
			? $query['properties']
			: null;
		$trace = empty($query['trace']) ? null : $query['trace'];
		$consents = $query['consents'] ?? '';
		$explicitConsents = $query['explicitConsents'] ?? '';
		$displayedSubmitAgreements = $query['displayedSubmitAgreements'] ?? '';
		$recaptchaResponse = empty($query['recaptcha']) ? null : $query['recaptcha'];
		$yandexSmartCaptchaResponse = empty($query['yandexSmartCaptcha']) ? null : $query['yandexSmartCaptcha'];
		$signString = empty($query['security_sign']) ? null : $query['security_sign'];
		//$entities = empty($query['entities']) ? null : $query['entities'];

		$form = null;
		$telemetryContext = null;
		$isValidationFailure = false;

		try
		{
			if (!WebForm\Manager::isEmbeddingAvailable())
			{
				self::printErrors(["Form embedding feature disabled."]);
			}

			if (!$formId)
			{
				self::printErrors(["Parameter `id` required."]);
			}
			if (!$securityCode)
			{
				self::printErrors(["Parameter `sec` required."]);
			}

			$form = new WebForm\Form($formId, null, $telemetryRecorder);
			if (!$form->checkSecurityCode($securityCode))
			{
				self::printErrors(["Parameter `security_sign` is invalid."]);
			}

			if (!$form->isActive())
			{
				self::printErrors(["Form with id=`$formId` is disabled."]);
			}

			$telemetryContext = self::prepareTelemetryContext($query, $formId);

			$consents = $consents ? Main\Web\Json::decode($consents) : [];
			$consents = is_array($consents) ? $consents : [];
			$explicitConsents = $explicitConsents ? Main\Web\Json::decode($explicitConsents) : [];
			$explicitConsents = is_array($explicitConsents) ? $explicitConsents : [];
			$displayedSubmitAgreements = $displayedSubmitAgreements
				? Main\Web\Json::decode($displayedSubmitAgreements)
				: [];
			$displayedSubmitAgreements = is_array($displayedSubmitAgreements) ? $displayedSubmitAgreements : [];

			if ($form->isUsedCaptcha())
			{
				if (Option::get('crm', 'crm_form_captcha_service') === 'yandex')
				{
					$yandexSecret = WebForm\YandexCaptcha::getSecret() ?: WebForm\YandexCaptcha::getDefaultSecret();
					$yandexKey = WebForm\YandexCaptcha::getKey() ?: WebForm\YandexCaptcha::getDefaultKey();

					if ($yandexSecret && $yandexKey)
					{
						if (!$recaptchaResponse)
						{
							self::printErrors(["Parameter `yandexSmartCaptcha` is invalid."]);
						}

						$yandexCaptcha = new WebForm\YandexCaptcha($yandexSecret);

						if (!$yandexCaptcha->verify($yandexSmartCaptchaResponse))
						{
							self::printErrors([$yandexCaptcha->getError()]);
						}
					}
				}
				else
				{
					$recaptchaSecret = WebForm\ReCaptcha::getSecret(2) ?: WebForm\ReCaptcha::getDefaultSecret(2);
					$recaptchaKey = WebForm\ReCaptcha::getKey(2) ?: WebForm\ReCaptcha::getDefaultKey(2);

					if ($recaptchaSecret && $recaptchaKey)
					{
						if (!$recaptchaResponse)
						{
							self::printErrors(["Parameter `recaptcha` is invalid."]);
						}

						$recaptcha = new WebForm\ReCaptcha($recaptchaSecret);

						if (!$recaptcha->verify($recaptchaResponse))
						{
							self::printErrors([$recaptcha->getError()]);
						}
					}
				}
			}

			if ($telemetryContext !== null)
			{
				self::startTelemetry($telemetryContext, $formId, $telemetryRecorder);
			}

			$fill = $form->fill();

			///////////////////
			$values = $values ? Main\Web\Json::decode($values) : [];

			$properties = $properties ? Main\Web\Json::decode($properties) : [];

			$fill
				->setTrace($trace)
				->setValues($values)
				->setConsents($consents)
				->setExplicitConsents($explicitConsents)
				->setDisplayedSubmitAgreements($displayedSubmitAgreements)
				->setProperties($properties)
			;
			if ($telemetryContext !== null)
			{
				$fill->setTelemetryContext($telemetryContext);
			}

			if ($signString)
			{
				$sign = new Sign();
				if ($sign->unpack($signString))
				{
					$fill->setEntities($sign->getEntities());
				}
			}

			$agreementsResult = $fill->resolveAgreements();
			if (!$agreementsResult->isSuccess())
			{
				$isValidationFailure = true;
				if ($telemetryContext !== null)
				{
					self::markTelemetryError($telemetryContext['attemptId'], $telemetryRecorder, 'validation');
				}
				self::printErrors($agreementsResult->getErrorMessages());
			}

			$result = $fill->save();
			if (!$result->getId())
			{
				$isValidationFailure = true;
				self::printErrors($result->getErrors());
			}

			$gid = $result->getResultEntity()->getTrace()->getGid();
			if (!$gid)
			{
				$gid = Guest::register([
					'ENTITIES' => array_map(
						function (array $item)
						{
							return [
								'ENTITY_TYPE_ID' => \CCrmOwnerType::resolveID($item['ENTITY_TYPE']),
								'ENTITY_ID' => $item['ENTITY_ID'],
							];
						},
						$result->getResultEntity()->getResultEntities()
					)
				]);
			}

			return [
				'resultId' => $result->getId(),
				'pay' => $form->isPayable(),
				'message' => $form->getSuccessText(),
				'gid' => $gid,
				'redirect' => [
					'url' => $result->getUrl(),
					'delay' => $form->getRedirectDelay(),
				],
				'refill' => [
					'active' => $form->getRefill()['ACTIVE'] === 'Y',
					'caption' => $form->getRefill()['CAPTION'],
				]
			];
		}
		catch (Throwable $exception)
		{
			if ($telemetryContext !== null && !$isValidationFailure)
			{
				self::markTelemetryError($telemetryContext['attemptId'], $telemetryRecorder);
			}

			return [
				'resultId' => null,
				'pay' => false,
				'message' => $form ? $form->getFailureText() : $exception->getMessage(),
				'gid' => null,
				'redirect' => [],
				'refill' => []
			];
		}
	}

	private static function startTelemetry(
		array $context,
		int $formId,
		?RecorderInterface $recorder,
	): void
	{
		try
		{
			$recorder ??= new Recorder();
			$recorder->start(
				$context['attemptId'],
				$formId,
				$context['source'],
				Main\Context::getCurrent()->getRequest()->getUserAgent(),
			);
		}
		catch (Throwable)
		{
		}
	}

	private static function prepareTelemetryContext(array $query, int $formId): ?array
	{
		$attemptId = $query['attemptId'] ?? null;
		if (!self::isUuidV4($attemptId))
		{
			return null;
		}

		$source = SubmissionClassifier::SOURCE_UNKNOWN;
		$signString = $query['telemetry_sign'] ?? null;
		if (is_string($signString) && $signString !== '')
		{
			$source = (new SourceContextSigner())->verify($signString, $formId)
				?? SubmissionClassifier::SOURCE_UNKNOWN
			;
		}

		return [
			'attemptId' => $attemptId,
			'source' => $source,
		];
	}

	private static function markTelemetryError(
		string $attemptId,
		?RecorderInterface $recorder,
		string $errorType = 'other',
	): void
	{
		try
		{
			($recorder ?? new Recorder())->markError($attemptId, $errorType);
		}
		catch (Throwable)
		{
		}
	}

	private static function isUuidV4(mixed $attemptId): bool
	{
		return is_string($attemptId) && preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$attemptId,
		) === 1;
	}

	/**
	 * Get form.
	 *
	 * @param array $query Query parameters.
	 * @return array
	 * @throws RestException
	 */
	public static function getForm($query)
	{
		$formId = empty($query['id']) ? null : (int) $query['id'];
		$securityCode = empty($query['sec']) ? null : $query['sec'];
		$loaderOnly = !empty($query['loaderOnly']);

		if (!WebForm\Manager::isEmbeddingAvailable())
		{
			self::printErrors(["Form embedding feature disabled."]);
		}

		if (!$formId)
		{
			self::printErrors(["Parameter `id` required."]);
		}
		if (!$securityCode)
		{
			self::printErrors(["Parameter `sec` required."]);
		}

		if (!empty($query['agreementsOnly']))
		{
			$cachePath = '/crm/webform/rest_get_form/' . $formId . '/';
			$cacheKey = 'WebFormAgreements:' . $formId
				. ':' . hash('sha256', $securityCode)
				. ':' . (Config::isAcceptBySubmitEnabled() ? '1' : '0')
			;
			$cache = Main\Application::getInstance()->getCache();
			if ($cache->initCache(15, $cacheKey, $cachePath))
			{
				return $cache->getVars();
			}

			$form = new WebForm\Form($formId);
			if (!$form->checkSecurityCode($securityCode))
			{
				self::printErrors(["Parameter `security_sign` is invalid."]);
			}

			if (!$form->isActive())
			{
				self::printErrors(["Form with id=`$formId` is disabled."]);
			}

			$cache->startDataCache();
			$taggedCache = Main\Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache($cachePath);
			$taggedCache->registerTag(WebForm\Form::getCacheTag($formId));
			$taggedCache->endTagCache();

			$response = ['agreements' => (new Config($form))->getAgreements()];
			$cache->endDataCache($response);

			return $response;
		}

		$telemetrySource = SubmissionClassifier::SOURCE_SITE;
		$telemetrySign = $query[SourceContextSigner::PARAMETER] ?? null;
		if (is_string($telemetrySign))
		{
			$telemetrySource = (new SourceContextSigner())->verify($telemetrySign, $formId)
				?? SubmissionClassifier::SOURCE_SITE
			;
		}

		$cacheKey = 'WebForm:'
			. $formId
			. ':' . ($securityCode ? substr(md5($securityCode), -6) : '')
			. ':' . ($loaderOnly ? '1' : '0')
			. ':' . $telemetrySource
			. ':' . (Config::isAcceptBySubmitEnabled() ? '1' : '0')
		;
		$cachePath = '/crm/webform/rest_get_form/'.$formId.'/';
		$ttl = 1800; // 30 min
		$cache = \Bitrix\Main\Application::getInstance()->getCache();

		if ($cache->initCache($ttl, $cacheKey, $cachePath))
		{
			$response = $cache->getVars();
		}
		else
		{
			$cache->startDataCache();
			$cacheTag = WebForm\Form::getCacheTag($formId);

			$taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache($cachePath);
			$taggedCache->registerTag($cacheTag);
			$taggedCache->endTagCache();

			$form = new WebForm\Form($formId);

			if (!$form->checkSecurityCode($securityCode))
			{
				self::printErrors(["Parameter `security_sign` is invalid."]);
			}

			if (!$form->isActive())
			{
				self::printErrors(["Form with id=`$formId` is disabled."]);
			}

			$appPack = Webpack\Form\App::instance();
			$scripts = WebForm\Script::getListContext($form->get(), [], null, $telemetrySource);

			$response = [
				'config' => $loaderOnly ? null : (new Config($form))->toArray(),
				'loader' => [
					'form' => [
						'inline' => $scripts['INLINE']['text'],
						'click' => $scripts['CLICK']['text'],
						'auto' => $scripts['AUTO']['text'],
					],
					'app' => [
						'link' => $appPack->getEmbeddedFileUrl(),
						'script' => $appPack->getEmbeddedBody(),
					],
				],
			];

			$cache->endDataCache($response);
		}

		return $response;
	}

	/**
	 * Get user.
	 *
	 * @param array $query Query parameters.
	 * @return array
	 * @throws RestException
	 */
	public static function getUser($query)
	{
		$sign = empty($query['security_sign']) ? null : $query['security_sign'];
		if (!$sign)
		{
			self::printErrors(["Parameter `security_sign` required."]);
		}

		if (!WebForm\Manager::isEmbeddingAvailable())
		{
			self::printErrors(["Form embedding feature disabled."]);
		}

		$hash = new Sign();

		if (!$hash->unpack($sign))
		{
			self::printErrors(["Parameter `security_sign` is not valid."]);
		}

		return User::getData($hash->getEntities()) + ['properties' => $hash->getProperties()];
	}

	/**
	 * Print rest errors.
	 *
	 * @param string[] $errors Errors.
	 * @param string $errorCode Error Code.
	 * @return void
	 * @throws RestException
	 */
	protected static function printErrors(array $errors,  $errorCode = RestException::ERROR_CORE)
	{
		foreach ($errors as $error)
		{
			throw new RestException(
				$error,
				$errorCode,
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}
	}
}
