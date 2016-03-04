<?php

namespace Dbmover;

use PDO;
use PDOException;
use Dariuszp\CliProgressBar;

/**
 * The main Dbmover class. This represents a migration for a single unique DSN
 * from the `dbmover.json` config file.
 */
abstract class Dbmover
{
    const CATALOG_COLUMN = 'CATALOG';
    const REGEX_PROCEDURES = '@^CREATE (PROCEDURE|FUNCTION).*?^END;$@ms';
    const REGEX_TRIGGERS = '@^CREATE TRIGGER.*?^END;$@ms';
    const DROP_ROUTINE_SUFFIX = '()';

    public $pdo;
    protected $schemas = [];
    protected $database;
    protected $ignores = [];

    /**
     * Constructor.
     *
     * @param string $dsn The DSN string to connect with. Passed verbatim to
     *  PHP's `PDO` constructor.
     * @param array $settings Hash of settings read from `dbmover.json`. The
     *  `"user"` and `"pass"` keys are mostly relevant.
     */
    public function __construct($dsn, array $settings = [])
    {
        preg_match('@dbname=(\w+)@', $dsn, $database);
        $this->database = $database[1];
        $user = isset($settings['user']) ? $settings['user'] : null;
        $pass = isset($settings['pass']) ? $settings['pass'] : null;
        $options = isset($settings['options']) ? $settings['options'] : [];
        $this->pdo = new PDO($dsn, $user, $pass, $options);
        if (isset($options['ignore']) && is_array($options['ignore'])) {
            $this->ignores = $options['ignore'];
        }
    }

    /**
     * Expose the name of the current database.
     *
     * @return string
     */
    public function getName()
    {
        return $this->database;
    }

    /**
     * Add schema data read from an SQL file.
     *
     * @param string $schema The complete schema.
     */
    public function addSchema($schema)
    {
        $this->schemas[] = $schema;
    }

    /**
     * Process all added schemas.
     */
    public function processSchemas()
    {
        echo "\033[0;34m".str_pad(
            "Gathering operations for {$this->database}...",
            100,
            ' ',
            STR_PAD_RIGHT
        );
        $sql = implode("\n", $this->schemas);
        $operations = [];
        $alter = $this->hoist( '@^ALTER TABLE .*?;$@ms', $sql);

        // Gather all conditionals and optionally wrap them in a "lambda".
        $ifs = $this->hoist('@^IF.*?^END IF;$@ms', $sql);
        foreach ($ifs as &$if) {
            $if = $this->wrapInProcedure($if);
        }

        $operations = array_merge($operations, $alter, $ifs);
        $tables = $this->hoist('@^CREATE TABLE .*?;$@ms', $sql);

        // Hoist all other recreatable objects.
        $hoists = array_merge(
            $this->hoist(
                '@^CREATE VIEW.*?;$@ms',
                $sql
            ),
            $this->hoist(
                static::REGEX_PROCEDURES,
                $sql
            ),
            $this->hoist(
                static::REGEX_TRIGGERS,
                $sql
            )
        );

        $tablenames = [];
        foreach ($tables as $table) {
            preg_match('@^CREATE TABLE (\w+) ?\(@', $table, $name);
            $name = $name[1];
            $tablenames[] = $name;

            // If the table doesn't exist yet, create it verbatim.
            if (!$this->tableExists($name)) {
                $operations[] = $table;
            } else {
                $existing = $this->getTableDefinition($name);
                $new = $this->parseTableDefinition($table);
                foreach ($existing as $col => $definition) {
                    if (!isset($new[$col])) {
                        $operations[] = sprintf(
                            "ALTER TABLE %s DROP COLUMN %s",
                            $name,
                            $col
                        );
                    }
                }
                foreach ($new as $col => $definition) {
                    if (!isset($existing[$col])) {
                        $operations[] = $this->addColumn($name, $definition);
                    } else {
                        $operations = array_merge(
                            $operations,
                            $this->alterColumn($name, $definition)
                        );
                    }
                }
            }
        }

        foreach ($this->getTables('VIEW') as $view) {
            if (!$this->shouldIgnore($view)) {
                $operations[] = "DROP VIEW $view";
            }
        }
        foreach ($this->getRoutines() as $routine) {
            if (!$this->shouldIgnore($routine)) {
                $operations[] = sprintf(
                    "DROP %s %s%s",
                    $routine['routinetype'],
                    $routine['routinename'],
                    static::DROP_ROUTINE_SUFFIX
                );
            }
        }
        foreach ($this->getTriggers() as $trigger) {
            if (!$this->shouldIgnore($trigger)) {
                $operations[] = "DROP TRIGGER $trigger";
            }
        }
        foreach ($hoists as $hoist) {
            preg_match('@^CREATE (\w+) (\w+)@', $hoist, $data);
            if ($data[1] == 'FUNCTION' && $this instanceof Pgsql) {
                $data[2] .= '()';
            }
            $operations[] = "DROP {$data[1]} IF EXISTS {$data[2]}";
            $operations[] = $hoist;
        }

        if (strlen(trim($sql))) {
            $operations[] = $sql;
        }

        // Rerun ifs and alters
        $operations = array_merge($operations, $alter, $ifs);

        // Cleanup: remove stuff that is not in the schema (any more)
        foreach ($this->getTables('BASE TABLE') as $table) {
            if (!in_array($table, $tablenames)
                && !$this->shouldIgnore($table)
            ) {
                $operations[] = "DROP TABLE $table CASCADE";
            }
        }

        // Perform the actual operations and display progress meter
        echo "\033[100D\033[1;34mPerforming operations for {$this->database}...\n";

        echo "\033[0m";

        $bar = new CliProgressBar(count($operations));
        $bar->display();

        $bar->setColorToRed();

        while ($operation = array_shift($operations)) {
            $this->pdo->exec($operation);
            $bar->progress();

            if ($bar->getCurrentstep() >= ($bar->getSteps() / 2)) {
                $bar->setColorToYellow();
            }
        }
        
        $bar->setColorToGreen();
        $bar->display();
        
        $bar->end();
        echo "\033[0m";
    }

