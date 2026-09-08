<?php

declare(strict_types=1);

namespace App\Attribution;

/**
 * first-touch 와 last-touch 를 어떻게 갱신할지 판정한다.
 *
 * 관측된 대상 조직은 유입 지점을 pidIntro(진입) / pid_join(가입시점) / pid_last(최종)
 * 세 벌로 보존한다. 이 프로젝트는 first / last 두 벌로 줄이되 판정 규칙은 명시한다.
 *
 * 규칙 네 가지
 *
 *  1. 소스가 있는 접점이고 first 가 없으면  → first 를 만들고 last 도 갱신한다
 *  2. 소스가 있는 접점이고 first 가 있으면  → first 는 보존하고 last 만 갱신한다
 *  3. 직접 유입이고 last 가 이미 있으면     → 아무것도 바꾸지 않는다
 *  4. 직접 유입이고 last 도 없으면          → last 만 기록한다 (first 는 만들지 않는다)
 *
 * 3번이 이 클래스의 핵심이다.
 *
 * 광고를 타고 들어온 방문자가 나중에 북마크로 재방문하면 직접 유입이 된다.
 * 이때 last 를 direct 로 덮으면 그 방문자의 전환은 어느 매체에도 귀속되지 않는다.
 * 광고 성과가 조용히 사라지는 것이라, 매체 대시보드와 자체 집계가 어긋나는
 * 흔한 원인이 된다. 그래서 "소스 없음"은 소스를 덮어쓰지 않는다.
 *
 * 4번은 그럼에도 방문 자체는 남겨야 하기 때문이다. 다만 first 로는 삼지 않는다.
 * 첫 방문이 직접 유입이라는 이유로 first 를 direct 로 박아 두면
 * 그 방문자는 이후 어떤 광고를 타고 와도 first-touch 기여를 받지 못한다.
 */
final class TouchpointResolver
{
    public function resolve(
        ?Touchpoint $existingFirst,
        ?Touchpoint $existingLast,
        Touchpoint $incoming,
    ): Resolution {
        if ($incoming->isDirect()) {
            return $existingLast === null
                ? Resolution::lastOnly($incoming)   // 규칙 4
                : Resolution::nothing();            // 규칙 3
        }

        return $existingFirst === null
            ? Resolution::firstAndLast($incoming)   // 규칙 1
            : Resolution::lastOnly($incoming);      // 규칙 2
    }
}
