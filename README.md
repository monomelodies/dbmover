# dbMover

A node module that provides versioned database handling.

## Installation
```bash
npm install -g dbmover
cd /path/to/project
npm install --save dbmover-DRIVER
```

dbMover needs a "driver" for your database(s); see below.

## Usage
```bash
$ cd /path/to/project
$ dbmover databaseidentifier
```

## Configuration
dbMover reads the dbmover.json config file in the current path to determine
options. The config file is a key/value store where the key is the database
identifier, e.g. `"live"`, `"dev"` or `"test"`. The database to check is passed
as the argument to the script.

Each database config is itself an object with three keys: "driver", "connection"
and "schemas".

### `"driver"`
This should contain a string with the name of a valid dbMover driver module.
Currently only `"dbmover-mysql"` is supported. Support for `"dbmover-pgsql"`
and `"dbmover-sqlite"` is planned.

### `"connection"`
This should contain whatever the driver needs to connect to the database. E.g.,
for MySQL is could be a hash user user, password etc., for SQLite a string
pointing to the location of the database file.

### `"schemas"`
An array of schema files to use. These can be either relative to the current
location, or absolute (not recommended).

## Writing schema files
The schema files should contain just your regular definitions, as if you were
starting a new project. E.g.:

```sql
CREATE TABLE foo (
    bar INTEGER NOT NULL
);
```

dbMover is smart enough to distinguish between "necessary" and "superfluous"
calls in your schema, so no need to keep a "history" of changes - just write
your SQL as it _should_ be and dbMover will apply just the changes for you.

## Table changes
Should a table definition be rewritten, dbMover will detect the change and
apply it. In order to not lose any data, the following strategy is used:

1. Create a temporary, new table with a unique name and the new definition;
2. Insert all rows from the current table into the temporary table, using a
   simple one-on-one field mapping. I.e., if the field exists in both tables,
   it is copied; if it only exists in the old table, it is ignored; and if it
   only exists in the new table, it gets its default value;
3. Drop the original table;
4. Rename the temporary table to the original name.

## Changes to views, procedures etc.
Since these form a simple recreation, be sure to always precede them with
`DROP [THING] IF EXISTS` or comparable statements. dbMover will simply
recreate them, the performance penalty is negligible.