    /**
     * Default implementation for adding a column.
     *
     * @param string $table The table to modify.
     * @param array $definition Key/value hash of column definition.
     * @return string SQL that adds this column to the table.
     */
    protected function addColumn($table, array $definition)
    {
        return sprintf(
            "ALTER TABLE %s ADD COLUMN %s %s%s%s",
            $table,
            $definition['name'],
            $definition['coltype'],
            $definition['nullable'] == 'NO' ?
                ' NOT NULL' :
                '',
            $definition['def'] != '' ?
                sprintf(
                    " DEFAULT %s",
                    is_null($definition['def']) ?
                        'NULL' :
                        $this->pdo->quote($definition['def'])
                ) :
                ''
        );
    }

    /**
     * Default implementation for altering a column.
     *
     * @param string $table The table to modify.
     * @param array $definition Key/value hash of column definition.
     * @return array An array of SQL statements that bring this column into the
     *  desired state.
     */
    protected function alterColumn($table, array $definition)
    {
        $operations = [];
        $base = sprintf(
            "ALTER TABLE %s ALTER COLUMN %s",
            $table,
            $definition['name']
        );
        $operations[] = "$base TYPE {$definition['coltype']}";
        if ($definition['nullable'] == 'NO') {
            $operations[] = "$base SET NOT NULL";
        } else {
            $operations[] = "$base DROP NOT NULL";
        }
        if ($definition['def'] != '') {
            $operations[] = "$base SET DEFAULT "
                .is_null($definition['def']) ?
                    'NULL' :
                    $this->pdo->quote($definition['def']);
        } else {
            $operations[] = "$base DROP DEFAULT";
        }
        return $operations;
    }

    /**
     * Database vendors not allowing direct conditionals (e.g. MySQL) can wrap
     * them in an "anonymous" procedure ECMAScript-style here.
     *
     * @param string $sql The SQL to wrap.
     * @return string The input SQL potentially wrapped and called.
     */
    protected function wrapInProcedure($sql)
    {
        return $sql;
    }

    /**
     * Hoist all matches of $regex from the provided SQL string, and return them
     * as an array for further processing.
     *
     * @param string $regex Regular expression to hoist.
     * @param string $sql Reference to the SQL string to hoist from. Hoisted
     *  statements are removed from this string.
     * @return array An array of hoisted statements (or an empty array if
     *  nothing matched).
     */
    protected function hoist($regex, &$sql)
    {
        $hoisted = [];
        if (preg_match_all($regex, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $stmt) {
                $hoisted[] = $stmt[0];
                $sql = str_replace($stmt[0], '', $sql);
            }
        }
        return $hoisted;
    }

    /**
     * Get an array of all table names in this database.
     *
     * @return array An array of table names.
     */
    protected function getTables($type = 'BASE TABLE')
    {
        static $stmt;
        if (!isset($stmt)) {
            $stmt = $this->pdo->prepare(sprintf(
                "SELECT TABLE_NAME
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_%s = ? AND TABLE_TYPE = ?",
                static::CATALOG_COLUMN
            ));
        }
        $stmt->execute([$this->database, $type]);
        $names = [];
        while (false !== ($table = $stmt->fetchColumn())) {
            var_dump($table);
            $names[] = $table;
        }
        return $names;
    }

    /**
     * Check if a table exists on the given database.
     *
     * @param string $name The table name to check.
     * @return bool True or false.
     */
    protected function tableExists($name, $type = 'BASE TABLE')
    {
        $tables = $this->getTables($type);
        return in_array($name, $tables);
    }

