-- 읽기 복제본 배정
--
-- 대상 조직에서 관측한 동작을 그대로 구현한다.
--   쿠키 없음  → 라운드로빈으로 배정하고 쿠키를 내려준다
--   쿠키 있음  → 그 복제본에 고정한다 (재발급하지 않는다)
--
-- 관측 근거: 쿠키 없이 8회 요청 시 sdb1~sdb5가 분산 배정됐고,
--            쿠키를 실어 보내면 Set-Cookie가 나오지 않았다.
--
-- 이 방식의 한계는 의도적으로 남겨둔다.
--   기기·브라우저를 바꾸면 다른 복제본에 배정되므로
--   "방금 산 코인이 안 보인다"가 재발한다.
--   docs/failure-scenarios.md E-1 에서 이걸 재현한다.

local _M = {}

-- 읽기 대상. rdb1은 프라이머리를 읽기 전용으로 쓰고, rdb2는 실제 복제본이다.
-- 지연이 서로 달라서 배정 결과에 따라 사용자가 보는 신선도가 갈린다.
local READ_TARGETS = { "rdb1", "rdb2" }

local COOKIE_NAME = "ab_rdb"

local function is_known(name)
    for _, v in ipairs(READ_TARGETS) do
        if v == name then return true end
    end
    return false
end

-- 라운드로빈. 공유 딕셔너리 카운터를 워커 간에 공유한다.
local function next_target()
    local dict = ngx.shared.replica_rr
    local n, err = dict:incr("cursor", 1, 0)
    if not n then
        ngx.log(ngx.WARN, "replica: incr failed (", err, "), 첫 대상으로 폴백")
        return READ_TARGETS[1]
    end
    return READ_TARGETS[(n % #READ_TARGETS) + 1]
end

function _M.assign()
    local cookie = ngx.var["cookie_" .. COOKIE_NAME]
    local target

    if cookie and cookie ~= "" and is_known(cookie) then
        -- 고정. Set-Cookie를 내리지 않는다 — 관측된 동작과 같다.
        target = cookie
    else
        target = next_target()
        -- 세션 성격이라 first-party로 충분하다. 크로스사이트로 나갈 이유가 없다.
        ngx.header["Set-Cookie"] = COOKIE_NAME .. "=" .. target ..
            "; Path=/; Max-Age=86400; SameSite=Lax; Secure; HttpOnly"
    end

    -- 앱이 읽기 커넥션을 고를 수 있도록 넘긴다.
    ngx.var.read_target = target
end

return _M
