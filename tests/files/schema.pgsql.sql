
CREATE TABLE test (
    id SERIAL,
    bar INTEGER NOT NULL,
    baz VARCHAR(16) NOT NULL DEFAULT 'fizz',
    foo VARCHAR(255) DEFAULT 'buzz'
);

CREATE FUNCTION test_before_insert() RETURNS "trigger" AS $$
BEGIN
    NEW.bar := NEW.bar + 1;
    RETURN NEW;
END;
$$ LANGUAGE 'plpgsql';
CREATE TRIGGER test_before_insert BEFORE INSERT ON test FOR EACH ROW EXECUTE PROCEDURE test_before_insert();

IF NOT EXISTS (SELECT 1 FROM test WHERE id = 1) THEN
    INSERT INTO test VALUES (1, 2, NULL, 'foo');
END IF;

CREATE VIEW viewtest AS SELECT * FROM test;

