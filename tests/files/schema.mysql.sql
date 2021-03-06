
CREATE TABLE test (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bar SMALLINT NOT NULL,
    baz VARCHAR(16) NOT NULL DEFAULT 'fizz',
    foo VARCHAR(255) DEFAULT 'buzz'
) ENGINE='InnoDB' DEFAULT CHARSET='UTF8';

ALTER TABLE test ADD INDEX (bar);

CREATE TRIGGER test_before_insert BEFORE INSERT ON test
FOR EACH ROW
BEGIN
    SET NEW.bar = NEW.bar + 1;
END;

IF NOT EXISTS (SELECT 1 FROM test WHERE id = 1) THEN
    INSERT INTO test VALUES (1, 2, NULL, 'foo');
END IF;

CREATE VIEW viewtest AS SELECT * FROM test;

