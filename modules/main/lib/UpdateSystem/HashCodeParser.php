<? namespace Bitrix\Main\UpdateSystem;

use Bitrix\Main\Application;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;

class HashCodeParser
{
	private string $_1996961862;

	public function __construct(string $_1996961862)
	{
		$this->_1996961862 = $_1996961862;
	}

	public function parse()
	{
		$_1990700997 = base64_decode($this->_1996961862);
		$_1990700997 = unserialize($_1990700997, ["allowed_classes" => false]);
		if (openssl_verify($_1990700997["info"], $_1990700997["signature"], $this->__643200814(), "sha256WithRSAEncryption") == 1) {
			$_1135403189 = Application::getInstance()->getLicense()->getHashLicenseKey();
			$_988395176  = new Cipher();
			$_1133361788 = $_988395176->decrypt($_1990700997["info"], $_1135403189);
			return unserialize($_1133361788, ["allowed_classes" => false]);
		}
		throw new SecurityException("Error verify openssl [HCPP01]");
	}

	private function __643200814(): string
	{
		return "-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6hcxIqiitUZRMwYiukSU
h9xa5fEDYlccbW3vj8Ava35vKqVN4iB9tqCX7jU86qAa2v37mbTF6pcY6HGPAhRF
bpnwXOY7YGxB1nSKZvE+jARbiLLBgZ1cG6Z0duu5i1XhpIRL1cN0Hh5fezpjXC6O
YxYq0nToHTjyRb1yczwtmiRwYqudXg/xWxppqwF0tUld3QBr3i68B8jqMm+TjdeA
u/fg1J0JGtR4/zK4G7YJNvhmuhrRGkyAQV0TVu5LEugSxjApRmIJQNHQMK0Eh93w
MZoFoPp9SgJ7GaFU8kzS+EQcntYxb1NHUJUIvTdiuRUeFKlyTdxIrH6CL//apMH3
FwIDAQAB
-----END PUBLIC KEY-----";
	}
} ?>