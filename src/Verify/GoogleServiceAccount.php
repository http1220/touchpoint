<?php

declare(strict_types=1);

namespace App\Verify;

use App\Channel\HttpClient;
use RuntimeException;

/**
 * 서비스 계정으로 Google API 액세스 토큰을 받는다. (OAuth2 JWT Bearer, RFC 7523)
 *
 * SDK(`google/apiclient`)를 넣지 않았다. 필요한 것이 **흐름 하나**이고,
 * 그 흐름이 이 파일 하나에 다 들어가기 때문이다 → ADR-017 ②
 * 의존성을 하나 늘리는 것보다 60줄을 읽을 수 있게 두는 편이 낫다고 봤다.
 *
 * 흐름은 세 단계다.
 *
 *   ① 서비스 계정 개인키로 JWT 를 만들어 서명한다 (RS256)
 *   ② 그 JWT 를 토큰 엔드포인트에 보낸다 (grant_type=jwt-bearer)
 *   ③ 액세스 토큰을 받는다. 보통 1시간짜리다
 *
 * **왜 비밀번호가 아니라 JWT 인가.** 서비스 계정에는 비밀번호가 없다.
 * 대신 개인키가 있고, 그 키로 "나는 이 계정이고 이 범위를 원한다" 는
 * 주장에 서명한다. 개인키 자체는 네트워크로 나가지 않는다 —
 * 나가는 것은 그 키로 만든 서명뿐이다.
 */
final class GoogleServiceAccount
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** 시계 오차를 감안해 만료 조금 전에 새로 받는다. */
    private const RENEW_MARGIN_SEC = 60;

    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly string $scope,
        private readonly int $timeoutMs = 10000,
    ) {
        if ($this->clientEmail === '' || $this->privateKey === '') {
            throw new RuntimeException('서비스 계정 정보가 비어 있습니다.');
        }
    }

    /**
     * 서비스 계정 JSON 키 파일에서 만든다.
     *
     * 파일 경로만 받고 **내용을 로그에 남기지 않는다.** 이 파일 하나로
     * 속성 데이터를 읽을 수 있으므로 `.env` 의 비밀값과 같은 급이다.
     */
    public static function fromKeyFile(HttpClient $http, string $path, string $scope): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException('서비스 계정 키 파일을 읽을 수 없습니다: '.$path);
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (!is_array($json) || !isset($json['client_email'], $json['private_key'])) {
            throw new RuntimeException('키 파일에 client_email 또는 private_key 가 없습니다.');
        }

        return new self($http, (string) $json['client_email'], (string) $json['private_key'], $scope);
    }

    public function accessToken(?int $now = null): string
    {
        $now ??= time();

        if ($this->token !== null && $now < $this->expiresAt - self::RENEW_MARGIN_SEC) {
            return $this->token;
        }

        $res = $this->http->postForm(self::TOKEN_URL, [
            'grant_type' => self::GRANT,
            'assertion' => $this->assertion($now),
        ], $this->timeoutMs);

        $body = $res->json();

        if (!$res->isSuccess() || !isset($body['access_token'])) {
            /*
             * 오류 본문을 그대로 올리지 않는다. 구글은 실패 응답에
             * 우리가 보낸 assertion 을 되비추는 경우가 있고, 그게 로그로
             * 흘러가면 서명된 주장이 남는다.
             */
            throw new RuntimeException(sprintf(
                '토큰 발급 실패 (http=%s, error=%s)',
                $res->status ?? '-',
                (string) ($body['error'] ?? $res->error ?? '알 수 없음')
            ));
        }

        $this->token = (string) $body['access_token'];
        $this->expiresAt = $now + (int) ($body['expires_in'] ?? 3600);

        return $this->token;
    }

    // ────────────────────────────────────────────────────────

    /** 서명된 JWT. 테스트에서 모양을 확인할 수 있게 분리해 둔다. */
    public function assertion(int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $claims = [
            'iss' => $this->clientEmail,
            'scope' => $this->scope,
            'aud' => self::TOKEN_URL,
            'iat' => $now,

            // 구글은 최대 1시간까지 받는다. 더 길게 주면 거절한다.
            'exp' => $now + 3600,
        ];

        $signingInput = self::b64(json_encode($header)).'.'.self::b64(json_encode($claims));

        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false) {
            throw new RuntimeException('개인키를 읽을 수 없습니다. PEM 형식인지 확인하세요.');
        }

        $signature = '';

        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('JWT 서명에 실패했습니다.');
        }

        return $signingInput.'.'.self::b64($signature);
    }

    /**
     * base64url. 표준 base64 와 세 글자가 다르다 — `+/=` 를 쓰지 않는다.
     * 그냥 base64 를 쓰면 토큰이 URL·헤더에서 깨진다.
     */
    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
