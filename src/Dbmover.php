<?php

namespace Dbmover;

use PDO;
use PDOException;
use Dariuszp\CliProgressBar;

/**
 * The main Dbmover class. This represents a migration for a single unique DSN
 * from the `dbmover.json` config file.
 */
class Dbmover
{
    private $pdo;
    private $schemas = [];
    private $database;

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
     *
     */
    public function processSchemas()
    {
        echo "\033[1;32m".str_pad(
            "Gathering operations for {$this->database}...",
            100,
            ' ',
            STR_PAD_RIGHT
        );
        $sql = implode("\n", $this->schemas);
        $operations = [];
        $alter = $this->hoist( '@^ALTER TABLE .*?;$@ms', $sql);
        $ifs = $this->hoist('@^IF.*?^END;$@ms', $sql);
        $operations = array_merge($operations, $alter, $ifs);
        $tables = $this->hoist('@^CREATE TABLE .*?;$@ms', $sql);

        // Hoist all other recreatable objects.
        $hoists = $this->hoist(
            '@^CREATE (VIEW|PROCEDURE|FUNCTION|TRIGGER).*?^END;$@ms',
            $sql
        );

        foreach ($tables as $table) {
            preg_match('@^CREATE TABLE (\w+) ?\(@', $table, $name);
            $name = $name[1];

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
                        $operations[] = sprintf(
                            "ALTER TABLE %s ADD COLUMN %s %s%s%s",
                            $name,
                            $col,
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

                }
            }
        }

        foreach ($hoists as $hoist) {
            preg_match('@^CREATE (\w+) (\w+)@', $hoist, $data);
            $operations[] = "DROP {$data[1]} IF EXISTS {$data[2]}";
            $operations[] = $hoist;
        }

        if (strlen(trim($sql))) {
            $operations[] = $sql;
        }

        // Rerun ifs and alters
        $operations = array_merge($operations, $alter, $ifs);
        echo "\033[100DPerforming operations for {$this->database}...\n";

        echo "\033[0m";

        /*
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
        echo "\033[0m\n\n";
        */
        var_dump($operations);
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
    private function hoist($regex, &$sql)
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
     * Check if a table exists on the given database.
     *
     * @param string $name The table name to check.
     * @return bool True or false.
     */
    private function tableExists($name)
    {
        static $stmt;
        if (!isset($stmt)) {
            $stmt = $this->pdo->prepare("SELECT * FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        }
        $stmt->execute([$this->database, $name]);
        if ($stmt->fetch()) {
            return true;
        } else {
            return false;
        }
    }

    private function getTableDefinition($name)
    {
        static $stmt;
        if (!isset($stmt)) {
            $stmt = $this->pdo->prepare("SELECT
                COLUMN_NAME name,
                COLUMN_DEFAULT def,
                IS_NULLABLE nullable,
                COLUMN_TYPE coltype,
                COLUMN_KEY colkey,
                EXTRA extra
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION ASC");
        }
        $stmt->execute([$this->database, $name]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $cols[$column['name']] = $column;
        }
        return $cols;
    }

    private function parseTableDefinition($schema)
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
                'def' => '',
                'nullable' => 'YES',
                'coltype' => '',
                'colkey' => '',
                'extra' => '',
            ];
            // Extract the name
            preg_match('@^\w+@', $line, $name);
            $column['name'] = $name[0];
            $line = str_replace($name[0], '', $line);
            if ($this->isNullable($line)) {
                $column['default'] = null;
            } else {
                $column['nullable'] = 'NO';
            }
            if ($this->isAutoIncrement($line)) {
                $column['extra'] = 'auto_increment';
            }
            if ($this->isPrimaryKey($line)) {
                $column['key'] = 'PRI';
            }
            $line = preg_replace('@REFERENCES.*?$@', '', $line);
            $column['coltype'] = strtolower(trim($line));
            $cols[$name[0]] = $column;
        }
        return $cols;
    }

    private function isNullable(&$column)
    {
        if (strpos($column, 'NOT NULL')) {
            $column = str_replace('NOT NULL', '', $column);
            return false;
        }
        return true;
    }

    private function isAutoIncrement(&$column)
    {
        // MySQL/SQLite
        if (preg_match('@AUTO_?INCREMENT@', $column)) {
            $column = preg_replace('@AUTO_?INCREMENT@', '', $column);
            return true;
        }
        // PostgreSQL
        if (preg_match('@SERIAL@', $column)) {
            return true;
        }
        return false;
    }
    
    private function isPrimaryKey(&$column)
    {
        if (preg_match('@(PRIMARY KEY|SERIAL)@', $column)) {
            $column = str_replace('PRIMARY KEY', '', $column);
            return true;
        }
        return false;
    }
}

