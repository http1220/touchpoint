<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CI3 자신이 뿜는 PHP 8.2 deprecation 만 걸러낸다.
 *
 * 왜 필요한가
 *
 *   CI3 의 공식 지원은 PHP 7.4 다. 8.2 에서 돌리면 프레임워크 내부
 *   (CI_URI, CI_Router, CI_DB_driver …)가 동적 프로퍼티를 만들면서
 *   요청마다 deprecation 을 여러 건 뿜는다. 우리 코드의 문제가 아니고
 *   우리가 고칠 수도 없다 — vendor/ 안이다.
 *
 *   그런데 이걸 error_reporting 에서 E_DEPRECATED 를 통째로 빼는 식으로 끄면
 *   우리가 새로 쓴 코드의 deprecation 까지 같이 사라진다.
 *   그건 8.2 위에서 CI3 를 돌리며 배우려던 것을 스스로 지우는 짓이다.
 *
 * 그래서 조건을 좁힌다
 *
 *   E_DEPRECATED 이면서 발생 위치가 프레임워크 안일 때만 억제한다.
 *   application/ 이나 src/ 에서 난 것은 그대로 보인다.
 *
 *   억제한 건수는 세어 둔다. 안 보이게 하는 것과 없는 것처럼 구는 것은 다르다.
 *   /diag 화면에서 이 숫자를 확인할 수 있다.
 *
 * → docs/decisions/ADR-001-php-codeigniter.md
 */
class MY_Exceptions extends CI_Exceptions
{
	/** vendor 안의 프레임워크 경로 조각. 이 경로에서 난 것만 대상이다. */
	const FRAMEWORK_PATH = 'vendor/codeigniter/framework/';

	/** @var int 이번 요청에서 억제한 건수 */
	private static $suppressed = 0;

	public function log_exception($severity, $message, $filepath, $line)
	{
		if ($this->isFrameworkDeprecation($severity, $filepath))
		{
			self::$suppressed++;

			return;
		}

		parent::log_exception($severity, $message, $filepath, $line);
	}

	public function show_php_error($severity, $message, $filepath, $line)
	{
		if ($this->isFrameworkDeprecation($severity, $filepath))
		{
			return;
		}

		parent::show_php_error($severity, $message, $filepath, $line);
	}

	/** 이번 요청에서 억제된 프레임워크 deprecation 건수. */
	public static function suppressed()
	{
		return self::$suppressed;
	}

	private function isFrameworkDeprecation($severity, $filepath)
	{
		if ($severity !== E_DEPRECATED && $severity !== E_USER_DEPRECATED)
		{
			return FALSE;
		}

		// 윈도우에서 만든 경로가 섞일 수 있으므로 구분자를 통일해 비교한다.
		$path = str_replace(chr(92), "/", (string) $filepath);   // chr(92) = 역슬래시

		return strpos($path, self::FRAMEWORK_PATH) !== FALSE;
	}
}
