-- 복제 계정. 복제본이 이 계정으로 바이너리 로그를 읽어간다.
CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH caching_sha2_password BY 'repl_pw_change_me';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';

-- 앱 계정이 두 서버 모두에서 통해야 한다(복제본은 읽기 전용).
GRANT SELECT, INSERT, UPDATE, DELETE ON *.* TO 'ab'@'%';

FLUSH PRIVILEGES;
