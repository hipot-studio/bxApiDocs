<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\Http\PublicAddressHttpClient;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @property-write string Title
 * @property-write string Method
 * @property-write string Url
 * @property-write array QueryParams
 * @property-write array Headers
 * @property-write string BodyType
 * @property-write string Body
 * @property-write array BodyParams
 * @property-write int Timeout
 * @property-write string ErrorBehaviour
 * @property-write string ResponseBody
 * @property-write int ResponseCode
 * @property-write string ResponseHeaders
 * @property-write int IsTransportError
 * @property-write string ErrorMessage
 */
class CBPOutgoingWebHookActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const BODY_TYPE_JSON = 'json';
	private const BODY_TYPE_FORM = 'form';

	private const ERROR_BEHAVIOUR_CONTINUE = 'continue';
	private const ERROR_BEHAVIOUR_STOP = 'stop';

	private const TIMEOUT_DEFAULT = 10;
	private const TIMEOUT_MIN = 1;
	private const TIMEOUT_MAX = 30;

	private const RESPONSE_BODY_LENGTH_MAX = 1048576;
	private const RESPONSE_HEADERS_LENGTH_MAX = 65536;

	private const PAIR_PROPERTIES = ['QueryParams', 'Headers', 'BodyParams'];

	private const TRANSPORT_OWNED_HEADERS = [
		'host',
		'content-length',
		'transfer-encoding',
		'connection',
		'keep-alive',
		'te',
		'trailer',
		'upgrade',
		'proxy-authorization',
		'proxy-connection',
	];

	private const METHODS_WITH_BODY = [
		HttpClient::HTTP_POST,
		HttpClient::HTTP_PUT,
		HttpClient::HTTP_PATCH,
	];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'Method' => HttpClient::HTTP_GET,
			'Url' => '',
			'QueryParams' => [],
			'Headers' => [],
			'BodyType' => self::BODY_TYPE_JSON,
			'Body' => '',
			'BodyParams' => [],
			'Timeout' => self::TIMEOUT_DEFAULT,
			'ErrorBehaviour' => self::ERROR_BEHAVIOUR_CONTINUE,

			'ResponseBody' => '',
			'ResponseCode' => 0,
			'ResponseHeaders' => '',
			'IsTransportError' => 0,
			'ErrorMessage' => '',
		];

		$this->setPropertiesTypes([
			'Title' => ['Type' => FieldType::STRING],
			'Method' => ['Type' => FieldType::STRING],
			'Url' => ['Type' => FieldType::STRING],
			'BodyType' => ['Type' => FieldType::STRING],
			'Body' => ['Type' => FieldType::TEXT],
			'Timeout' => ['Type' => FieldType::INT],
			'ErrorBehaviour' => ['Type' => FieldType::STRING],
			'ResponseBody' => ['Type' => FieldType::STRING],
			'ResponseCode' => ['Type' => FieldType::INT],
			'ResponseHeaders' => ['Type' => FieldType::STRING],
			'IsTransportError' => ['Type' => FieldType::INT],
			'ErrorMessage' => ['Type' => FieldType::STRING],
		]);
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->setProperty('ResponseBody', '');
		$this->setProperty('ResponseCode', 0);
		$this->setProperty('ResponseHeaders', '');
		$this->setProperty('IsTransportError', 0);
		$this->setProperty('ErrorMessage', '');
	}

	protected function internalExecute(): ErrorCollection
	{
		$url = $this->resolveRequestUrl();
		if ($url === null)
		{
			return $this->fail('BPOWH_ERROR_URL');
		}

		$httpClient = $this->createHttpClient();
		$failureMessageId = $this->sendRequest($httpClient, $url);

		$this->setProperty('ResponseCode', (int)$httpClient->getStatus());
		$this->setProperty('ResponseBody', \CBPHelper::stringify($httpClient->getResult()));
		$this->setProperty('ResponseHeaders', $this->encodeResponseHeaders($httpClient));

		if ($failureMessageId !== null)
		{
			if ($failureMessageId === 'BPOWH_ERROR_TRANSPORT')
			{
				$this->setProperty('IsTransportError', 1);
			}

			return $this->fail($failureMessageId);
		}

		$responseCode = (int)$this->ResponseCode;
		if ($responseCode >= 400)
		{
			return $this->fail('BPOWH_ERROR_HTTP_CODE', ['#CODE#' => (string)$responseCode]);
		}

		if ($responseCode >= 300)
		{
			return $this->fail('BPOWH_ERROR_REDIRECT', ['#CODE#' => (string)$responseCode]);
		}

		$this->logDebug();

		return new ErrorCollection();
	}

	protected function createHttpClient(): PublicAddressHttpClient
	{
		return new PublicAddressHttpClient();
	}

	private function resolveRequestUrl(): ?string
	{
		$url = $this->prepareUrl();

		return $url === null ? null : $this->appendQuery($url, $this->QueryParams);
	}

	/** @return string|null id of the failure phrase, null when the service answered */
	private function sendRequest(PublicAddressHttpClient $httpClient, string $url): ?string
	{
		$this->configureHttpClient($httpClient);

		try
		{
			foreach ($this->collectPairs($this->Headers) as [$name, $value])
			{
				// HttpHeaders::validateValue() lets a trailing line feed through: its pattern has no D modifier
				if (strpbrk($value, "\r\n") !== false)
				{
					return 'BPOWH_ERROR_HEADER';
				}

				if (in_array(strtolower($name), self::TRANSPORT_OWNED_HEADERS, true))
				{
					return 'BPOWH_ERROR_HEADER';
				}

				$httpClient->getRequestHeaders()->add($name, $value);
			}
		}
		catch (\InvalidArgumentException)
		{
			return 'BPOWH_ERROR_HEADER';
		}

		$method = $this->resolveMethod();

		$body = null;
		if (in_array($method, self::METHODS_WITH_BODY, true))
		{
			$body = $this->buildBody();
			$httpClient->setHeader('Content-Type', $this->resolveContentType(), false);
		}

		if ($httpClient->query($method, $url, $body))
		{
			return null;
		}

		return array_key_exists('PRIVATE_IP', $httpClient->getError())
			? 'BPOWH_ERROR_PRIVATE_ADDRESS'
			: 'BPOWH_ERROR_TRANSPORT'
		;
	}

	private function configureHttpClient(PublicAddressHttpClient $httpClient): void
	{
		$timeout = $this->resolveTimeout();

		$httpClient->setPrivateIp(false);
		$httpClient->setRedirect(false);
		$httpClient->setBodyLengthMax(self::RESPONSE_BODY_LENGTH_MAX);
		$httpClient->setHeadersLengthMax(self::RESPONSE_HEADERS_LENGTH_MAX);
		$httpClient->setTimeout($timeout);
		$httpClient->setStreamTimeout($timeout);
		$httpClient->setDeadline($timeout);
	}

	private function fail(string $messageId, array $replacements = []): ErrorCollection
	{
		$message = Loc::getMessage($messageId, $replacements) ?? '';
		$this->setProperty('ErrorMessage', $message);
		$this->trackError($message);
		$this->logDebug();

		if ($this->resolveErrorBehaviour() === self::ERROR_BEHAVIOUR_STOP)
		{
			throw new CBPInvalidOperationException($message);
		}

		return new ErrorCollection();
	}

	private function prepareUrl(): ?string
	{
		$url = $this->Url;
		if (is_array($url))
		{
			$url = \CBPHelper::flatten($url)[0] ?? null;
		}

		$url = trim(\CBPHelper::stringify($url));
		if (\CBPHelper::isEmptyValue($url))
		{
			return null;
		}

		$urlData = parse_url($url);
		if (!is_array($urlData))
		{
			return null;
		}

		$scheme = mb_strtolower((string)($urlData['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return null;
		}

		$host = (string)($urlData['host'] ?? '');
		if ($host === '' || mb_strpos($host, '.') <= 0)
		{
			return null;
		}

		$target = $scheme . '://';
		if (isset($urlData['user']) || isset($urlData['pass']))
		{
			$target .= (string)($urlData['user'] ?? '');
			if (isset($urlData['pass']))
			{
				$target .= ':' . $urlData['pass'];
			}
			$target .= '@';
		}

		$target .= $host;
		if (isset($urlData['port']))
		{
			$target .= ':' . (int)$urlData['port'];
		}

		if (isset($urlData['path']))
		{
			$target .= self::escapeRequestLine((string)$urlData['path']);
		}

		if (isset($urlData['query']))
		{
			$target .= '?' . self::escapeRequestLine((string)$urlData['query']);
		}

		if (isset($urlData['fragment']))
		{
			$target .= '#' . self::escapeRequestLine((string)$urlData['fragment']);
		}

		return $target;
	}

	/** A request line carries neither a space nor a control character, everything else is kept as written. */
	private static function escapeRequestLine(string $part): string
	{
		return preg_replace_callback('/[\x00-\x20\x7F]/', static fn(array $match) => rawurlencode($match[0]), $part);
	}

	private function appendQuery(string $url, $queryParams): string
	{
		$query = $this->encodePairs($queryParams, rawurlencode(...));

		if (!$query)
		{
			return $url;
		}

		[$target, $fragment] = array_pad(explode('#', $url, 2), 2, null);
		$target .= (str_contains($target, '?') ? '&' : '?') . implode('&', $query);

		return $fragment === null ? $target : $target . '#' . $fragment;
	}

	private function buildBody(): string
	{
		if ($this->resolveBodyType() === self::BODY_TYPE_FORM)
		{
			return implode('&', $this->encodePairs($this->BodyParams, urlencode(...)));
		}

		return \CBPHelper::stringify($this->Body);
	}

	/** @return list<string> */
	private function encodePairs($pairs, callable $encode): array
	{
		$encoded = [];
		foreach ($this->collectPairs($pairs) as [$name, $value])
		{
			$encoded[] = $encode($name) . '=' . $encode($value);
		}

		return $encoded;
	}

	/** @return list<array{string, string}> */
	private function collectPairs($pairs): array
	{
		$collected = [];

		foreach ((array)$pairs as $pair)
		{
			if (!is_array($pair))
			{
				continue;
			}

			$name = \CBPHelper::stringify($pair['NAME'] ?? null);
			if (\CBPHelper::isEmptyValue($name))
			{
				continue;
			}

			$collected[] = [$name, \CBPHelper::stringify($pair['VALUE'] ?? null)];
		}

		return $collected;
	}

	private function collectResponseHeaders(HttpClient $httpClient): array
	{
		$headers = [];
		foreach ($httpClient->getHeaders() as $name => $values)
		{
			// RFC 7230: Set-Cookie is the one field that must not be joined, its Expires carries a comma of its own
			$headers[$name] = strtolower($name) === 'set-cookie'
				? $values
				: implode(', ', $values)
			;
		}

		return $headers;
	}

	private function encodeResponseHeaders(HttpClient $httpClient): string
	{
		try
		{
			return Json::encode($this->collectResponseHeaders($httpClient));
		}
		catch (\Throwable)
		{
			return '{}';
		}
	}

	private function resolveContentType(): string
	{
		return $this->resolveBodyType() === self::BODY_TYPE_FORM
			? 'application/x-www-form-urlencoded'
			: 'application/json'
		;
	}

	private function resolveBodyType(): string
	{
		$bodyType = mb_strtolower(\CBPHelper::stringify($this->BodyType));

		return $bodyType === self::BODY_TYPE_FORM ? self::BODY_TYPE_FORM : self::BODY_TYPE_JSON;
	}

	private function resolveMethod(): string
	{
		$method = mb_strtoupper(\CBPHelper::stringify($this->Method));

		return isset(static::getMethodOptions()[$method]) ? $method : HttpClient::HTTP_GET;
	}

	private function resolveErrorBehaviour(): string
	{
		return \CBPHelper::stringify($this->ErrorBehaviour) === self::ERROR_BEHAVIOUR_STOP
			? self::ERROR_BEHAVIOUR_STOP
			: self::ERROR_BEHAVIOUR_CONTINUE
		;
	}

	private function resolveTimeout(): int
	{
		$timeout = (int)$this->Timeout;
		if ($timeout <= 0)
		{
			$timeout = self::TIMEOUT_DEFAULT;
		}

		return min(self::TIMEOUT_MAX, max(self::TIMEOUT_MIN, $timeout));
	}

	private static function hidePasswordOfUrl(string $url): string
	{
		$uri = new Uri($url);

		return $uri->getPass() === '' ? $url : $uri->setPass('')->getLocator();
	}

	private function logDebug(): void
	{
		if (!$this->workflow?->isDebug())
		{
			return;
		}

		$debugInfo = $this->getDebugInfo(
			[
				'Method' => $this->resolveMethod(),
				'Url' => static::hidePasswordOfUrl(\CBPHelper::stringify($this->Url)),
				'BodyType' => $this->resolveBodyType(),
				'Body' => $this->buildBody(),
				'Timeout' => $this->resolveTimeout(),
				'ErrorBehaviour' => $this->resolveErrorBehaviour(),
			],
			[
				'Method' => [
					'Name' => Loc::getMessage('BPOWH_FIELD_METHOD') ?? '',
					'Type' => FieldType::STRING,
				],
				'Url' => [
					'Name' => Loc::getMessage('BPOWH_FIELD_URL') ?? '',
					'Type' => FieldType::STRING,
				],
				'BodyType' => [
					'Name' => Loc::getMessage('BPOWH_FIELD_BODY_TYPE') ?? '',
					'Type' => FieldType::SELECT,
					'Options' => static::getBodyTypeOptions(),
				],
				'Body' => [
					'Name' => Loc::getMessage('BPOWH_FIELD_BODY') ?? '',
					'Type' => FieldType::TEXT,
				],
				'Timeout' => [
					'Name' => Loc::getMessage('BPOWH_FIELD_TIMEOUT') ?? '',
					'Type' => FieldType::INT,
				],
				'ErrorBehaviour' => [
					'Name' => Loc::getMessage('BPOWH_FIELD_ERROR_BEHAVIOUR') ?? '',
					'Type' => FieldType::SELECT,
					'Options' => static::getErrorBehaviourOptions(),
				],
			]
		);

		$debugInfo += $this->getDebugInfo(
			[
				'ResponseCode' => (int)$this->ResponseCode,
				'ResponseBody' => \CBPHelper::stringify($this->ResponseBody),
				'ResponseHeaders' => \CBPHelper::stringify($this->ResponseHeaders),
				'IsTransportError' => (int)$this->IsTransportError,
				'ErrorMessage' => \CBPHelper::stringify($this->ErrorMessage),
			],
			[
				'ResponseCode' => [
					'Name' => Loc::getMessage('BPOWH_RESULT_CODE') ?? '',
					'Type' => FieldType::INT,
				],
				'ResponseBody' => [
					'Name' => Loc::getMessage('BPOWH_RESULT_BODY') ?? '',
					'Type' => FieldType::STRING,
				],
				'ResponseHeaders' => [
					'Name' => Loc::getMessage('BPOWH_RESULT_HEADERS') ?? '',
					'Type' => FieldType::STRING,
				],
				'IsTransportError' => [
					'Name' => Loc::getMessage('BPOWH_RESULT_IS_TRANSPORT_ERROR') ?? '',
					'Type' => FieldType::INT,
				],
				'ErrorMessage' => [
					'Name' => Loc::getMessage('BPOWH_RESULT_ERROR') ?? '',
					'Type' => FieldType::STRING,
				],
			]
		);

		$this->writeDebugInfo($debugInfo);
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$properties = is_array($context['Properties'] ?? null) ? $context['Properties'] : [];

		$url = [
			'Name' => Loc::getMessage('BPOWH_FIELD_URL') ?? '',
			'FieldName' => 'url',
			'Type' => FieldType::CUSTOM,
			'ValueType' => FieldType::STRING,
			'Required' => true,
			'AllowSelection' => true,
		];

		$body = [
			'Name' => Loc::getMessage('BPOWH_FIELD_BODY') ?? '',
			'FieldName' => 'body',
			'Type' => FieldType::CUSTOM,
			'ValueType' => FieldType::TEXT,
			'Required' => false,
			'AllowSelection' => true,
		];

		$bodyParams = [
			'Name' => Loc::getMessage('BPOWH_BODY_TYPE_FORM') ?? '',
			'FieldName' => 'body_params',
			'Type' => FieldType::CUSTOM,
			'Required' => false,
			'AllowSelection' => true,
		];

		$errorBehaviour = [
			'Name' => Loc::getMessage('BPOWH_FIELD_ERROR_BEHAVIOUR') ?? '',
			'FieldName' => 'error_behaviour',
			'Type' => FieldType::CUSTOM,
			'ValueType' => FieldType::SELECT,
			'Required' => false,
			'Default' => self::ERROR_BEHAVIOUR_CONTINUE,
			'Options' => static::getErrorBehaviourOptions(),
		];

		return [
			'Method' => [
				'Name' => Loc::getMessage('BPOWH_FIELD_METHOD') ?? '',
				'FieldName' => 'method',
				'Type' => FieldType::CUSTOM,
				'ShowFieldLabel' => false,
				'ValueType' => FieldType::SELECT,
				'CustomType' => 'webhookRequestLine',
				'Required' => true,
				'Default' => HttpClient::HTTP_GET,
				'Options' => [
					'fieldName' => 'method',
					'documentType' => $documentType,
					'defaultValue' => HttpClient::HTTP_GET,
					'selectOptions' => static::getMethodOptions(),
					'fieldErrorCaption' => Loc::getMessage('BPOWH_FIELD_RENDER_ERROR') ?? '',
					'pairedField' => static::describeRenderableField($url, $properties['Url'] ?? null),
				],
			],
			'Url' => $url,
			'QueryParams' => [
				'Name' => Loc::getMessage('BPOWH_QUERY_PARAMS_NAME') ?? '',
				'FieldName' => 'query_params',
				'Type' => FieldType::CUSTOM,
				'ShowFieldLabel' => false,
				'CustomType' => 'webhookParams',
				'Required' => false,
				'AllowSelection' => true,
				'Options' => [
					'fieldName' => 'query_params',
					'documentType' => $documentType,
					'addRowCaption' => Loc::getMessage('BPOWH_QUERY_PARAMS_ADD') ?? '',
					'fieldErrorCaption' => Loc::getMessage('BPOWH_FIELD_RENDER_ERROR') ?? '',
					'nameColumnCaption' => Loc::getMessage('BPOWH_QUERY_PARAMS_COLUMN_NAME') ?? '',
					'valueColumnCaption' => Loc::getMessage('BPOWH_PAIR_COLUMN_VALUE') ?? '',
				],
			],
			'Headers' => [
				'Name' => Loc::getMessage('BPOWH_HEADERS_NAME') ?? '',
				'FieldName' => 'headers',
				'Type' => FieldType::CUSTOM,
				'ShowFieldLabel' => false,
				'CustomType' => 'webhookParams',
				'Required' => false,
				'AllowSelection' => true,
				'Options' => [
					'fieldName' => 'headers',
					'documentType' => $documentType,
					'addRowCaption' => Loc::getMessage('BPOWH_HEADERS_ADD') ?? '',
					'fieldErrorCaption' => Loc::getMessage('BPOWH_FIELD_RENDER_ERROR') ?? '',
					'nameColumnCaption' => Loc::getMessage('BPOWH_HEADERS_COLUMN_NAME') ?? '',
					'valueColumnCaption' => Loc::getMessage('BPOWH_PAIR_COLUMN_VALUE') ?? '',
				],
			],
			'BodyType' => [
				'Name' => Loc::getMessage('BPOWH_FIELD_BODY_TYPE') ?? '',
				'FieldName' => 'body_type',
				'Type' => FieldType::CUSTOM,
				'ShowFieldLabel' => false,
				'ValueType' => FieldType::SELECT,
				'CustomType' => 'webhookBody',
				'Required' => false,
				'Default' => self::BODY_TYPE_JSON,
				'Options' => [
					'fieldName' => 'body_type',
					'documentType' => $documentType,
					'defaultValue' => self::BODY_TYPE_JSON,
					'selectOptions' => static::getBodyTypeOptions(),
					'fieldErrorCaption' => Loc::getMessage('BPOWH_FIELD_RENDER_ERROR') ?? '',
					'pairedField' => static::describeRenderableField($body, $properties['Body'] ?? null),
					'pairsOptionValue' => self::BODY_TYPE_FORM,
					'pairsValue' => $properties['BodyParams'] ?? [],
					'pairsOptions' => [
						'fieldName' => 'body_params',
						'documentType' => $documentType,
						'addRowCaption' => Loc::getMessage('BPOWH_BODY_PARAMS_ADD') ?? '',
						'fieldErrorCaption' => Loc::getMessage('BPOWH_FIELD_RENDER_ERROR') ?? '',
						'nameColumnCaption' => Loc::getMessage('BPOWH_BODY_PARAMS_COLUMN_NAME') ?? '',
						'valueColumnCaption' => Loc::getMessage('BPOWH_PAIR_COLUMN_VALUE') ?? '',
					],
				],
			],
			'Body' => $body,
			'BodyParams' => $bodyParams,
			'Timeout' => [
				'Name' => Loc::getMessage('BPOWH_FIELD_TIMEOUT') ?? '',
				'FieldName' => 'timeout',
				'Type' => FieldType::CUSTOM,
				'ShowFieldLabel' => false,
				'ValueType' => FieldType::INT,
				'CustomType' => 'webhookExtra',
				'Required' => false,
				'Default' => self::TIMEOUT_DEFAULT,
				'Options' => [
					'fieldName' => 'timeout',
					'documentType' => $documentType,
					'sectionCaption' => Loc::getMessage('BPOWH_EXTRA_SECTION') ?? '',
					'defaultValue' => self::TIMEOUT_DEFAULT,
					'minValue' => self::TIMEOUT_MIN,
					'maxValue' => self::TIMEOUT_MAX,
					'pairedField' => static::describeRenderableField(
						$errorBehaviour,
						$properties['ErrorBehaviour'] ?? null,
					),
				],
			],
			'ErrorBehaviour' => $errorBehaviour,
			'TestRequest' => [
				'Name' => Loc::getMessage('BPOWH_TEST_BUTTON') ?? '',
				'FieldName' => 'test_request',
				'Type' => FieldType::CUSTOM,
				'ShowFieldLabel' => false,
				'CustomType' => 'webhookTest',
				'Required' => false,
				'Options' => [
					'documentType' => $documentType,
					'buttonCaption' => Loc::getMessage('BPOWH_TEST_BUTTON') ?? '',
					'durationCaption' => Loc::getMessage('BPOWH_TEST_DURATION') ?? '',
					'sizeCaption' => Loc::getMessage('BPOWH_TEST_SIZE') ?? '',
					'bodyTruncatedCaption' => Loc::getMessage('BPOWH_TEST_BODY_TRUNCATED') ?? '',
					'expressionWarning' => Loc::getMessage('BPOWH_TEST_ERROR_EXPRESSION') ?? '',
					'methodsWithBody' => self::METHODS_WITH_BODY,
					'formBodyType' => self::BODY_TYPE_FORM,
				],
			],
		];
	}

	private static function describeRenderableField(array $property, mixed $value): array
	{
		return ['Type' => $property['ValueType'], 'Value' => $value] + $property;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap($dialog ? $dialog->getDocumentType() : []);
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$result = parent::extractPropertiesValues($dialog, static::getValueExtractionMap($fieldsMap));
		if (!$result->isSuccess())
		{
			return $result;
		}

		$values = $result->getData();
		$currentValues = $dialog->getCurrentValues();

		foreach ($fieldsMap as $propertyKey => $fieldProperties)
		{
			$submittedValue = $currentValues[$fieldProperties['FieldName']] ?? null;

			if (in_array($propertyKey, self::PAIR_PROPERTIES, true))
			{
				$values[$propertyKey] = static::extractPairs($submittedValue);
			}
			elseif ($submittedValue === null && isset($fieldProperties['ValueType']))
			{
				$values[$propertyKey] = $fieldProperties['Default'] ?? null;
			}
		}

		return $result->setData($values);
	}

	private static function getValueExtractionMap(array $fieldsMap): array
	{
		$extractionMap = [];

		foreach ($fieldsMap as $propertyKey => $fieldProperties)
		{
			if (!isset($fieldProperties['ValueType']))
			{
				continue;
			}

			$fieldProperties['Type'] = $fieldProperties['ValueType'];
			if (isset($fieldProperties['Options']['selectOptions']))
			{
				$fieldProperties['Options'] = $fieldProperties['Options']['selectOptions'];
			}
			unset($fieldProperties['CustomType']);

			$extractionMap[$propertyKey] = $fieldProperties;
		}

		return $extractionMap;
	}

	private static function extractPairs(mixed $submittedValue): array
	{
		$submittedValue = is_array($submittedValue) ? $submittedValue : [];
		$names = is_array($submittedValue['name'] ?? null) ? $submittedValue['name'] : [];
		$values = is_array($submittedValue['value'] ?? null) ? $submittedValue['value'] : [];

		$pairs = [];
		foreach ($names as $index => $name)
		{
			$name = \CBPHelper::stringify($name);
			if (\CBPHelper::isEmptyValue($name))
			{
				continue;
			}

			$pairs[] = [
				'NAME' => $name,
				'VALUE' => \CBPHelper::stringify($values[$index] ?? ''),
			];
		}

		return $pairs;
	}

	public static function getAjaxResponse($request)
	{
		$request = is_array($request) ? $request : [];

		$properties = [
			'Method' => $request['method'] ?? '',
			'Url' => $request['url'] ?? '',
			'QueryParams' => static::extractPairs($request['query_params'] ?? null),
			'Headers' => static::extractPairs($request['headers'] ?? null),
			'BodyType' => $request['body_type'] ?? '',
			'Body' => $request['body'] ?? '',
			'BodyParams' => static::extractPairs($request['body_params'] ?? null),
			'Timeout' => $request['timeout'] ?? self::TIMEOUT_DEFAULT,
		];

		try
		{
			$activity = new static('OutgoingWebHookTestRequest');
			$activity->initializeFromArray($properties);

			return $activity->runTestRequest();
		}
		catch (\Throwable)
		{
			return static::describeTestRequestFailure('BPOWH_TEST_ERROR_FAILED');
		}
	}

	private function runTestRequest(): array
	{
		if (static::containsExpression($this->collectPropertiesInUse()))
		{
			return static::describeTestRequestFailure('BPOWH_TEST_ERROR_EXPRESSION');
		}

		$url = $this->resolveRequestUrl();
		if ($url === null)
		{
			return static::describeTestRequestFailure('BPOWH_ERROR_URL');
		}

		$httpClient = $this->createHttpClient();

		$startedAt = microtime(true);
		$failureMessageId = $this->sendRequest($httpClient, $url);
		$durationMs = (int)round((microtime(true) - $startedAt) * 1000);

		$responseBody = \CBPHelper::stringify($httpClient->getResult());

		return [
			'success' => $failureMessageId === null,
			'responseCode' => (int)$httpClient->getStatus(),
			'responseBody' => $responseBody,
			'responseBodySize' => strlen($responseBody),
			'responseHeaders' => $this->collectResponseHeaders($httpClient),
			'durationMs' => $durationMs,
			'errorMessage' => $failureMessageId === null ? '' : (Loc::getMessage($failureMessageId) ?? ''),
		];
	}

	/** Only the properties the request is built from: a body of another type or of a GET is not sent. */
	private function collectPropertiesInUse(): array
	{
		$properties = [
			'Url' => $this->getRawProperty('Url'),
			'QueryParams' => $this->getRawProperty('QueryParams'),
			'Headers' => $this->getRawProperty('Headers'),
			'Timeout' => $this->getRawProperty('Timeout'),
		];

		if (in_array($this->resolveMethod(), self::METHODS_WITH_BODY, true))
		{
			$properties['Body'] = $this->resolveBodyType() === self::BODY_TYPE_FORM
				? $this->getRawProperty('BodyParams')
				: $this->getRawProperty('Body')
			;
		}

		return $properties;
	}

	private static function describeTestRequestFailure(string $messageId): array
	{
		return [
			'success' => false,
			'responseCode' => 0,
			'responseBody' => '',
			'responseBodySize' => 0,
			'responseHeaders' => [],
			'durationMs' => 0,
			'errorMessage' => Loc::getMessage($messageId) ?? '',
		];
	}

	private static function containsExpression(mixed $value): bool
	{
		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (static::containsExpression($item))
				{
					return true;
				}
			}

			return false;
		}

		$text = \CBPHelper::stringify($value);

		return static::isExpression($text)
			|| preg_match(static::ValueInlinePattern, $text)
			|| preg_match(static::CalcInlinePattern, $text)
		;
	}

	private static function getBodyTypeOptions(): array
	{
		return [
			self::BODY_TYPE_JSON => Loc::getMessage('BPOWH_BODY_TYPE_JSON') ?? '',
			self::BODY_TYPE_FORM => Loc::getMessage('BPOWH_BODY_TYPE_FORM') ?? '',
		];
	}

	private static function getErrorBehaviourOptions(): array
	{
		return [
			self::ERROR_BEHAVIOUR_CONTINUE => Loc::getMessage('BPOWH_ERROR_BEHAVIOUR_CONTINUE') ?? '',
			self::ERROR_BEHAVIOUR_STOP => Loc::getMessage('BPOWH_ERROR_BEHAVIOUR_STOP') ?? '',
		];
	}

	private static function getMethodOptions(): array
	{
		return [
			HttpClient::HTTP_GET => HttpClient::HTTP_GET,
			HttpClient::HTTP_POST => HttpClient::HTTP_POST,
			HttpClient::HTTP_PUT => HttpClient::HTTP_PUT,
			HttpClient::HTTP_PATCH => HttpClient::HTTP_PATCH,
			HttpClient::HTTP_DELETE => HttpClient::HTTP_DELETE,
		];
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
