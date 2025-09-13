<?php

namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\IO\Path;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Security\W\Rules\Results\ModifyResult;

abstract class Rule
{
	protected $path;

	protected $context;

	protected $keys;

	protected $process;

	protected $encoding;

	public static function make(array $rule): ?static
	{
		$rule = static::prepareRuleParameters($rule);

		return match ($rule['action'])
		{
			'intval' => new IntvalRule(
				$rule['path'],
				$rule['context'],
				$rule['keys'],
				$rule['process'],
				$rule['encoding']
			),
			'preg_replace' => new PregReplaceRule(
				$rule['path'],
				$rule['context'],
				$rule['keys'],
				$rule['process'],
				$rule['encoding'],
				$rule['pattern']
			),
			'preg_match' => new PregMatchRule(
				$rule['path'],
				$rule['context'],
				$rule['keys'],
				$rule['process'],
				$rule['encoding'],
				$rule['pattern'],
				$rule['post_action']
			),
			'check_csrf' => new CsrfRule(
				$rule['path'],
				$rule['context'],
				$rule['keys'],
				$rule['process'],
				$rule['encoding'],
				$rule['pattern'],
			),
			default => null,
		};
	}

	protected static function prepareRuleParameters(array $parameters): array
	{
		if (is_string($parameters['action']))
		{
			$parameters['action'] = strtolower($parameters['action']);
		}
		elseif (is_array($parameters['action']))
		{
			$complexAction = $parameters['action'];

			$parameters['action'] = $complexAction[0];
			$parameters['post_action'] = $complexAction[1];
		}

		$parameters['encoding'] = !empty($parameters['encoding'])
			? $parameters['encoding']
			: [];

		if (is_string($parameters['encoding']))
		{
			$parameters['encoding'] = [$parameters['encoding']];
		}

		return $parameters;
	}

	/**
	 * @param $path
	 * @param $context
	 * @param $keys
	 * @param $process
	 */
	public function __construct($path, $context, $keys, $process, $encoding)
	{
		$this->path = $path;
		$this->context = $this->castContext($context);
		$this->keys = $this->castKeys($keys);
		$this->process = $process;
		$this->encoding = $encoding;
	}

	public function evaluateValue($value)
	{
		if (!empty($this->encoding))
		{
			foreach ($this->encoding as $encodingType)
			{
				$value = match ($encodingType)
				{
					'gz' => gzdecode($value),
					'base64' => base64_decode($value),
					'url' => urldecode($value),
					'hex' => hex2bin($value)
				};
			}
		}

		$result = $this->evaluate($value);

		if (!empty($this->encoding) && $result instanceof ModifyResult)
		{
			$cleanValue = $result->getCleanValue();

			foreach (array_reverse($this->encoding) as $encodingType)
			{
				$cleanValue = match ($encodingType)
				{
					'gz' => gzencode($cleanValue),
					'base64' => base64_encode($cleanValue),
					'url' => urlencode($cleanValue),
					'hex' => bin2hex($cleanValue)
				};
			}

			$result = new ModifyResult($cleanValue);
		}

		return $result;
	}

	abstract public function evaluate($value);

	protected function castContext($context)
	{
		if (!is_array($context))
		{
			$context = [$context];
		}

		foreach ($context as $k => $v)
		{
			$context[$k] = strtolower($v);
		}

		return $context;
	}

	protected function castKeys($keys)
	{
		if (!is_array($keys))
		{
			$keys = [$keys];
		}

		return $keys;
	}

	public function matchKey(array $contextKey): bool
	{
		$contextKey = join('.', $contextKey);

		foreach ($this->keys as $key)
		{
			//if ($key === $contextKey)
			// bxu_files.validKey.phpinfo();die(); =>  bxu_files.*
			if (fnmatch($key, $contextKey))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * 3 uri types: public scripts, urlrewrite and routing.
	 * There is base check for both types.
	 * There is one more check for real path for public scripts.
	 *
	 * @param $uri
	 * @return bool
	 */
	public function matchPath($uri)
	{
		if ($this->path === '*')
		{
			return  true;
		}

		// normalize uri
		$parsedUri = new Uri($uri);
		$_uri = $parsedUri->getPath();

		$_uri = rawurldecode($_uri);
//
//		if (Application::hasInstance())
//		{
//			$_uri = Encoding::convertEncodingToCurrent($_uri);
//		}

		if (str_ends_with($_uri, '/'))
		{
			$_uri .= 'index.php';
		}

		$_uri = Path::normalize($_uri);

		// valid uris
		$cleanUris[] = $_uri;

		if (str_ends_with($_uri, '/index.php'))
		{
			$cleanUris[] = substr($_uri, 0, -9);
		}
		elseif (str_ends_with($_SERVER['SCRIPT_NAME'], '/index.php'))
		{
			$cleanUris[] = substr($_SERVER['SCRIPT_NAME'], 0, -9);
		}

		if ($_uri !== $_SERVER['SCRIPT_NAME'])
		{
			$cleanUris[] = $_SERVER['SCRIPT_NAME'];
		}

		// analyze
		if (str_starts_with($this->path, '~'))
		{
			$pattern = $this->path;
		}
		else
		{
			$pattern = '~^' . str_replace('~', '\~', preg_quote($this->path)) . '$~';
		}

		foreach ($cleanUris as $cleanUri)
		{
			if ($this->path === $cleanUri || preg_match($pattern, $cleanUri))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return mixed
	 */
	public function getPath()
	{
		return $this->path;
	}

	/**
	 * @return mixed
	 */
	public function getContext()
	{
		return $this->context;
	}

	/**
	 * @return mixed
	 */
	public function getKeys()
	{
		return $this->keys;
	}

	/**
	 * @return mixed
	 */
	public function getProcess()
	{
		return $this->process;
	}
}