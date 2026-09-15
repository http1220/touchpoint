<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 로그를 파일이 아니라 stderr 로 보낸다.
 *
 * 이유가 셋이다.
 *
 * ① **컨테이너 로그가 곧 애플리케이션 로그여야 한다.**
 *    엣지(OpenResty)는 이미 JSON 한 줄씩 stdout 으로 뱉는다.
 *    앱만 파일에 쓰면 한 요청을 쫓는 데 두 군데를 봐야 한다.
 *
 * ② **바인드 마운트에서 파일 로그는 조용히 사라진다.**
 *    application/logs/ 는 호스트 소유(uid 1000)인데 php-fpm 워커는
 *    www-data 로 돈다. CI_Log 는 쓰기 권한이 없으면 예외를 던지지 않고
 *    _enabled 를 FALSE 로 바꾼다 — 로그가 없어진 걸 아무도 모른다.
 *
 * ③ **상관 ID 를 붙일 자리가 필요하다.**
 *    엣지에서 받은 trace_id 를 앱 로그 줄에도 달아야 엣지 로그 한 줄에서
 *    앱까지 따라갈 수 있다. → docs/decisions/ADR-012-observability-scope.md
 *
 * 로그 로테이션도 이 방식이면 우리 문제가 아니다. 도커 로그 드라이버가 한다.
 *
 * ── 09-15 정정 ──
 *
 * 위 판단에 빠진 것이 있었다. 도커 로그 드라이버가 들고 있는 로그는 **컨테이너와
 * 수명을 같이한다.** 재생성 한 번에 사라진다. 그래서 같은 줄을 명명 볼륨의
 * 일자별 파일에도 붙인다(appendToFile). ② 의 권한 문제는 볼륨 디렉터리를
 * 이미지에서 www-data 소유로 만들고, 파일을 SAPI 별로 나눠 피한다.
 * 파일 로테이션은 날짜별 파일 + 3개월 파기(cli/purge)가 맡는다.
 */
class MY_Log extends CI_Log
{
	public function __construct()
	{
		parent::__construct();

		// 부모 생성자는 로그 디렉터리가 쓰기 불가하면 _enabled 를 끈다.
		// 우리는 파일을 쓰지 않으므로 그 판단이 해당되지 않는다.
		$this->_enabled = TRUE;
	}

	/**
	 * 부모의 임계값 판정은 그대로 쓰고, 출력만 바꾼다.
	 * 판정 로직을 새로 쓰면 config 의 log_threshold 의미가 갈라진다.
	 */
	public function write_log($level, $msg)
	{
		if ($this->_enabled === FALSE)
		{
			return FALSE;
		}

		$level = strtoupper($level);

		if (( ! isset($this->_levels[$level]) OR ($this->_levels[$level] > $this->_threshold))
			&& ! isset($this->_threshold_array[$this->_levels[$level]]))
		{
			return FALSE;
		}

		$line = json_encode(array(
			'ts'       => gmdate('Y-m-d\TH:i:s\Z'),
			'level'    => $level,
			'trace_id' => $this->traceId(),
			'msg'      => trim((string) $msg),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		// 실패해도 요청을 죽이지 않는다. 로그 때문에 응답이 실패하면 본말전도다.
		$ok = @file_put_contents('php://stderr', $line."\n") !== FALSE;

		$this->appendToFile($line);

		return $ok;
	}

	/**
	 * 같은 줄을 컨테이너 밖에 사는 일자별 파일에도.
	 *
	 * stderr 만으로는 컨테이너를 재생성하면 로그가 사라진다(09-15 발견)
	 * → src/Support/LogFile 머리말. 디렉터리는 명명 볼륨(`app_logs`)이다.
	 *
	 * 쓰기에 실패해도 조용히 넘어간다 — 위 ② 의 "조용히 사라진다" 가 다시
	 * 생기지 않게, **실패는 한 번만 stderr 에 알린다.**
	 */
	private function appendToFile($line)
	{
		$dir = getenv('APP_LOG_DIR');
		$dir = ($dir === FALSE) ? '/var/log/touchpoint' : rtrim((string) $dir, '/');

		if ($dir === '' OR ! class_exists('App\Support\LogFile'))
		{
			return;
		}

		$path = $dir.'/'.App\Support\LogFile::name(PHP_SAPI, new DateTimeImmutable('now', new DateTimeZone('UTC')));

		if (@file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX) === FALSE && ! self::$fileWarned)
		{
			self::$fileWarned = TRUE;
			@file_put_contents('php://stderr', json_encode(array(
				'ts'    => gmdate('Y-m-d\TH:i:s\Z'),
				'level' => 'ERROR',
				'msg'   => 'MY_Log: 로그 파일에 쓰지 못했다 — '.$path.' (볼륨·권한 확인). 이후 실패는 알리지 않는다',
			), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
		}
	}

	/** @var bool 프로세스당 한 번만 알린다. php-fpm 워커는 요청을 여럿 처리한다 */
	private static $fileWarned = FALSE;

	/**
	 * 이번 요청의 상관 ID.
	 *
	 * App\Support\TraceId 를 쓰되 존재 여부를 확인한다 — 로그는 composer
	 * 오토로드가 걸리기 전에도 호출될 수 있고(설정 오류·초기 에러 핸들러),
	 * 그때 클래스가 없다고 죽으면 원인을 알려줄 로그마저 사라진다.
	 */
	private function traceId()
	{
		if (class_exists('App\Support\TraceId')) {
			return App\Support\TraceId::current();
		}

		return isset($_SERVER['AB_TRACE_ID']) ? (string) $_SERVER['AB_TRACE_ID'] : '';
	}
}