    /**
     * Get the relevant parts of a table schema from INFORMATION_SCHEMA in a
     * vendor-independent way.
     *
     * @param string $name The name of the table.
     * @return array A hash of columns, where the key is also the column name.
     */
    protected function getTableDefinition($name)
    {
        static $stmt;
        if (!isset($stmt)) {
            $stmt = $this->pdo->prepare(sprintf(
                "SELECT
                    COLUMN_NAME name,
                    COLUMN_DEFAULT def,
                    IS_NULLABLE nullable,
                    COLUMN_TYPE coltype,
                    COLUMN_KEY colkey,
                    EXTRA extra
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_%s = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION ASC",
                static::CATALOG_COLUMN
            ));
        }
        $stmt->execute([$this->database, $name]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (is_null($column['def'])) {
                $column['def'] = 'NULL';
            } else {
                $column['def'] = $this->pdo->quote($column['def']);
            }
            $cols[$column['name']] = $column;
        }
        return $cols;
    }

    /**
     * Parse the table definition as specified in the schema into a format
     * similar to what `getTableDefinition` returns.
     *
     * @param string $schema The schema for this table (CREATE TABLE .. (...);)
     * @return array A hash of columns, where the key is also the column name.
     */
    protected function parseTableDefinition($schema)
    {
        preg_match("@CREATE TABLE \w+ \((.*)\)@ms", $schema, $extr);
        $lines = explode(',', $extr[1]);
        $extra = $cols = [];
        foreach ($lines as &$line) {
            $line = trim($line);
            if (preg_match('@^(CONSTRAINT|UNIQUE|INDEX|PRIMARY)@', $line)) {
                $extra[] = $line;
                continue;
            }
            $column = [
                'name' => '',
                'def' => null,
                'nullable' => 'YES',
                'coltype' => '',
                'colkey' => '',
                'extra' => '',
            ];
            // Extract the name
            preg_match('@^\w+@', $line, $name);
            $column['name'] = $name[0];
            $line = str_replace($name[0], '', $line);
            if (!$this->isNullable($line)) {
                $column['nullable'] = 'NO';
            }
            if ($this->isAutoIncrement($line)) {
                $column['extra'] = 'auto_increment';
            }
            if ($this->isPrimaryKey($line)) {
                $column['key'] = 'PRI';
            }
            if ($default = $this->getDefaultValue($line)) {
                $column['def'] = $default;
            }
            $line = preg_replace('@REFERENCES.*?$@', '', $line);
            $column['coltype'] = strtolower(trim($line));
            $cols[$name[0]] = $column;
        }
        return $cols;
    }

    /**
     * Extract and return the default value for a column, if specified.
     *
     * @param string $column The referenced column definition.
     * @return string|null The default value of the column, if specified. If no
     *  default was specified, null.
     */
    protected function getDefaultValue(&$column)
    {
        if (preg_match('@DEFAULT (.*?)($| )@', $column, $default)) {
            $column = str_replace($default[0], '', $column);
            return $default[1];
        }
        return null;
    }

    /**
     * Checks whether a column is nullable.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    protected function isNullable(&$column)
    {
        if (strpos($column, 'NOT NULL')) {
            $column = str_replace('NOT NULL', '', $column);
            return false;
        }
        return true;
    }

    /**
     * Checks whether a column is an auto_increment column.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    protected abstract function isAutoIncrement(&$column);
    
    /**
     * Checks whether a column is a primary key.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    protected abstract function isPrimaryKey(&$column);

    /**
     * Return a list of all routines in the current catalog.
     *
     * @return array Array of routines, including meta-information.
     */
    protected function getRoutines()
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT
                ROUTINE_TYPE routinetype,
                ROUTINE_NAME routinename
            FROM INFORMATION_SCHEMA.ROUTINES WHERE
                ROUTINE_%s = '%s'",
            static::CATALOG_COLUMN,
            $this->database
        ));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a list of all triggers in the current catalog.
     *
     * @return array Array of trigger names.
     */
    protected function getTriggers()
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT TRIGGER_NAME triggername
                FROM INFORMATION_SCHEMA.TRIGGERS WHERE
                TRIGGER_%s = '%s'",
            static::CATALOG_COLUMN,
            $this->database
        ));
        $stmt->execute();
        $triggers = [];
        foreach ($stmt->fetchAll() as $trigger) {
            $triggers[] = $trigger['triggername'];
        }
        return $triggers;
    }


    /**
     * Determine whether an object should be ignored as per config.
     *
     * @param string $object The name of the object to test.
     */
    protected function shouldIgnore($object)
    {
        foreach ($this->ignores as $regex) {
            if (preg_match($regex, $object)) {
                return true;
            }
        }
        return false;
    }
}

