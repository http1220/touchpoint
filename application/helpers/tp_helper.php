<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 이 프로젝트 공용 헬퍼.
|
| CI3 관용구를 따라 함수로 둔다. 다만 규칙이 있다 —
| 여기에는 "형식 변환"만 들어온다. 판단이 들어가면 src/ 로 보낸다.
| 헬퍼는 전역 함수라 테스트도 대체도 어렵기 때문이다.
*/

if ( ! function_exists('tp_now_utc'))
{
	/**
	 * DATETIME(3) 문자열. 저장은 언제나 UTC 다.
	 *
	 * date() 를 쓰면 서버 타임존에 걸린다. gmdate 는 밀리초가 없다.
	 * 그래서 DateTime 으로 만든다.
	 */
	function tp_now_utc()
	{
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
	}
}

if ( ! function_exists('tp_uuid7'))
{
	/**
	 * UUIDv7 을 BINARY(16) 으로.
	 *
	 * 앞 48비트가 밀리초 타임스탬프라 시간순으로 정렬된다.
	 * UUIDv4 를 PK 근처에 쓰면 InnoDB 클러스터드 인덱스가 매 삽입마다
	 * 페이지를 쪼갠다 — 그래서 v7 이다. → docs/data-model.md 8장
	 */
	function tp_uuid7()
	{
		$ms  = (int) (microtime(TRUE) * 1000);
		$bin = pack('J', $ms);          // 8바이트 빅엔디언
		$bin = substr($bin, 2);         // 앞 2바이트 버리고 48비트만
		$bin .= random_bytes(10);

		// 버전 7, variant RFC 4122
		$bin[6] = chr((ord($bin[6]) & 0x0F) | 0x70);
		$bin[8] = chr((ord($bin[8]) & 0x3F) | 0x80);

		return $bin;
	}
}

if ( ! function_exists('tp_uuid_text'))
{
	/** BINARY(16) → 사람이 읽는 형태. 로그와 응답에만 쓴다. */
	function tp_uuid_text($bin)
	{
		$hex = bin2hex($bin);

		return sprintf('%s-%s-%s-%s-%s',
			substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
			substr($hex, 16, 4), substr($hex, 20, 12));
	}
}

if ( ! function_exists('tp_hash'))
{
	/**
	 * IP·UA 처럼 원본을 보관하면 안 되는 값의 단방향 해시.
	 *
	 * 소금 없이 해시하면 IPv4 는 43억 개라 전수 대입으로 복원된다.
	 * ENCRYPTION_KEY 를 소금으로 쓴다 — 키가 유출되면 해시도 무의미해지지만,
	 * 그건 이미 세션까지 다 뚫린 상황이다.
	 */
	function tp_hash($raw)
	{
		if ($raw === NULL OR $raw === '')
		{
			return NULL;
		}

		return hash_hmac('sha256', (string) $raw, (string) (getenv('ENCRYPTION_KEY') ?: ''), TRUE);
	}
}

if ( ! function_exists('tp_env_bool'))
{
	/**
	 * .env 의 실험 스위치.
	 *
	 * getenv() 는 "false" 라는 문자열을 돌려주고 PHP 에서 그건 참이다.
	 * 스위치를 껐는데 켜져 있는 상황이 여기서 나온다.
	 */
	function tp_env_bool($key, $default = FALSE)
	{
		$raw = getenv($key);

		if ($raw === FALSE OR $raw === '')
		{
			return $default;
		}

		return in_array(strtolower(trim($raw)), array('1', 'true', 'yes', 'on'), TRUE);
	}
}
