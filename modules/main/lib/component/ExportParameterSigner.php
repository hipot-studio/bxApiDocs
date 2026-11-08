<?php

declare(strict_types=1);

namespace Bitrix\Main\Component;

use Bitrix\Main\Security\Sign\Signer;

/**
 * Answers the only question the export controller asks about a component name: may this name be
 * executed? A signed pass answers it for good, the transitional table answers it for the modules
 * that have not started issuing passes yet.
 *
 * The salt binds a pass both to the controller that issued it and to the component it names, so the
 * same pass is worthless for another controller or another component. Inheritance from
 * ParameterSigner serves a single purpose: the restricted unserialize() whitelist must stay one per
 * kernel, and a copy of it would silently drift apart from the original.
 */
class ExportParameterSigner extends ParameterSigner
{
	public const COMPONENT_NAME_KEY = '__componentName';

	/**
	 * Components a module still exports without a signed pass, by the module owning the export
	 * controller. A module lists only its own components, and a controller only ever asks about its
	 * own module, so a name of a foreign module is never admitted by this table.
	 *
	 * Components whose right to the data is checked by the administrative page rather than by the
	 * component itself are absent on purpose: a record here would open their export to any
	 * authorised user.
	 *
	 * @todo Transitional, a module leaves the table once it has shipped the update that issues passes
	 * for its components. The last row taken away takes the rest with it: this table,
	 * isTransitionallyAllowed(), getTransitionalComponents(), the branch calling the first of them in
	 * Bitrix\Main\Controller\Export::resolveComponentName() and the tests of all of it. What stays
	 * is the signed pass: after the removal a component name is admitted by a pass alone.
	 */
	private const TRANSITIONAL_COMPONENTS = [
		'crm' => [
			'bitrix:crm.company.list',
			'bitrix:crm.contact.list',
			'bitrix:crm.deal.list',
			'bitrix:crm.lead.list',
			'bitrix:crm.invoice.list',
			'bitrix:crm.quote.list',
			'bitrix:crm.product.list',
			'bitrix:crm.order.list',
			'bitrix:crm.item.list',
		],
		'lists' => [
			'bitrix:lists.export.excel',
		],
		'tasks' => [
			'bitrix:tasks.task.list',
			'bitrix:tasks.task.gantt',
		],
		'timeman' => [
			'bitrix:timeman.worktime.export.excel',
		],
		'voximplant' => [
			'bitrix:voximplant.statistic.detail',
		],
		'imopenlines' => [
			'bitrix:imopenlines.statistics.detail',
		],
	];

	private const SALT_PREFIX = 'stexport.';

	private const COMPONENT_PATH_MARKER = '/components/';

	public static function sign(string $componentName, string $controllerIdentity, array $parameters): string
	{
		$parameters[self::COMPONENT_NAME_KEY] = $componentName;

		$signer = new Signer();

		return $signer->sign(
			base64_encode(serialize($parameters)),
			self::buildSalt($componentName, $controllerIdentity)
		);
	}

	/**
	 * Anything that is not an array of parameters signed for this very component is a failure, and
	 * every failure looks the same from the outside.
	 */
	public static function unsign(string $componentName, string $controllerIdentity, string $signedParameters): ?array
	{
		try
		{
			$signer = new Signer();
			$value = $signer->unsign($signedParameters, self::buildSalt($componentName, $controllerIdentity));
			$parameters = static::unserialize(base64_decode($value));
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($parameters))
		{
			return null;
		}

		$signedComponentName = $parameters[self::COMPONENT_NAME_KEY] ?? null;
		unset($parameters[self::COMPONENT_NAME_KEY]);

		if ($signedComponentName !== $componentName)
		{
			return null;
		}

		return $parameters;
	}

	/**
	 * For issuing points that know the component but not the controller class: the class is declared
	 * in ajax.php of the component and does not exist while the page is being rendered.
	 */
	public static function signForComponent(string $componentName, array $parameters): string
	{
		return self::sign($componentName, self::resolveComponentIdentity($componentName), $parameters);
	}

	/**
	 * A controller living in ajax.php of a component is identified by that component, because two
	 * components may declare classes of the same name.
	 */
	public static function resolveControllerIdentity(string $controllerClass): string
	{
		try
		{
			$fileName = (new \ReflectionClass($controllerClass))->getFileName();
		}
		catch (\ReflectionException)
		{
			$fileName = false;
		}

		if ($fileName === false)
		{
			return mb_strtolower($controllerClass);
		}

		return self::identityByComponentDirectory(dirname($fileName)) ?? mb_strtolower($controllerClass);
	}

	/** @todo Transitional, see TRANSITIONAL_COMPONENTS. */
	public static function isTransitionallyAllowed(string $moduleId, string $componentName): bool
	{
		if ($componentName === '')
		{
			return false;
		}

		return in_array($componentName, self::getTransitionalComponents($moduleId), true);
	}

	/**
	 * @return string[]
	 * @todo Transitional, see TRANSITIONAL_COMPONENTS.
	 */
	public static function getTransitionalComponents(string $moduleId): array
	{
		return self::TRANSITIONAL_COMPONENTS[$moduleId] ?? [];
	}

	/**
	 * Only the identity is normalized: the component name enters the salt exactly as the issuing
	 * point puts it into the request.
	 */
	private static function buildSalt(string $componentName, string $controllerIdentity): string
	{
		return self::SALT_PREFIX . md5(mb_strtolower($controllerIdentity) . '|' . $componentName);
	}

	private static function resolveComponentIdentity(string $componentName): string
	{
		$directory = self::resolveComponentDirectory($componentName);
		if ($directory === null)
		{
			return mb_strtolower($componentName);
		}

		return self::identityByComponentDirectory($directory) ?? mb_strtolower($componentName);
	}

	/**
	 * A name without a vendor prefix resolves literally only for a component lying directly in
	 * components/<name>, so the vendor directory is looked up afterwards: /local first, then the
	 * distribution. A name that resolves to nothing keeps its own form and the pass simply will not
	 * match.
	 */
	private static function resolveComponentDirectory(string $componentName): ?string
	{
		if (!\CComponentEngine::checkComponentName($componentName))
		{
			return null;
		}

		$path = getLocalPath('components' . \CComponentEngine::makeComponentPath($componentName));
		if ($path !== false)
		{
			return $path;
		}

		if (str_contains($componentName, ':'))
		{
			return null;
		}

		$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '\\/');
		foreach (['/local' . self::COMPONENT_PATH_MARKER, '/bitrix' . self::COMPONENT_PATH_MARKER] as $base)
		{
			$directories = glob($root . $base . '*/' . $componentName, GLOB_ONLYDIR);
			if (!empty($directories))
			{
				return $directories[0];
			}
		}

		return null;
	}

	private static function identityByComponentDirectory(string $directory): ?string
	{
		$directory = str_replace('\\', '/', $directory);

		$position = mb_strrpos($directory, self::COMPONENT_PATH_MARKER);
		if ($position === false)
		{
			return null;
		}

		$parts = explode('/', mb_substr($directory, $position + mb_strlen(self::COMPONENT_PATH_MARKER)));
		$vendor = $parts[0] ?? '';
		$name = $parts[1] ?? '';

		if ($vendor === '')
		{
			return null;
		}

		return mb_strtolower($name === '' ? $vendor : $vendor . ':' . $name);
	}
}
