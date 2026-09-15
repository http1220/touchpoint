<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 앱 로그 파일의 이름과 수명.
 *
 * ── 왜 파일이 다시 필요해졌나 ──
 *
 * 앱 로그는 stderr 로만 나갔다(MY_Log). 도커 json-file 드라이버가 그 출력을
 * 들고 있는데, **그 로그는 컨테이너에 붙어 있어서 컨테이너를 재생성하면 같이
 * 사라진다.** 2026-09-15 환불 시험 끝에 app 을 재생성하자 "이미 쓴 코인이 있는
 * 결제가 환불됐다" 는 error 줄이 없어졌다 — 사람이 봐야 한다고 남긴 줄이
 * 배포 한 번에 지워지는 구조였다.
 *
 * 그래서 stderr 는 그대로 두고(실시간으로 볼 때), 같은 줄을 **컨테이너 밖에 사는
 * 명명 볼륨**의 일자별 파일에도 붙인다.
 *
 * ── 이름에 SAPI 를 넣는 이유 ──
 *
 * 웹 요청은 php-fpm 워커(www-data)가, 워커·cron 은 CLI(root)가 쓴다. 같은
 * 파일을 root 가 먼저 만들면 www-data 가 붙여 쓰지 못하고 **조용히 실패한다.**
 * 파일을 SAPI 로 나누면 각자 자기가 만든 파일에만 쓴다.
 *
 * ── 보존 ──
 *
 * 로그에는 방문·결제 식별자와 요청 경로가 실린다. 방문기록과 같은 3개월로
 * 파기한다 → cli/Purge
 */
final class LogFile
{
    private const PATTERN = '/\Aapp-(fpm-fcgi|cli|[a-z0-9-]{1,16})-(\d{4}-\d{2}-\d{2})\.jsonl\z/';

    public static function name(string $sapi, DateTimeImmutable $now): string
    {
        $sapi = preg_replace('/[^a-z0-9-]/', '-', strtolower($sapi)) ?? 'unknown';

        return 'app-'.substr($sapi, 0, 16).'-'.$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d').'.jsonl';
    }

    /**
     * 이 파일이 보존기간을 넘었는가. **이름의 날짜로 판정한다.**
     *
     * 수정 시각(mtime)을 쓰지 않는다 — 어제 파일에 오늘 한 줄이 늦게 붙으면
     * mtime 이 갱신돼 파기가 하루씩 밀린다. 우리가 모르는 이름은 지우지 않는다.
     */
    public static function isExpired(string $filename, DateTimeImmutable $cutoff): bool
    {
        if (preg_match(self::PATTERN, $filename, $m) !== 1) {
            return false;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $m[2], new DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $m[2]) {
            return false;
        }

        // 그날 전체가 기준 시각 이전이어야 지운다.
        return $day->modify('+1 day') <= $cutoff->setTimezone(new DateTimeZone('UTC'));
    }
}
